<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Value\BaseOccurrence;
use InvalidArgumentException;
use RuntimeException;

/**
 * Calculates bounded base occurrences from Drupal-owned ActivitySeries state.
 */
final class OccurrenceProjectionService {

  /**
   * @return \Drupal\personal_secretary\Value\BaseOccurrence[]
   */
  public function project(
    ActivitySeries $series,
    ?DateTimeImmutable $windowStart = NULL,
    ?DateTimeImmutable $windowEnd = NULL,
    ?int $limit = NULL,
  ): array {
    if ($series->isNew()) {
      throw new InvalidArgumentException('Occurrence projection requires a persisted ActivitySeries.');
    }

    $revisionId = $series->getRevisionId();
    if ($revisionId === NULL || (string) $revisionId === '') {
      throw new InvalidArgumentException('Occurrence projection requires valid persisted ActivitySeries revision context.');
    }
    $revisionId = (string) $revisionId;

    $hasWindow = $windowStart !== NULL && $windowEnd !== NULL;
    if (($windowStart === NULL) !== ($windowEnd === NULL)) {
      throw new InvalidArgumentException('Occurrence windows require both a start and an end.');
    }
    if ($hasWindow && $windowEnd <= $windowStart) {
      throw new InvalidArgumentException('Occurrence window end must be after its start.');
    }
    if ($limit !== NULL && $limit <= 0) {
      throw new InvalidArgumentException('Occurrence limit must be a positive integer.');
    }
    if (!$hasWindow && $limit === NULL) {
      throw new InvalidArgumentException('Occurrence projection requires an explicit bounded window and/or limit.');
    }

    $item = $series->get('recurrence')->first();
    if ($item === NULL || $item->isEmpty()) {
      throw new RuntimeException('ActivitySeries has no recurrence value.');
    }

    $raw = $item->getValue();
    $timezoneName = (string) ($raw['timezone'] ?? '');
    if ($timezoneName === '') {
      throw new RuntimeException('ActivitySeries recurrence has no canonical source timezone.');
    }
    $sourceTimezone = new DateTimeZone($timezoneName);
    $helper = $item->getHelper();
    $occurrences = $helper->getOccurrences($windowStart, $windowEnd, $limit);
    $allDay = $series->timeMode() === ActivitySeries::TIME_MODE_ALL_DAY;
    $civilDaySpan = $allDay ? $series->allDayCivilDaySpan() : NULL;

    $utc = new DateTimeZone('UTC');
    $seriesUuid = $series->uuid();
    $projected = [];

    foreach ($occurrences as $occurrence) {
      // Date Recur remains the sole authority for every occurrence start.
      $start = DateTimeImmutable::createFromInterface($occurrence->getStart());
      $sourceLocalStart = $start->setTimezone($sourceTimezone);
      if ($allDay) {
        if ($sourceLocalStart->format('H:i:s') !== '00:00:00' || $civilDaySpan === NULL) {
          throw new RuntimeException('Generated ALL_DAY occurrence start must be a source-local midnight.');
        }
        // Preserve the canonical civil-day span across DST. This is calendar
        // arithmetic in the source timezone, never fixed elapsed-duration math.
        $sourceLocalEnd = $sourceLocalStart->modify(sprintf('+%d days', $civilDaySpan));
        $end = $sourceLocalEnd;
      }
      else {
        $end = DateTimeImmutable::createFromInterface($occurrence->getEnd());
        $sourceLocalEnd = $end->setTimezone($sourceTimezone);
      }

      $utcStart = $start->setTimezone($utc);
      $utcEnd = $end->setTimezone($utc);

      $projected[] = new BaseOccurrence(
        seriesUuid: $seriesUuid,
        seriesRevisionId: $revisionId,
        originalOccurrenceKey: $utcStart->format('Y-m-d\\TH:i:s\\Z'),
        utcStart: $utcStart->format(DateTimeInterface::ATOM),
        utcEnd: $utcEnd->format(DateTimeInterface::ATOM),
        sourceLocalStart: $sourceLocalStart->format(DateTimeInterface::ATOM),
        sourceLocalEnd: $sourceLocalEnd->format(DateTimeInterface::ATOM),
        sourceTimezone: $sourceTimezone->getName(),
      );
    }

    return $projected;
  }

}
