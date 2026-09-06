<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\personal_secretary\Entity\ActivityException;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Value\EffectiveOccurrence;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Applies a bounded recurring-activity pause as ordinary exact CANCEL rows.
 */
final class PauseRecurringActivityService {

  public const MAX_PAUSE_CANDIDATES = 128;

  private const UTC_STORAGE_FORMAT = 'Y-m-d\\TH:i:s';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly RevisionTimelineService $revisionTimeline,
    private readonly OccurrenceProjectionService $occurrenceProjection,
    private readonly EffectiveOccurrenceProjectionService $effectiveOccurrences,
    private readonly ActivityExceptionService $activityExceptions,
    private readonly CancelOccurrenceService $cancelOccurrence,
  ) {}

  public function canPause(int $seriesId): bool {
    return $this->isRecurring($this->loadSeries($seriesId));
  }

  /**
   * @return array{series: ActivitySeries, source_timezone: string}
   */
  public function context(int $seriesId): array {
    $series = $this->loadSeries($seriesId);
    if (!$this->isRecurring($series)) {
      throw new InvalidArgumentException('Pause is available only for recurring ActivitySeries.');
    }

    return [
      'series' => $series,
      'source_timezone' => $this->sourceTimezoneName($series),
    ];
  }

  /**
   * @return array{
   *   source_timezone: string,
   *   occurrences_to_cancel: int,
   *   already_cancelled: int,
   *   conflict: 'none'|'rescheduled',
   *   conflict_count: int
   * }
   */
  public function preview(int $seriesId, string $startDate, string $endDate): array {
    $context = $this->context($seriesId);
    $plan = $this->plan($context['series'], $startDate, $endDate);

    return [
      'source_timezone' => $plan['source_timezone'],
      'occurrences_to_cancel' => count($plan['targets']),
      'already_cancelled' => $plan['already_cancelled'],
      'conflict' => $plan['conflict_count'] > 0 ? 'rescheduled' : 'none',
      'conflict_count' => $plan['conflict_count'],
    ];
  }

  /**
   * @return array{cancelled_count: int, already_cancelled_count: int}
   */
  public function apply(int $seriesId, string $startDate, string $endDate): array {
    $transaction = $this->database->startTransaction();

    try {
      // Confirmation is authoritative: reload persisted series truth and rebuild
      // the bounded plan instead of trusting any preview candidate list.
      $context = $this->context($seriesId);
      $plan = $this->plan($context['series'], $startDate, $endDate);
      if ($plan['conflict_count'] > 0) {
        throw new InvalidArgumentException('Pause range contains an active rescheduled occurrence.');
      }

      $resolvedTargets = [];
      foreach ($plan['targets'] as $candidate) {
        $resolved = $this->cancelOccurrence->resolve($seriesId, $candidate->originalOccurrenceKey);
        if (
          (string) $resolved['occurrence']->seriesRevisionId !== (string) $candidate->seriesRevisionId
          || $resolved['occurrence']->originalOccurrenceKey !== $candidate->originalOccurrenceKey
        ) {
          throw new InvalidArgumentException('Pause target changed during confirmation.');
        }
        $resolvedTargets[] = $resolved;
      }

      foreach ($resolvedTargets as $resolved) {
        $this->cancelOccurrence->cancel(
          (int) $resolved['series']->id(),
          $resolved['occurrence']->originalOccurrenceKey,
        );
      }

      $transaction->commitOrRelease();
      return [
        'cancelled_count' => count($resolvedTargets),
        'already_cancelled_count' => $plan['already_cancelled'],
      ];
    }
    catch (Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  private function loadSeries(int $seriesId): ActivitySeries {
    if ($seriesId <= 0) {
      throw new InvalidArgumentException('Pause requires a positive ActivitySeries ID.');
    }
    $storage = $this->entityTypeManager->getStorage('personal_sec_activity_series');
    $storage->resetCache([$seriesId]);
    $series = $storage->load($seriesId);
    if (!$series instanceof ActivitySeries) {
      throw new InvalidArgumentException('Pause requires an existing ActivitySeries.');
    }
    return $series;
  }

  private function isRecurring(ActivitySeries $series): bool {
    return count($this->occurrenceProjection->project($series, NULL, NULL, 2)) > 1;
  }

  /**
   * @return array{
   *   source_timezone: string,
   *   targets: EffectiveOccurrence[],
   *   already_cancelled: int,
   *   conflict_count: int
   * }
   */
  private function plan(ActivitySeries $series, string $startDate, string $endDate): array {
    [$windowStart, $windowEnd, $timezone] = $this->range($series, $startDate, $endDate);
    $exceptions = $this->activityExceptions->activeForSeries($series);

    $this->assertCandidateBound(
      $series,
      $windowStart,
      $windowEnd,
      $exceptions,
      $timezone,
      $startDate,
      $endDate,
    );

    $targets = [];
    $conflictCount = 0;
    foreach ($this->effectiveOccurrences->project($series, $windowStart, $windowEnd) as $occurrence) {
      $effectiveDate = (new DateTimeImmutable($occurrence->effectiveSourceLocalStart))
        ->setTimezone($timezone)
        ->format('Y-m-d');
      if (!$this->dateInside($effectiveDate, $startDate, $endDate)) {
        throw new RuntimeException('Effective pause candidate escaped its source-local date range.');
      }

      if ($occurrence->exceptionAction === ActivityException::ACTION_RESCHEDULE) {
        $conflictCount++;
        continue;
      }
      if ($occurrence->exceptionUuid !== NULL || $occurrence->exceptionAction !== NULL) {
        throw new RuntimeException('Pause encountered an unsupported effective ActivityException overlay.');
      }
      $targets[] = $occurrence;
    }

    $alreadyCancelled = 0;
    foreach ($exceptions as $exception) {
      if ((string) $exception->get('action')->value !== ActivityException::ACTION_CANCEL) {
        continue;
      }
      $originalLocalStart = trim((string) $exception->get('original_source_local_start')->value);
      if ($originalLocalStart === '') {
        throw new RuntimeException('Active CANCEL ActivityException has no original local start.');
      }
      $cancelledDate = (new DateTimeImmutable($originalLocalStart))
        ->setTimezone($timezone)
        ->format('Y-m-d');
      if ($this->dateInside($cancelledDate, $startDate, $endDate)) {
        $alreadyCancelled++;
      }
    }

    return [
      'source_timezone' => $timezone->getName(),
      'targets' => $targets,
      'already_cancelled' => $alreadyCancelled,
      'conflict_count' => $conflictCount,
    ];
  }

  /**
   * @param ActivityException[] $exceptions
   */
  private function assertCandidateBound(
    ActivitySeries $series,
    DateTimeImmutable $windowStart,
    DateTimeImmutable $windowEnd,
    array $exceptions,
    DateTimeZone $timezone,
    string $startDate,
    string $endDate,
  ): void {
    $targetKeys = [];

    foreach ($this->revisionTimeline->timeline($series) as $interval) {
      $start = $windowStart > $interval['effective_from'] ? $windowStart : $interval['effective_from'];
      $end = $windowEnd;
      if ($interval['effective_until'] !== NULL && $interval['effective_until'] < $end) {
        $end = $interval['effective_until'];
      }
      if ($end <= $start) {
        continue;
      }

      $remaining = self::MAX_PAUSE_CANDIDATES + 1 - count($targetKeys);
      foreach ($this->occurrenceProjection->project($interval['revision'], $start, $end, $remaining) as $occurrence) {
        $occurrenceStart = (new DateTimeImmutable($occurrence->utcStart))->setTimezone(new DateTimeZone('UTC'));
        if (
          $occurrenceStart < $interval['effective_from']
          || ($interval['effective_until'] !== NULL && $occurrenceStart >= $interval['effective_until'])
        ) {
          continue;
        }
        $targetKeys[$this->targetKey($occurrence->seriesRevisionId, $occurrence->originalOccurrenceKey)] = TRUE;
        if (count($targetKeys) > self::MAX_PAUSE_CANDIDATES) {
          throw new InvalidArgumentException('Pause range exceeds the maximum of 128 bounded occurrence candidates.');
        }
      }
    }

    // A durable RESCHEDULE can move an original target from outside the base
    // date window into it. Count that exact target too without expanding any
    // recurrence outside the requested finite window.
    foreach ($exceptions as $exception) {
      if ((string) $exception->get('action')->value !== ActivityException::ACTION_RESCHEDULE) {
        continue;
      }
      $rescheduledStart = $this->fromStorage((string) $exception->get('rescheduled_utc_start')->value)
        ->setTimezone($timezone);
      $date = $rescheduledStart->format('Y-m-d');
      if (!$this->dateInside($date, $startDate, $endDate)) {
        continue;
      }

      $key = $this->targetKey(
        (string) $exception->get('target_revision_id')->value,
        (string) $exception->get('original_occurrence_key')->value,
      );
      $targetKeys[$key] = TRUE;
      if (count($targetKeys) > self::MAX_PAUSE_CANDIDATES) {
        throw new InvalidArgumentException('Pause range exceeds the maximum of 128 bounded occurrence candidates.');
      }
    }
  }

  /**
   * @return array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: DateTimeZone}
   */
  private function range(ActivitySeries $series, string $startDate, string $endDate): array {
    $timezone = new DateTimeZone($this->sourceTimezoneName($series));
    $start = $this->parseDate($startDate, $timezone);
    $end = $this->parseDate($endDate, $timezone);
    if ($end < $start) {
      throw new InvalidArgumentException('Pause end date must not be before its start date.');
    }

    return [
      $start->setTimezone(new DateTimeZone('UTC')),
      $end->modify('+1 day')->setTimezone(new DateTimeZone('UTC')),
      $timezone,
    ];
  }

  private function sourceTimezoneName(ActivitySeries $series): string {
    $item = $series->get('recurrence')->first();
    if ($item === NULL || $item->isEmpty()) {
      throw new RuntimeException('ActivitySeries has no recurrence source timezone.');
    }
    $raw = $item->getValue();
    $timezoneName = trim((string) ($raw['timezone'] ?? ''));
    if ($timezoneName === '') {
      throw new RuntimeException('ActivitySeries recurrence has no source timezone.');
    }
    new DateTimeZone($timezoneName);
    return $timezoneName;
  }

  private function parseDate(string $value, DateTimeZone $timezone): DateTimeImmutable {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
    if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
      throw new InvalidArgumentException('Pause dates must be valid Y-m-d civil dates.');
    }
    return $date;
  }

  private function dateInside(string $date, string $startDate, string $endDate): bool {
    return strcmp($date, $startDate) >= 0 && strcmp($date, $endDate) <= 0;
  }

  private function targetKey(string $revisionId, string $originalOccurrenceKey): string {
    return $revisionId . '|' . $originalOccurrenceKey;
  }

  private function fromStorage(string $value): DateTimeImmutable {
    $parsed = DateTimeImmutable::createFromFormat(
      '!' . self::UTC_STORAGE_FORMAT,
      $value,
      new DateTimeZone('UTC'),
    );
    if (!$parsed instanceof DateTimeImmutable || $parsed->format(self::UTC_STORAGE_FORMAT) !== $value) {
      throw new RuntimeException('Stored ActivityException UTC datetime is invalid.');
    }
    return $parsed;
  }

}
