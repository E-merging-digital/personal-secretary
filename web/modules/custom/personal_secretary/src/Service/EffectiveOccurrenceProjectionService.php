<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Drupal\personal_secretary\Entity\ActivityException;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Value\BaseOccurrence;
use Drupal\personal_secretary\Value\EffectiveOccurrence;
use InvalidArgumentException;
use RuntimeException;

/**
 * Applies active ActivityExceptions over the effective series revision timeline.
 */
final class EffectiveOccurrenceProjectionService {

  private const UTC_STORAGE_FORMAT = 'Y-m-d\TH:i:s';

  public function __construct(
    private readonly RevisionTimelineService $revisionTimeline,
    private readonly ActivityExceptionService $activityExceptions,
  ) {}

  /**
   * @return \Drupal\personal_secretary\Value\EffectiveOccurrence[]
   */
  public function project(
    ActivitySeries $series,
    DateTimeImmutable $windowStart,
    DateTimeImmutable $windowEnd,
    ?int $limit = NULL,
  ): array {
    $windowStart = $this->utc($windowStart);
    $windowEnd = $this->utc($windowEnd);
    if ($windowEnd <= $windowStart) {
      throw new InvalidArgumentException('Effective occurrence projection requires a complete window with end after start.');
    }
    if ($limit !== NULL && $limit <= 0) {
      throw new InvalidArgumentException('Effective occurrence final limit must be positive.');
    }

    $exceptions = $this->activityExceptions->activeForSeries($series);
    $byTarget = [];
    foreach ($exceptions as $exception) {
      $byTarget[$this->targetKeyFromException($exception)] = $exception;
    }

    $effective = [];
    $matchedExceptionIds = [];

    foreach ($this->revisionTimeline->projectBaseWindow($series, $windowStart, $windowEnd) as $base) {
      $key = $this->targetKeyFromBase($base);
      $exception = $byTarget[$key] ?? NULL;
      if ($exception === NULL) {
        $effective[] = $this->fromBase($base);
        continue;
      }

      $matchedExceptionIds[(int) $exception->id()] = TRUE;
      $action = (string) $exception->get('action')->value;
      if ($action === ActivityException::ACTION_CANCEL) {
        continue;
      }
      if ($action !== ActivityException::ACTION_RESCHEDULE) {
        throw new RuntimeException('Unknown active ActivityException action.');
      }

      $rescheduled = $this->fromReschedule($exception);
      if ($this->startsInsideWindow($rescheduled->effectiveUtcStart, $windowStart, $windowEnd)) {
        $effective[] = $rescheduled;
      }
    }

    // Reschedules can move an original target from outside the requested
    // recurrence window into it. Those durable exception rows are domain truth
    // and can be overlaid without expanding recurrence outside the window.
    foreach ($exceptions as $exception) {
      if (
        isset($matchedExceptionIds[(int) $exception->id()])
        || (string) $exception->get('action')->value !== ActivityException::ACTION_RESCHEDULE
      ) {
        continue;
      }
      $rescheduled = $this->fromReschedule($exception);
      if ($this->startsInsideWindow($rescheduled->effectiveUtcStart, $windowStart, $windowEnd)) {
        $effective[] = $rescheduled;
      }
    }

    usort(
      $effective,
      static fn(EffectiveOccurrence $left, EffectiveOccurrence $right): int =>
        [
          $left->effectiveUtcStart,
          $left->seriesRevisionId,
          $left->originalOccurrenceKey,
          $left->exceptionUuid ?? '',
        ]
        <=>
        [
          $right->effectiveUtcStart,
          $right->seriesRevisionId,
          $right->originalOccurrenceKey,
          $right->exceptionUuid ?? '',
        ],
    );

    return $limit === NULL ? $effective : array_slice($effective, 0, $limit);
  }

  private function fromBase(BaseOccurrence $base): EffectiveOccurrence {
    return new EffectiveOccurrence(
      seriesUuid: $base->seriesUuid,
      seriesRevisionId: $base->seriesRevisionId,
      originalOccurrenceKey: $base->originalOccurrenceKey,
      originalUtcStart: $base->utcStart,
      originalUtcEnd: $base->utcEnd,
      originalSourceLocalStart: $base->sourceLocalStart,
      originalSourceLocalEnd: $base->sourceLocalEnd,
      sourceTimezone: $base->sourceTimezone,
      effectiveUtcStart: $base->utcStart,
      effectiveUtcEnd: $base->utcEnd,
      effectiveSourceLocalStart: $base->sourceLocalStart,
      effectiveSourceLocalEnd: $base->sourceLocalEnd,
    );
  }

  private function fromReschedule(ActivityException $exception): EffectiveOccurrence {
    $startValue = (string) $exception->get('rescheduled_utc_start')->value;
    $endValue = (string) $exception->get('rescheduled_utc_end')->value;
    if ($startValue === '' || $endValue === '') {
      throw new RuntimeException('Active reschedule ActivityException has no effective UTC range.');
    }

    $start = $this->fromStorage($startValue);
    $end = $this->fromStorage($endValue);
    $timezoneName = (string) $exception->get('source_timezone')->value;
    $timezone = new DateTimeZone($timezoneName);

    $series = $exception->get('series')->entity;
    if (!$series instanceof ActivitySeries) {
      throw new RuntimeException('ActivityException references no persisted ActivitySeries.');
    }

    return new EffectiveOccurrence(
      seriesUuid: $series->uuid(),
      seriesRevisionId: (string) $exception->get('target_revision_id')->value,
      originalOccurrenceKey: (string) $exception->get('original_occurrence_key')->value,
      originalUtcStart: $this->atomFromStorage((string) $exception->get('original_utc_start')->value),
      originalUtcEnd: $this->atomFromStorage((string) $exception->get('original_utc_end')->value),
      originalSourceLocalStart: (string) $exception->get('original_source_local_start')->value,
      originalSourceLocalEnd: (string) $exception->get('original_source_local_end')->value,
      sourceTimezone: $timezoneName,
      effectiveUtcStart: $start->format(DateTimeInterface::ATOM),
      effectiveUtcEnd: $end->format(DateTimeInterface::ATOM),
      effectiveSourceLocalStart: $start->setTimezone($timezone)->format(DateTimeInterface::ATOM),
      effectiveSourceLocalEnd: $end->setTimezone($timezone)->format(DateTimeInterface::ATOM),
      exceptionUuid: $exception->uuid(),
      exceptionRevisionId: (string) $exception->getRevisionId(),
      exceptionAction: ActivityException::ACTION_RESCHEDULE,
    );
  }

  private function targetKeyFromBase(BaseOccurrence $base): string {
    return $base->seriesRevisionId . '|' . $base->originalOccurrenceKey;
  }

  private function targetKeyFromException(ActivityException $exception): string {
    return (string) $exception->get('target_revision_id')->value
      . '|'
      . (string) $exception->get('original_occurrence_key')->value;
  }

  private function startsInsideWindow(
    string $utcStart,
    DateTimeImmutable $windowStart,
    DateTimeImmutable $windowEnd,
  ): bool {
    $start = $this->utc(new DateTimeImmutable($utcStart));
    return $start >= $windowStart && $start < $windowEnd;
  }

  private function atomFromStorage(string $value): string {
    return $this->fromStorage($value)->format(DateTimeInterface::ATOM);
  }

  private function fromStorage(string $value): DateTimeImmutable {
    $parsed = DateTimeImmutable::createFromFormat(
      '!' . self::UTC_STORAGE_FORMAT,
      $value,
      new DateTimeZone('UTC'),
    );
    if (!$parsed instanceof DateTimeImmutable) {
      throw new RuntimeException('Stored ActivityException UTC datetime is invalid.');
    }
    return $parsed;
  }

  private function utc(DateTimeImmutable $value): DateTimeImmutable {
    return $value->setTimezone(new DateTimeZone('UTC'));
  }

}
