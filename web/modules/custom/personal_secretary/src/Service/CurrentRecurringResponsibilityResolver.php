<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\Person;
use Drupal\personal_secretary\Entity\ResponsibilityRule;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Resolves the current simple product-managed recurring responsibility.
 */
final class CurrentRecurringResponsibilityResolver {

  public const WEEKLY_RRULE = 'FREQ=WEEKLY;INTERVAL=1';

  private const UTC_STORAGE_FORMAT = 'Y-m-d\\TH:i:s';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ResponsibilityContextValidator $responsibilityContext,
    private readonly OccurrenceProjectionService $occurrenceProjection,
  ) {}

  /**
   * @return array{
   *   series: \Drupal\personal_secretary\Entity\ActivitySeries,
   *   rule: \Drupal\personal_secretary\Entity\ResponsibilityRule,
   *   responsible_person: \Drupal\personal_secretary\Entity\Person,
   *   source_timezone: string,
   *   current_local_start: \DateTimeImmutable,
   *   current_local_end: \DateTimeImmutable,
   *   current_rule_local_start: \DateTimeImmutable,
   *   current_rule_local_end: \DateTimeImmutable,
   *   current_rule_utc_start: \DateTimeImmutable
   * }
   */
  public function resolve(int $seriesId): array {
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
      throw new InvalidArgumentException('Recurring responsibility requires the latest ActivitySeries revision.');
    }

    $seriesRecurrence = $this->recurrenceValue($series, 'ActivitySeries');
    if (trim($seriesRecurrence['rrule']) !== self::WEEKLY_RRULE) {
      throw new InvalidArgumentException('Only the current simple weekly ActivitySeries recurrence is supported.');
    }

    try {
      $sourceTimezone = new DateTimeZone($seriesRecurrence['timezone']);
    }
    catch (Throwable $exception) {
      throw new RuntimeException('ActivitySeries source timezone is invalid.', previous: $exception);
    }

    $currentLocalStart = $this->fromStorage($seriesRecurrence['value'])->setTimezone($sourceTimezone);
    $currentLocalEnd = $this->fromStorage($seriesRecurrence['end_value'])->setTimezone($sourceTimezone);
    if ($currentLocalEnd <= $currentLocalStart) {
      throw new RuntimeException('ActivitySeries recurrence window is invalid.');
    }

    $rule = $this->currentRule($series);
    $ruleRecurrence = $this->recurrenceValue($rule, 'ResponsibilityRule');
    $this->assertAlignedRule($series, $seriesRecurrence, $ruleRecurrence);

    $ruleUtcStart = $this->fromStorage($ruleRecurrence['value']);
    $ruleUtcEnd = $this->fromStorage($ruleRecurrence['end_value']);
    $ruleLocalStart = $ruleUtcStart->setTimezone($sourceTimezone);
    $ruleLocalEnd = $ruleUtcEnd->setTimezone($sourceTimezone);

    $responsiblePersonId = (int) $rule->get('responsible_person')->target_id;
    $responsiblePerson = $this->responsibilityContext->requireMember($series, $responsiblePersonId);
    if (!$responsiblePerson instanceof Person) {
      throw new RuntimeException('ResponsibilityRule references no current Person.');
    }

    return [
      'series' => $series,
      'rule' => $rule,
      'responsible_person' => $responsiblePerson,
      'source_timezone' => $sourceTimezone->getName(),
      'current_local_start' => $currentLocalStart,
      'current_local_end' => $currentLocalEnd,
      'current_rule_local_start' => $ruleLocalStart,
      'current_rule_local_end' => $ruleLocalEnd,
      'current_rule_utc_start' => $ruleUtcStart,
    ];
  }

  private function currentRule(ActivitySeries $series): ResponsibilityRule {
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

    return $rule;
  }

  /**
   * @param array{value:string,end_value:string,rrule:string,timezone:string} $seriesRecurrence
   * @param array{value:string,end_value:string,rrule:string,timezone:string} $ruleRecurrence
   */
  private function assertAlignedRule(
    ActivitySeries $series,
    array $seriesRecurrence,
    array $ruleRecurrence,
  ): void {
    if (
      trim($ruleRecurrence['rrule']) !== self::WEEKLY_RRULE
      || $ruleRecurrence['rrule'] !== $seriesRecurrence['rrule']
      || $ruleRecurrence['timezone'] !== $seriesRecurrence['timezone']
    ) {
      throw new InvalidArgumentException('Current ResponsibilityRule is not aligned with the supported weekly ActivitySeries recurrence.');
    }

    $seriesStart = $this->fromStorage($seriesRecurrence['value']);
    $seriesEnd = $this->fromStorage($seriesRecurrence['end_value']);
    $ruleStart = $this->fromStorage($ruleRecurrence['value']);
    $ruleEnd = $this->fromStorage($ruleRecurrence['end_value']);
    $seriesDuration = $seriesEnd->getTimestamp() - $seriesStart->getTimestamp();
    $ruleDuration = $ruleEnd->getTimestamp() - $ruleStart->getTimestamp();
    if ($seriesDuration <= 0 || $ruleDuration !== $seriesDuration) {
      throw new InvalidArgumentException('Current ResponsibilityRule window is not aligned with ActivitySeries duration.');
    }

    $matches = [];
    foreach ($this->occurrenceProjection->project(
      $series,
      $ruleStart->modify('-1 second'),
      $ruleStart->modify('+1 second'),
    ) as $occurrence) {
      if (
        (new DateTimeImmutable($occurrence->utcStart))->getTimestamp() === $ruleStart->getTimestamp()
        && (new DateTimeImmutable($occurrence->utcEnd))->getTimestamp() === $ruleEnd->getTimestamp()
        && $occurrence->sourceTimezone === $ruleRecurrence['timezone']
      ) {
        $matches[] = $occurrence;
      }
    }

    if (count($matches) !== 1) {
      throw new InvalidArgumentException('Current ResponsibilityRule must start on exactly one real ActivitySeries occurrence.');
    }
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
      'rrule' => trim((string) $raw['rrule']),
      'timezone' => (string) $raw['timezone'],
    ];
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
