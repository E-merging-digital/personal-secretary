<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\Person;
use Drupal\personal_secretary\Entity\ResponsibilityRule;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Coordinates one future weekly schedule correction for an ActivitySeries.
 */
final class EditRecurringScheduleService {

  private const WEEKLY_RRULE = 'FREQ=WEEKLY;INTERVAL=1';
  private const UTC_STORAGE_FORMAT = 'Y-m-d\\TH:i:s';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly DomainMutationService $domainMutations,
    private readonly ResponsibilityMutationService $responsibilityMutations,
    private readonly ResponsibilityContextValidator $responsibilityContext,
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
    if ($seriesId <= 0) {
      throw new InvalidArgumentException('A valid ActivitySeries identity is required.');
    }

    $seriesStorage = $this->seriesStorage();
    $series = $seriesStorage->load($seriesId);
    if (!$series instanceof ActivitySeries) {
      throw new InvalidArgumentException('ActivitySeries does not exist.');
    }
    $latestSeriesRevisionId = $seriesStorage->getLatestRevisionId($seriesId);
    if (
      $latestSeriesRevisionId === NULL
      || (string) $latestSeriesRevisionId !== (string) $series->getRevisionId()
    ) {
      throw new InvalidArgumentException('Recurring schedule edits require the latest ActivitySeries revision.');
    }

    $seriesRecurrence = $this->recurrenceValue($series, 'ActivitySeries');
    if (trim((string) $seriesRecurrence['rrule']) !== self::WEEKLY_RRULE) {
      throw new InvalidArgumentException('Only the current simple weekly ActivitySeries schedule can be edited.');
    }

    try {
      $sourceTimezone = new DateTimeZone((string) $seriesRecurrence['timezone']);
    }
    catch (Throwable $exception) {
      throw new RuntimeException('ActivitySeries source timezone is invalid.', previous: $exception);
    }

    $currentLocalStart = $this->fromStorage((string) $seriesRecurrence['value'])
      ->setTimezone($sourceTimezone);
    $currentLocalEnd = $this->fromStorage((string) $seriesRecurrence['end_value'])
      ->setTimezone($sourceTimezone);
    if ($currentLocalEnd <= $currentLocalStart) {
      throw new RuntimeException('ActivitySeries recurrence window is invalid.');
    }

    $latestEffectiveFrom = $this->fromStorage((string) $series->get('effective_from')->value);
    $rule = $this->currentProductManagedRule($series, $seriesRecurrence);
    $responsiblePersonId = (int) $rule->get('responsible_person')->target_id;
    $this->responsibilityContext->requireMember($series, $responsiblePersonId);
    $responsiblePerson = $this->entityTypeManager
      ->getStorage('personal_secretary_person')
      ->load($responsiblePersonId);
    if (!$responsiblePerson instanceof Person) {
      throw new RuntimeException('ResponsibilityRule references no current Person.');
    }

    $todayLocal = (new DateTimeImmutable('@' . $this->time->getCurrentTime()))
      ->setTimezone($sourceTimezone)
      ->setTime(0, 0);
    $defaultBoundary = $todayLocal->modify('+1 day');
    while ($defaultBoundary->setTimezone(new DateTimeZone('UTC')) <= $latestEffectiveFrom) {
      $defaultBoundary = $defaultBoundary->modify('+1 day');
    }

    return [
      'series' => $series,
      'rule' => $rule,
      'responsible_person' => $responsiblePerson,
      'source_timezone' => $sourceTimezone->getName(),
      'current_local_start' => $currentLocalStart,
      'current_local_end' => $currentLocalEnd,
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

  /**
   * @param array<string, mixed> $seriesRecurrence
   */
  private function currentProductManagedRule(
    ActivitySeries $series,
    array $seriesRecurrence,
  ): ResponsibilityRule {
    $candidates = [];
    foreach ($this->ruleStorage()->loadByProperties(['series' => $series->id()]) as $rule) {
      if (!$rule instanceof ResponsibilityRule) {
        throw new RuntimeException('ResponsibilityRule storage returned an unexpected entity type.');
      }
      if ($rule->get('effective_until')->isEmpty()) {
        $candidates[] = $rule;
      }
    }
    if (count($candidates) !== 1) {
      throw new InvalidArgumentException('ActivitySeries must have exactly one current non-retired ResponsibilityRule.');
    }

    $rule = $candidates[0];
    $latestRuleRevisionId = $this->ruleStorage()->getLatestRevisionId($rule->id());
    if (
      $latestRuleRevisionId === NULL
      || (string) $latestRuleRevisionId !== (string) $rule->getRevisionId()
    ) {
      throw new InvalidArgumentException('ResponsibilityRule must be at its latest persisted revision.');
    }

    $ruleRecurrence = $this->recurrenceValue($rule, 'ResponsibilityRule');
    foreach (['value', 'end_value', 'rrule', 'timezone'] as $key) {
      if ((string) $ruleRecurrence[$key] !== (string) $seriesRecurrence[$key]) {
        throw new InvalidArgumentException('Current ResponsibilityRule is not the simple product-managed weekly rule aligned with ActivitySeries.');
      }
    }
    if (trim((string) $ruleRecurrence['rrule']) !== self::WEEKLY_RRULE) {
      throw new InvalidArgumentException('Current ResponsibilityRule is not the supported weekly product rule.');
    }

    return $rule;
  }

  /**
   * @return array{value:string,end_value:string,rrule:string,timezone:string}
   */
  private function recurrenceValue(object $entity, string $label): array {
    /** @var \Drupal\Core\Field\FieldItemListInterface $field */
    $field = $entity->get('recurrence');
    $item = $field->first();
    if ($item === NULL || $item->isEmpty()) {
      throw new RuntimeException($label . ' has no recurrence value.');
    }
    $raw = $item->getValue();
    foreach (['value', 'end_value', 'rrule', 'timezone'] as $key) {
      if (!isset($raw[$key]) || trim((string) $raw[$key]) === '') {
        throw new RuntimeException($label . ' recurrence value is incomplete.');
      }
    }
    return [
      'value' => (string) $raw['value'],
      'end_value' => (string) $raw['end_value'],
      'rrule' => (string) $raw['rrule'],
      'timezone' => (string) $raw['timezone'],
    ];
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

  private function seriesStorage(): RevisionableStorageInterface {
    $storage = $this->entityTypeManager->getStorage('personal_sec_activity_series');
    if (!$storage instanceof RevisionableStorageInterface) {
      throw new RuntimeException('ActivitySeries storage must support revisions.');
    }
    return $storage;
  }

  private function ruleStorage(): RevisionableStorageInterface {
    $storage = $this->entityTypeManager->getStorage('personal_sec_resp_rule');
    if (!$storage instanceof RevisionableStorageInterface) {
      throw new RuntimeException('ResponsibilityRule storage must support revisions.');
    }
    return $storage;
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
