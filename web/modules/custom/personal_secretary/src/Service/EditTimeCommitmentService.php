<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\TimeCommitmentRule;
use Drupal\personal_secretary\Value\EffectiveOccurrence;
use InvalidArgumentException;
use RuntimeException;

/**
 * Coordinates one future series-level time-commitment transition.
 */
final class EditTimeCommitmentService {

  public const MODE_NONE = 'none';
  public const MODE_FULL_OCCURRENCE = TimeCommitmentRule::MODE_FULL_OCCURRENCE;

  private const LOOKAHEAD_DAYS = 370;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly EffectiveOccurrenceProjectionService $effectiveOccurrences,
    private readonly TimeCommitmentResolver $timeCommitment,
    private readonly TimeCommitmentMutationService $timeCommitmentMutations,
  ) {}

  /**
   * @return array{
   *   series: \Drupal\personal_secretary\Entity\ActivitySeries,
   *   source_timezone: string,
   *   default_effective_date: string,
   *   default_mode: string
   * }
   */
  public function context(int $seriesId): array {
    $series = $this->loadSeries($seriesId);
    $timezone = new DateTimeZone($this->sourceTimezone($series));
    $todayLocal = (new DateTimeImmutable('@' . $this->time->getCurrentTime()))
      ->setTimezone($timezone)
      ->setTime(0, 0);
    $defaultBoundary = $todayLocal->modify('+1 day');
    $defaultOccurrence = $this->firstEffectiveOccurrence($series, $defaultBoundary);
    $currentRule = $this->timeCommitment->resolve($series, $defaultOccurrence);

    return [
      'series' => $series,
      'source_timezone' => $timezone->getName(),
      'default_effective_date' => $defaultBoundary->format('Y-m-d'),
      'default_mode' => $currentRule === NULL
        ? self::MODE_NONE
        : self::MODE_FULL_OCCURRENCE,
    ];
  }

  /**
   * @return array{
   *   series: \Drupal\personal_secretary\Entity\ActivitySeries,
   *   source_timezone: string,
   *   requested_local_boundary: \DateTimeImmutable,
   *   transition_occurrence: \Drupal\personal_secretary\Value\EffectiveOccurrence,
   *   transition_utc: \DateTimeImmutable,
   *   requested_mode: string,
   *   current_mode: string,
   *   current_rule: ?\Drupal\personal_secretary\Entity\TimeCommitmentRule,
   *   noop: bool
   * }
   */
  public function prepare(
    int $seriesId,
    string $effectiveDate,
    string $mode,
  ): array {
    if (!in_array($mode, [self::MODE_NONE, self::MODE_FULL_OCCURRENCE], TRUE)) {
      throw new InvalidArgumentException('Unknown time commitment mode.');
    }

    $series = $this->loadSeries($seriesId);
    $timezone = new DateTimeZone($this->sourceTimezone($series));
    $requestedLocalBoundary = DateTimeImmutable::createFromFormat(
      '!Y-m-d H:i',
      $effectiveDate . ' 00:00',
      $timezone,
    );
    if (
      !$requestedLocalBoundary instanceof DateTimeImmutable
      || $requestedLocalBoundary->format('Y-m-d') !== $effectiveDate
    ) {
      throw new InvalidArgumentException('Effective-from date is invalid.');
    }

    $todayLocal = (new DateTimeImmutable('@' . $this->time->getCurrentTime()))
      ->setTimezone($timezone)
      ->setTime(0, 0);
    if ($requestedLocalBoundary <= $todayLocal) {
      throw new InvalidArgumentException('Effective-from date must be in the future.');
    }

    $occurrence = $this->firstEffectiveOccurrence($series, $requestedLocalBoundary);
    $transitionUtc = (new DateTimeImmutable($occurrence->effectiveUtcStart))
      ->setTimezone(new DateTimeZone('UTC'));
    $currentRule = $this->timeCommitment->resolve($series, $occurrence);
    $currentMode = $currentRule === NULL
      ? self::MODE_NONE
      : self::MODE_FULL_OCCURRENCE;
    $noop = $currentMode === $mode;

    if (!$noop && $mode === self::MODE_FULL_OCCURRENCE) {
      $this->timeCommitmentMutations->assertFullOccurrenceCreationAllowed($series, $transitionUtc);
    }
    elseif (!$noop && $currentRule !== NULL) {
      $this->timeCommitmentMutations->assertRetirementAllowed($currentRule, $transitionUtc);
    }

    return [
      'series' => $series,
      'source_timezone' => $timezone->getName(),
      'requested_local_boundary' => $requestedLocalBoundary,
      'transition_occurrence' => $occurrence,
      'transition_utc' => $transitionUtc,
      'requested_mode' => $mode,
      'current_mode' => $currentMode,
      'current_rule' => $currentRule,
      'noop' => $noop,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function apply(
    int $seriesId,
    string $effectiveDate,
    string $mode,
  ): array {
    $plan = $this->prepare($seriesId, $effectiveDate, $mode);
    if ($plan['noop']) {
      return $plan + ['created_rule' => NULL, 'retired_rule' => NULL];
    }

    if ($mode === self::MODE_FULL_OCCURRENCE) {
      $created = $this->timeCommitmentMutations->createFullOccurrenceCommitment(
        $plan['series'],
        $plan['transition_utc'],
      );
      return $plan + ['created_rule' => $created, 'retired_rule' => NULL];
    }

    $currentRule = $plan['current_rule'];
    if (!$currentRule instanceof TimeCommitmentRule) {
      throw new RuntimeException('FULL_OCCURRENCE to NONE transition has no current TimeCommitmentRule.');
    }
    $retired = $this->timeCommitmentMutations->retireCommitment(
      $currentRule,
      $plan['transition_utc'],
    );
    return $plan + ['created_rule' => NULL, 'retired_rule' => $retired];
  }

  private function loadSeries(int $seriesId): ActivitySeries {
    if ($seriesId <= 0) {
      throw new InvalidArgumentException('ActivitySeries ID must be positive.');
    }
    $series = $this->entityTypeManager
      ->getStorage('personal_sec_activity_series')
      ->load($seriesId);
    if (!$series instanceof ActivitySeries) {
      throw new InvalidArgumentException('Requested ActivitySeries does not exist.');
    }
    return $series;
  }

  private function sourceTimezone(ActivitySeries $series): string {
    $item = $series->get('recurrence')->first();
    if ($item === NULL || $item->isEmpty()) {
      throw new RuntimeException('ActivitySeries has no recurrence value.');
    }
    $raw = $item->getValue();
    $timezone = (string) ($raw['timezone'] ?? '');
    if ($timezone === '') {
      throw new RuntimeException('ActivitySeries recurrence has no canonical source timezone.');
    }
    return $timezone;
  }

  private function firstEffectiveOccurrence(
    ActivitySeries $series,
    DateTimeImmutable $localBoundary,
  ): EffectiveOccurrence {
    $utc = new DateTimeZone('UTC');
    $windowStart = $localBoundary->setTimezone($utc);
    $windowEnd = $localBoundary
      ->modify('+' . self::LOOKAHEAD_DAYS . ' days')
      ->setTimezone($utc);
    $occurrences = $this->effectiveOccurrences->project(
      $series,
      $windowStart,
      $windowEnd,
      1,
    );
    if ($occurrences === []) {
      throw new InvalidArgumentException('Unable to resolve a future effective occurrence from the requested date.');
    }
    return $occurrences[0];
  }

}
