<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Coordinates one future weekly schedule correction for an ActivitySeries.
 */
final class EditRecurringScheduleService {

  private const WEEKLY_RRULE = CurrentRecurringResponsibilityResolver::WEEKLY_RRULE;
  private const UTC_STORAGE_FORMAT = 'Y-m-d\\TH:i:s';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly DomainMutationService $domainMutations,
    private readonly ResponsibilityMutationService $responsibilityMutations,
    private readonly CurrentRecurringResponsibilityResolver $currentResponsibility,
  ) {}

  /**
   * @return array{
   *   series: \Drupal\personal_secretary\Entity\ActivitySeries,
   *   rule: \Drupal\personal_secretary\Entity\ResponsibilityRule,
   *   responsible_person: \Drupal\personal_secretary\Entity\Person,
   *   source_timezone: string,
   *   current_local_start: \DateTimeImmutable,
   *   current_local_end: \DateTimeImmutable,
   *   latest_effective_from: \DateTimeImmutable,
   *   default_effective_date: string
   * }
   */
  public function context(int $seriesId): array {
    $resolved = $this->currentResponsibility->resolve($seriesId);
    $sourceTimezone = new DateTimeZone($resolved['source_timezone']);
    $latestEffectiveFrom = $this->fromStorage((string) $resolved['series']->get('effective_from')->value);

    $todayLocal = (new DateTimeImmutable('@' . $this->time->getCurrentTime()))
      ->setTimezone($sourceTimezone)
      ->setTime(0, 0);
    $defaultBoundary = $todayLocal->modify('+1 day');
    while ($defaultBoundary->setTimezone(new DateTimeZone('UTC')) <= $latestEffectiveFrom) {
      $defaultBoundary = $defaultBoundary->modify('+1 day');
    }

    return $resolved + [
      'latest_effective_from' => $latestEffectiveFrom,
      'default_effective_date' => $defaultBoundary->format('Y-m-d'),
    ];
  }

  /**
   * @return array{
   *   series: \Drupal\personal_secretary\Entity\ActivitySeries,
   *   rule: \Drupal\personal_secretary\Entity\ResponsibilityRule,
   *   responsible_person: \Drupal\personal_secretary\Entity\Person,
   *   source_timezone: string,
   *   current_local_start: \DateTimeImmutable,
   *   current_local_end: \DateTimeImmutable,
   *   latest_effective_from: \DateTimeImmutable,
   *   default_effective_date: string,
   *   effective_from_utc: \DateTimeImmutable,
   *   new_local_start: \DateTimeImmutable,
   *   new_local_end: \DateTimeImmutable
   * }
   */
  public function prepare(
    int $seriesId,
    string $effectiveDate,
    string $newLocalStartTime,
    string $newLocalEndTime,
  ): array {
    $context = $this->context($seriesId);
    $timezone = new DateTimeZone($context['source_timezone']);

    $effectiveLocalMidnight = DateTimeImmutable::createFromFormat(
      '!Y-m-d H:i',
      $effectiveDate . ' 00:00',
      $timezone,
    );
    if (
      !$effectiveLocalMidnight instanceof DateTimeImmutable
      || $effectiveLocalMidnight->format('Y-m-d') !== $effectiveDate
    ) {
      throw new InvalidArgumentException('Effective-from date is invalid.');
    }

    $todayLocal = (new DateTimeImmutable('@' . $this->time->getCurrentTime()))
      ->setTimezone($timezone)
      ->setTime(0, 0);
    if ($effectiveLocalMidnight <= $todayLocal) {
      throw new InvalidArgumentException('Effective-from date must be in the future.');
    }

    $effectiveFromUtc = $effectiveLocalMidnight->setTimezone(new DateTimeZone('UTC'));
    if ($effectiveFromUtc <= $context['latest_effective_from']) {
      throw new InvalidArgumentException('Effective-from boundary must be after the latest ActivitySeries boundary.');
    }

    $newLocalStart = $this->parseLocalDateTime($effectiveDate, $newLocalStartTime, $timezone);
    $newLocalEnd = $this->parseLocalDateTime($effectiveDate, $newLocalEndTime, $timezone);
    if (!$newLocalStart instanceof DateTimeImmutable || !$newLocalEnd instanceof DateTimeImmutable) {
      throw new InvalidArgumentException('New local schedule time is invalid.');
    }
    if ($newLocalEnd <= $newLocalStart) {
      throw new InvalidArgumentException('New local end must be after new local start.');
    }

    return $context + [
      'effective_from_utc' => $effectiveFromUtc,
      'new_local_start' => $newLocalStart,
      'new_local_end' => $newLocalEnd,
    ];
  }

  /**
   * @return array{
   *   series: \Drupal\personal_secretary\Entity\ActivitySeries,
   *   retired_rule: \Drupal\personal_secretary\Entity\ResponsibilityRule,
   *   replacement_rule: \Drupal\personal_secretary\Entity\ResponsibilityRule
   * }
   */
  public function apply(
    int $seriesId,
    string $effectiveDate,
    string $newLocalStartTime,
    string $newLocalEndTime,
  ): array {
    $plan = $this->prepare(
      $seriesId,
      $effectiveDate,
      $newLocalStartTime,
      $newLocalEndTime,
    );

    $transaction = $this->database->startTransaction();
    try {
      $updatedSeries = $this->domainMutations->updateActivitySeriesRecurrence(
        $plan['series'],
        $plan['new_local_start'],
        $plan['new_local_end'],
        self::WEEKLY_RRULE,
        $plan['effective_from_utc'],
      );
      $retiredRule = $this->responsibilityMutations->retireResponsibilityRule(
        $plan['rule'],
        $plan['effective_from_utc'],
      );
      $replacementRule = $this->responsibilityMutations->createResponsibilityRule(
        $updatedSeries,
        (int) $plan['responsible_person']->id(),
        $plan['new_local_start'],
        $plan['new_local_end'],
        self::WEEKLY_RRULE,
      );

      $transaction->commitOrRelease();
      return [
        'series' => $updatedSeries,
        'retired_rule' => $retiredRule,
        'replacement_rule' => $replacementRule,
      ];
    }
    catch (Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  private function parseLocalDateTime(
    string $date,
    string $time,
    DateTimeZone $timezone,
  ): ?DateTimeImmutable {
    $value = DateTimeImmutable::createFromFormat(
      '!Y-m-d H:i',
      $date . ' ' . $time,
      $timezone,
    );
    if (!$value instanceof DateTimeImmutable) {
      return NULL;
    }
    return $value->format('Y-m-d H:i') === $date . ' ' . $time ? $value : NULL;
  }

  private function fromStorage(string $value): DateTimeImmutable {
    $parsed = DateTimeImmutable::createFromFormat(
      '!' . self::UTC_STORAGE_FORMAT,
      $value,
      new DateTimeZone('UTC'),
    );
    if (!$parsed instanceof DateTimeImmutable || $parsed->format(self::UTC_STORAGE_FORMAT) !== $value) {
      throw new RuntimeException('Stored UTC datetime is invalid.');
    }
    return $parsed;
  }

}
