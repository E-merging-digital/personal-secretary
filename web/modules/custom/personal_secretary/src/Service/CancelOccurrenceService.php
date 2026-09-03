<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\personal_secretary\Entity\ActivityException;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Value\BaseOccurrence;
use InvalidArgumentException;

/**
 * Resolves and cancels one exact currently effective base occurrence.
 */
final class CancelOccurrenceService {

  private const OCCURRENCE_KEY_FORMAT = 'Y-m-d\\TH:i:s\\Z';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RevisionTimelineService $revisionTimeline,
    private readonly OccurrenceProjectionService $occurrenceProjection,
    private readonly EffectiveOccurrenceProjectionService $effectiveOccurrences,
    private readonly ActivityExceptionService $activityExceptions,
  ) {}

  /**
   * @return array{series: \Drupal\personal_secretary\Entity\ActivitySeries, occurrence: \Drupal\personal_secretary\Value\BaseOccurrence}
   */
  public function resolve(int $seriesId, string $originalOccurrenceKey): array {
    if ($seriesId <= 0) {
      throw new InvalidArgumentException('Cancellation requires a valid ActivitySeries identity.');
    }

    $series = $this->entityTypeManager
      ->getStorage('personal_sec_activity_series')
      ->load($seriesId);
    if (!$series instanceof ActivitySeries) {
      throw new InvalidArgumentException('Cancellation ActivitySeries does not exist.');
    }

    $instant = $this->parseOccurrenceKey($originalOccurrenceKey);
    $windowStart = $instant->modify('-1 second');
    $windowEnd = $instant->modify('+1 second');

    try {
      $revision = $this->revisionTimeline->effectiveRevisionFor($series, $instant);
    }
    catch (InvalidArgumentException) {
      throw new InvalidArgumentException('Cancellation target is not governed by an effective ActivitySeries revision.');
    }

    $matches = array_values(array_filter(
      $this->occurrenceProjection->project($revision, $windowStart, $windowEnd),
      static fn(BaseOccurrence $candidate): bool =>
        $candidate->originalOccurrenceKey === $originalOccurrenceKey,
    ));
    if (count($matches) !== 1) {
      throw new InvalidArgumentException('Cancellation target did not resolve to exactly one audited BaseOccurrence.');
    }

    $target = $matches[0];
    if (!$this->revisionTimeline->isEffectiveTarget($series, $target)) {
      throw new InvalidArgumentException('Cancellation target is no longer an effective audited BaseOccurrence.');
    }

    $effectiveMatches = array_values(array_filter(
      $this->effectiveOccurrences->project($series, $windowStart, $windowEnd),
      static fn($candidate): bool =>
        $candidate->seriesRevisionId === $target->seriesRevisionId
        && $candidate->originalOccurrenceKey === $target->originalOccurrenceKey,
    ));
    if (count($effectiveMatches) !== 1 || $effectiveMatches[0]->exceptionUuid !== NULL) {
      throw new InvalidArgumentException('Cancellation target is no longer an unmodified effective base occurrence.');
    }

    return [
      'series' => $series,
      'occurrence' => $target,
    ];
  }

  public function cancel(int $seriesId, string $originalOccurrenceKey): ActivityException {
    $resolved = $this->resolve($seriesId, $originalOccurrenceKey);

    return $this->activityExceptions->createCancel(
      $resolved['series'],
      $resolved['occurrence'],
    );
  }

  private function parseOccurrenceKey(string $originalOccurrenceKey): DateTimeImmutable {
    $parsed = DateTimeImmutable::createFromFormat(
      '!' . self::OCCURRENCE_KEY_FORMAT,
      $originalOccurrenceKey,
      new DateTimeZone('UTC'),
    );
    if (
      !$parsed instanceof DateTimeImmutable
      || $parsed->format(self::OCCURRENCE_KEY_FORMAT) !== $originalOccurrenceKey
    ) {
      throw new InvalidArgumentException('Cancellation original occurrence key is malformed.');
    }

    return $parsed;
  }

}
