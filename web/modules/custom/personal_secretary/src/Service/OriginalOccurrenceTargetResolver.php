<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Value\BaseOccurrence;
use InvalidArgumentException;

/**
 * Resolves one exact current audited original occurrence identity.
 */
final class OriginalOccurrenceTargetResolver {

  private const OCCURRENCE_KEY_FORMAT = 'Y-m-d\\TH:i:s\\Z';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RevisionTimelineService $revisionTimeline,
    private readonly OccurrenceProjectionService $occurrenceProjection,
  ) {}

  /**
   * @return array{series: \Drupal\personal_secretary\Entity\ActivitySeries, occurrence: \Drupal\personal_secretary\Value\BaseOccurrence}
   */
  public function resolve(int $seriesId, string $originalOccurrenceKey): array {
    if ($seriesId <= 0) {
      throw new InvalidArgumentException('Occurrence targeting requires a valid ActivitySeries identity.');
    }

    $series = $this->entityTypeManager
      ->getStorage('personal_sec_activity_series')
      ->load($seriesId);
    if (!$series instanceof ActivitySeries) {
      throw new InvalidArgumentException('Occurrence target ActivitySeries does not exist.');
    }

    $instant = $this->parseOccurrenceKey($originalOccurrenceKey);
    $windowStart = $instant->modify('-1 second');
    $windowEnd = $instant->modify('+1 second');

    try {
      $revision = $this->revisionTimeline->effectiveRevisionFor($series, $instant);
    }
    catch (InvalidArgumentException) {
      throw new InvalidArgumentException('Occurrence target is not governed by an effective ActivitySeries revision.');
    }

    $matches = array_values(array_filter(
      $this->occurrenceProjection->project($revision, $windowStart, $windowEnd),
      static fn(BaseOccurrence $candidate): bool =>
        $candidate->originalOccurrenceKey === $originalOccurrenceKey,
    ));
    if (count($matches) !== 1) {
      throw new InvalidArgumentException('Occurrence target did not resolve to exactly one audited BaseOccurrence.');
    }

    $target = $matches[0];
    if (!$this->revisionTimeline->isEffectiveTarget($series, $target)) {
      throw new InvalidArgumentException('Occurrence target is no longer an effective audited BaseOccurrence.');
    }

    return [
      'series' => $series,
      'occurrence' => $target,
    ];
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
      throw new InvalidArgumentException('Original occurrence key is malformed.');
    }

    return $parsed;
  }

}
