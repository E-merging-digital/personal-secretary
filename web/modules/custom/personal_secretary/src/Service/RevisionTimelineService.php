<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Value\BaseOccurrence;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves persisted ActivitySeries revisions into append-only effective intervals.
 */
final class RevisionTimelineService {

  private const UTC_STORAGE_FORMAT = 'Y-m-d\TH:i:s';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly OccurrenceProjectionService $occurrenceProjection,
  ) {}

  /**
   * @return array<int, array{
   *   revision: \Drupal\personal_secretary\Entity\ActivitySeries,
   *   revision_id: string,
   *   effective_from: \DateTimeImmutable,
   *   effective_until: ?\DateTimeImmutable
   * }>
   */
  public function timeline(ActivitySeries $series): array {
    if ($series->isNew() || $series->id() === NULL) {
      throw new InvalidArgumentException('Effective revision timeline requires a persisted ActivitySeries.');
    }

    $storage = $this->entityTypeManager->getStorage('personal_sec_activity_series');
    if (!$storage instanceof RevisionableStorageInterface) {
      throw new RuntimeException('ActivitySeries storage must support revisions.');
    }

    $revisionIds = array_keys(
      $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('id', $series->id())
        ->allRevisions()
        ->sort('revision_id', 'ASC')
        ->execute(),
    );
    if ($revisionIds === []) {
      throw new RuntimeException('Persisted ActivitySeries has no revision timeline.');
    }

    $revisions = [];
    $previousBoundary = NULL;
    foreach ($revisionIds as $revisionId) {
      $revision = $storage->loadRevision($revisionId);
      if (!$revision instanceof ActivitySeries) {
        throw new RuntimeException('ActivitySeries revision could not be loaded.');
      }
      $boundary = $this->effectiveFrom($revision);
      if ($previousBoundary !== NULL && $boundary <= $previousBoundary) {
        throw new RuntimeException('ActivitySeries effective-from timeline is not strictly increasing.');
      }
      $revisions[] = [
        'revision' => $revision,
        'revision_id' => (string) $revision->getRevisionId(),
        'effective_from' => $boundary,
        'effective_until' => NULL,
      ];
      $previousBoundary = $boundary;
    }

    $count = count($revisions);
    for ($index = 0; $index < $count - 1; $index++) {
      $revisions[$index]['effective_until'] = $revisions[$index + 1]['effective_from'];
    }

    return $revisions;
  }

  public function effectiveRevisionFor(ActivitySeries $series, DateTimeImmutable $instant): ActivitySeries {
    $instant = $this->utc($instant);
    foreach ($this->timeline($series) as $interval) {
      if (
        $instant >= $interval['effective_from']
        && ($interval['effective_until'] === NULL || $instant < $interval['effective_until'])
      ) {
        return $interval['revision'];
      }
    }

    throw new InvalidArgumentException('No ActivitySeries revision governs the requested instant.');
  }

  /**
   * Projects base occurrences only while each persisted revision is effective.
   *
   * @return \Drupal\personal_secretary\Value\BaseOccurrence[]
   */
  public function projectBaseWindow(
    ActivitySeries $series,
    DateTimeImmutable $windowStart,
    DateTimeImmutable $windowEnd,
  ): array {
    $windowStart = $this->utc($windowStart);
    $windowEnd = $this->utc($windowEnd);
    if ($windowEnd <= $windowStart) {
      throw new InvalidArgumentException('Effective projection requires a complete window with end after start.');
    }

    $projected = [];
    foreach ($this->timeline($series) as $interval) {
      $start = $windowStart > $interval['effective_from'] ? $windowStart : $interval['effective_from'];
      $end = $windowEnd;
      if ($interval['effective_until'] !== NULL && $interval['effective_until'] < $end) {
        $end = $interval['effective_until'];
      }
      if ($end <= $start) {
        continue;
      }

      foreach ($this->occurrenceProjection->project($interval['revision'], $start, $end) as $occurrence) {
        $occurrenceStart = $this->utc(new DateTimeImmutable($occurrence->utcStart));
        if (
          $occurrenceStart < $interval['effective_from']
          || ($interval['effective_until'] !== NULL && $occurrenceStart >= $interval['effective_until'])
        ) {
          continue;
        }
        if ($occurrenceStart >= $windowStart && $occurrenceStart < $windowEnd) {
          $projected[] = $occurrence;
        }
      }
    }

    usort(
      $projected,
      static fn(BaseOccurrence $left, BaseOccurrence $right): int =>
        [$left->utcStart, $left->seriesRevisionId, $left->originalOccurrenceKey]
        <=>
        [$right->utcStart, $right->seriesRevisionId, $right->originalOccurrenceKey],
    );

    return $projected;
  }

  public function isEffectiveTarget(ActivitySeries $series, BaseOccurrence $target): bool {
    if ($series->isNew() || $series->uuid() !== $target->seriesUuid) {
      return FALSE;
    }

    $targetStart = $this->utc(new DateTimeImmutable($target->utcStart));
    try {
      $effectiveRevision = $this->effectiveRevisionFor($series, $targetStart);
    }
    catch (InvalidArgumentException) {
      return FALSE;
    }

    if ((string) $effectiveRevision->getRevisionId() !== $target->seriesRevisionId) {
      return FALSE;
    }

    $targetEnd = $this->utc(new DateTimeImmutable($target->utcEnd));
    $probeStart = $targetStart->modify('-1 second');
    $probeEnd = $targetEnd->modify('+1 second');
    foreach ($this->occurrenceProjection->project($effectiveRevision, $probeStart, $probeEnd) as $candidate) {
      if (
        $candidate->seriesUuid === $target->seriesUuid
        && $candidate->seriesRevisionId === $target->seriesRevisionId
        && $candidate->originalOccurrenceKey === $target->originalOccurrenceKey
        && $candidate->utcStart === $target->utcStart
        && $candidate->utcEnd === $target->utcEnd
        && $candidate->sourceLocalStart === $target->sourceLocalStart
        && $candidate->sourceLocalEnd === $target->sourceLocalEnd
        && $candidate->sourceTimezone === $target->sourceTimezone
      ) {
        return TRUE;
      }
    }

    return FALSE;
  }

  public function effectiveFrom(ActivitySeries $revision): DateTimeImmutable {
    $value = (string) $revision->get('effective_from')->value;
    if ($value === '') {
      throw new RuntimeException('ActivitySeries revision has no effective-from boundary.');
    }
    $parsed = DateTimeImmutable::createFromFormat(
      '!' . self::UTC_STORAGE_FORMAT,
      $value,
      new DateTimeZone('UTC'),
    );
    if (!$parsed instanceof DateTimeImmutable) {
      throw new RuntimeException('ActivitySeries effective-from boundary is invalid.');
    }
    return $parsed;
  }

  private function utc(DateTimeImmutable $value): DateTimeImmutable {
    return $value->setTimezone(new DateTimeZone('UTC'));
  }

}
