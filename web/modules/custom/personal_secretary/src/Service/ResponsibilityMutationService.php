<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\date_recur\DateRecurHelper;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\ResponsibilityOverride;
use Drupal\personal_secretary\Entity\ResponsibilityRule;
use Drupal\personal_secretary\Value\EffectiveOccurrence;
use InvalidArgumentException;
use RuntimeException;

/**
 * Governed mutation boundary for responsibility rules and overrides.
 */
final class ResponsibilityMutationService {

  private const UTC_STORAGE_FORMAT = 'Y-m-d\\TH:i:s';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ResponsibilityContextValidator $contextValidator,
    private readonly TimeInterface $time,
  ) {}

  public function createResponsibilityRule(
    ActivitySeries $series,
    int $responsiblePersonId,
    DateTimeImmutable $localStart,
    DateTimeImmutable $localEnd,
    string $rrule,
  ): ResponsibilityRule {
    $this->contextValidator->requireMember($series, $responsiblePersonId);
    $recurrence = $this->recurrenceValue($localStart, $localEnd, $rrule);

    /** @var \Drupal\personal_secretary\Entity\ResponsibilityRule $rule */
    $rule = $this->ruleStorage()->create([
      'series' => $series->id(),
      'responsible_person' => $responsiblePersonId,
      'recurrence' => [$recurrence],
      'lifecycle_persisted_at' => $this->time->getCurrentTime(),
    ]);
    $rule->save();
    return $rule;
  }

  public function retireResponsibilityRule(
    ResponsibilityRule $rule,
    DateTimeImmutable $effectiveUntil,
  ): ResponsibilityRule {
    $this->assertCurrentRule($rule);
    if (!$rule->get('effective_until')->isEmpty()) {
      throw new InvalidArgumentException('A retired ResponsibilityRule cannot be retired again.');
    }

    $series = $rule->get('series')->entity;
    if (!$series instanceof ActivitySeries) {
      throw new RuntimeException('ResponsibilityRule references no persisted ActivitySeries.');
    }
    $this->contextValidator->requireMember($series, (int) $rule->get('responsible_person')->target_id);

    $item = $rule->get('recurrence')->first();
    if ($item === NULL || $item->isEmpty()) {
      throw new RuntimeException('ResponsibilityRule has no recurrence value.');
    }
    $raw = $item->getValue();
    $dtstart = $this->fromStorage((string) ($raw['value'] ?? ''));
    $effectiveUntil = $this->utc($effectiveUntil);
    if ($effectiveUntil <= $dtstart) {
      throw new InvalidArgumentException('ResponsibilityRule effective-until must be after recurrence DTSTART.');
    }

    $rule->setNewRevision(TRUE);
    $rule->set('effective_until', $this->toStorage($effectiveUntil));
    $rule->set('lifecycle_persisted_at', $this->time->getCurrentTime());
    $rule->save();
    return $rule;
  }

  public function createAssignOverride(
    ActivitySeries $series,
    EffectiveOccurrence $target,
    int $responsiblePersonId,
  ): ResponsibilityOverride {
    $this->contextValidator->assertCurrentOccurrence($series, $target);
    $this->contextValidator->requireMember($series, $responsiblePersonId);
    $this->assertNoDuplicateActiveOverride($series, $target);

    return $this->createOverride(
      $series,
      $target,
      ResponsibilityOverride::ACTION_ASSIGN_PERSON,
      $responsiblePersonId,
    );
  }

  public function createClearOverride(
    ActivitySeries $series,
    EffectiveOccurrence $target,
  ): ResponsibilityOverride {
    $this->contextValidator->assertCurrentOccurrence($series, $target);
    $this->assertNoDuplicateActiveOverride($series, $target);

    return $this->createOverride(
      $series,
      $target,
      ResponsibilityOverride::ACTION_CLEAR_RESPONSIBILITY,
      NULL,
    );
  }

  public function supersedeOverride(
    ResponsibilityOverride $override,
    ActivitySeries $series,
    EffectiveOccurrence $target,
    string $action,
    ?int $responsiblePersonId = NULL,
  ): ResponsibilityOverride {
    $this->assertCurrentOverride($override);
    if ((string) $override->get('status')->value !== ResponsibilityOverride::STATUS_ACTIVE) {
      throw new InvalidArgumentException('Only an active ResponsibilityOverride can be superseded.');
    }
    if ((string) $override->get('series')->target_id !== (string) $series->id()) {
      throw new InvalidArgumentException('ResponsibilityOverride cannot be superseded across ActivitySeries.');
    }

    $this->contextValidator->assertCurrentOccurrence($series, $target);
    if (!$this->contextValidator->sameImmutableIdentity(
      $series,
      (string) $override->get('target_revision_id')->value,
      (string) $override->get('original_occurrence_key')->value,
      $target,
    )) {
      throw new InvalidArgumentException('ResponsibilityOverride target identity is immutable across revisions.');
    }
    $this->contextValidator->assertStoredOriginalContext(
      (string) $override->get('original_utc_start')->value,
      (string) $override->get('original_utc_end')->value,
      (string) $override->get('original_source_local_start')->value,
      (string) $override->get('original_source_local_end')->value,
      (string) $override->get('source_timezone')->value,
      $target,
    );
    $this->assertNoDuplicateActiveOverride($series, $target, (int) $override->id());
    $this->validateOverrideAction($series, $action, $responsiblePersonId);

    $override->setNewRevision(TRUE);
    foreach ($this->effectiveSnapshot($target) as $field => $value) {
      $override->set($field, $value);
    }
    $override->set('action', $action);
    $override->set('responsible_person', $responsiblePersonId);
    $override->set('status', ResponsibilityOverride::STATUS_ACTIVE);
    $override->set('lifecycle_persisted_at', $this->time->getCurrentTime());
    $override->save();
    return $override;
  }

  public function withdrawOverride(ResponsibilityOverride $override): ResponsibilityOverride {
    $this->assertCurrentOverride($override);
    if ((string) $override->get('status')->value !== ResponsibilityOverride::STATUS_ACTIVE) {
      throw new InvalidArgumentException('Only an active ResponsibilityOverride can be withdrawn.');
    }

    $override->setNewRevision(TRUE);
    $override->set('status', ResponsibilityOverride::STATUS_WITHDRAWN);
    $override->set('lifecycle_persisted_at', $this->time->getCurrentTime());
    $override->save();
    return $override;
  }

  private function createOverride(
    ActivitySeries $series,
    EffectiveOccurrence $target,
    string $action,
    ?int $responsiblePersonId,
  ): ResponsibilityOverride {
    $this->validateOverrideAction($series, $action, $responsiblePersonId);
    $values = $this->immutableTargetSnapshot($target)
      + $this->effectiveSnapshot($target)
      + [
        'series' => $series->id(),
        'action' => $action,
        'responsible_person' => $responsiblePersonId,
        'status' => ResponsibilityOverride::STATUS_ACTIVE,
        'lifecycle_persisted_at' => $this->time->getCurrentTime(),
      ];

    /** @var \Drupal\personal_secretary\Entity\ResponsibilityOverride $override */
    $override = $this->overrideStorage()->create($values);
    $override->save();
    return $override;
  }

  private function validateOverrideAction(
    ActivitySeries $series,
    string $action,
    ?int $responsiblePersonId,
  ): void {
    if ($action === ResponsibilityOverride::ACTION_ASSIGN_PERSON) {
      if ($responsiblePersonId === NULL) {
        throw new InvalidArgumentException('ASSIGN_PERSON ResponsibilityOverride requires a responsible Person.');
      }
      $this->contextValidator->requireMember($series, $responsiblePersonId);
      return;
    }
    if ($action === ResponsibilityOverride::ACTION_CLEAR_RESPONSIBILITY) {
      if ($responsiblePersonId !== NULL) {
        throw new InvalidArgumentException('CLEAR_RESPONSIBILITY ResponsibilityOverride cannot reference a responsible Person.');
      }
      return;
    }
    throw new InvalidArgumentException('Unknown ResponsibilityOverride action.');
  }

  /**
   * @return array<string, int|string>
   */
  private function immutableTargetSnapshot(EffectiveOccurrence $target): array {
    return [
      'target_revision_id' => (int) $target->seriesRevisionId,
      'original_occurrence_key' => $target->originalOccurrenceKey,
      'original_utc_start' => $this->toStorage(new DateTimeImmutable($target->originalUtcStart)),
      'original_utc_end' => $this->toStorage(new DateTimeImmutable($target->originalUtcEnd)),
      'original_source_local_start' => $target->originalSourceLocalStart,
      'original_source_local_end' => $target->originalSourceLocalEnd,
      'source_timezone' => $target->sourceTimezone,
    ];
  }

  /**
   * @return array<string, int|string|null>
   */
  private function effectiveSnapshot(EffectiveOccurrence $target): array {
    return [
      'effective_utc_start' => $this->toStorage(new DateTimeImmutable($target->effectiveUtcStart)),
      'effective_utc_end' => $this->toStorage(new DateTimeImmutable($target->effectiveUtcEnd)),
      'effective_source_local_start' => $target->effectiveSourceLocalStart,
      'effective_source_local_end' => $target->effectiveSourceLocalEnd,
      'activity_exception_uuid' => $target->exceptionUuid,
      'activity_exception_revision_id' => $target->exceptionRevisionId === NULL ? NULL : (int) $target->exceptionRevisionId,
    ];
  }

  private function assertNoDuplicateActiveOverride(
    ActivitySeries $series,
    EffectiveOccurrence $target,
    ?int $excludeOverrideId = NULL,
  ): void {
    $ids = $this->overrideStorage()
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('series', $series->id())
      ->condition('target_revision_id', (int) $target->seriesRevisionId)
      ->condition('original_occurrence_key', $target->originalOccurrenceKey)
      ->condition('status', ResponsibilityOverride::STATUS_ACTIVE)
      ->execute();

    foreach ($ids as $id) {
      if ($excludeOverrideId === NULL || (int) $id !== $excludeOverrideId) {
        throw new InvalidArgumentException('An active ResponsibilityOverride already targets this occurrence.');
      }
    }
  }

  private function assertCurrentRule(ResponsibilityRule $rule): void {
    if ($rule->isNew() || $rule->id() === NULL) {
      throw new InvalidArgumentException('ResponsibilityRule lifecycle mutations require a persisted rule.');
    }
    $latestRevisionId = $this->ruleStorage()->getLatestRevisionId($rule->id());
    if ($latestRevisionId === NULL || (string) $latestRevisionId !== (string) $rule->getRevisionId()) {
      throw new InvalidArgumentException('ResponsibilityRule lifecycle mutations require the current revision.');
    }
  }

  private function assertCurrentOverride(ResponsibilityOverride $override): void {
    if ($override->isNew() || $override->id() === NULL) {
      throw new InvalidArgumentException('ResponsibilityOverride lifecycle mutations require a persisted override.');
    }
    $latestRevisionId = $this->overrideStorage()->getLatestRevisionId($override->id());
    if ($latestRevisionId === NULL || (string) $latestRevisionId !== (string) $override->getRevisionId()) {
      throw new InvalidArgumentException('ResponsibilityOverride lifecycle mutations require the current revision.');
    }
  }

  /**
   * @return array{value:string,end_value:string,rrule:string,timezone:string}
   */
  private function recurrenceValue(
    DateTimeImmutable $localStart,
    DateTimeImmutable $localEnd,
    string $rrule,
  ): array {
    $rrule = trim($rrule);
    if ($rrule === '') {
      throw new InvalidArgumentException('ResponsibilityRule RRULE must not be empty.');
    }
    if ($localEnd <= $localStart) {
      throw new InvalidArgumentException('ResponsibilityRule window end must be after start.');
    }

    $timezone = $localStart->getTimezone()->getName();
    if ($timezone === '' || $localEnd->getTimezone()->getName() !== $timezone) {
      throw new InvalidArgumentException('ResponsibilityRule window start/end must use the same explicit source timezone.');
    }

    DateRecurHelper::create($rrule, $localStart, $localEnd);
    return [
      'value' => $this->toStorage($localStart),
      'end_value' => $this->toStorage($localEnd),
      'rrule' => $rrule,
      'timezone' => $timezone,
    ];
  }

  private function ruleStorage(): RevisionableStorageInterface {
    $storage = $this->entityTypeManager->getStorage('personal_sec_resp_rule');
    if (!$storage instanceof RevisionableStorageInterface) {
      throw new RuntimeException('ResponsibilityRule storage must support revisions.');
    }
    return $storage;
  }

  private function overrideStorage(): RevisionableStorageInterface {
    $storage = $this->entityTypeManager->getStorage('personal_sec_resp_override');
    if (!$storage instanceof RevisionableStorageInterface) {
      throw new RuntimeException('ResponsibilityOverride storage must support revisions.');
    }
    return $storage;
  }

  private function toStorage(DateTimeImmutable $value): string {
    return $this->utc($value)->format(self::UTC_STORAGE_FORMAT);
  }

  private function fromStorage(string $value): DateTimeImmutable {
    $parsed = DateTimeImmutable::createFromFormat(
      '!' . self::UTC_STORAGE_FORMAT,
      $value,
      new DateTimeZone('UTC'),
    );
    if (!$parsed instanceof DateTimeImmutable) {
      throw new RuntimeException('Stored responsibility UTC datetime is invalid.');
    }
    return $parsed;
  }

  private function utc(DateTimeImmutable $value): DateTimeImmutable {
    return $value->setTimezone(new DateTimeZone('UTC'));
  }

}
