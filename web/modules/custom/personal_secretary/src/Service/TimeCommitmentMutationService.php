<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\TimeCommitmentRule;
use InvalidArgumentException;
use RuntimeException;

/**
 * Governed mutation boundary for TimeCommitmentRule lifecycle.
 */
final class TimeCommitmentMutationService {

  private const UTC_STORAGE_FORMAT = 'Y-m-d\\TH:i:s';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {}

  public function createFullOccurrenceCommitment(
    ActivitySeries $series,
    DateTimeImmutable $effectiveFrom,
  ): TimeCommitmentRule {
    $effectiveFrom = $this->assertFullOccurrenceCreationAllowed($series, $effectiveFrom);

    /** @var \Drupal\personal_secretary\Entity\TimeCommitmentRule $rule */
    $rule = $this->ruleStorage()->create([
      'series' => $series->id(),
      'mode' => TimeCommitmentRule::MODE_FULL_OCCURRENCE,
      'effective_from' => $this->toStorage($effectiveFrom),
      'lifecycle_persisted_at' => $this->time->getCurrentTime(),
    ]);
    $rule->save();
    return $rule;
  }

  public function retireCommitment(
    TimeCommitmentRule $rule,
    DateTimeImmutable $effectiveUntil,
  ): TimeCommitmentRule {
    $effectiveUntil = $this->assertRetirementAllowed($rule, $effectiveUntil);

    $rule->setNewRevision(TRUE);
    $rule->set('effective_until', $this->toStorage($effectiveUntil));
    $rule->set('lifecycle_persisted_at', $this->time->getCurrentTime());
    $rule->save();
    return $rule;
  }

  public function assertFullOccurrenceCreationAllowed(
    ActivitySeries $series,
    DateTimeImmutable $effectiveFrom,
  ): DateTimeImmutable {
    $series = $this->requirePersistedSeries($series);
    $effectiveFrom = $this->utc($effectiveFrom);
    $this->assertFuture($effectiveFrom);

    /** @var \Drupal\personal_secretary\Entity\TimeCommitmentRule[] $rules */
    $rules = $this->ruleStorage()->loadByProperties(['series' => $series->id()]);
    foreach ($rules as $rule) {
      if (!$rule instanceof TimeCommitmentRule) {
        throw new RuntimeException('TimeCommitmentRule storage returned an unexpected entity type.');
      }
      $this->assertSupportedRule($rule);
      $start = $this->fromStorage((string) $rule->get('effective_from')->value);
      $end = $rule->get('effective_until')->isEmpty()
        ? NULL
        : $this->fromStorage((string) $rule->get('effective_until')->value);
      if ($end !== NULL && $end < $start) {
        throw new RuntimeException('TimeCommitmentRule effective interval is invalid.');
      }

      // A new FULL_OCCURRENCE interval is open-ended. Any current rule whose
      // interval extends beyond its start would overlap it.
      if ($end === NULL || $end > $effectiveFrom) {
        throw new InvalidArgumentException('A TimeCommitmentRule would overlap this FULL_OCCURRENCE transition.');
      }
    }

    return $effectiveFrom;
  }

  public function assertRetirementAllowed(
    TimeCommitmentRule $rule,
    DateTimeImmutable $effectiveUntil,
  ): DateTimeImmutable {
    $this->assertCurrentRule($rule);
    $this->assertSupportedRule($rule);
    if (!$rule->get('effective_until')->isEmpty()) {
      throw new InvalidArgumentException('A retired TimeCommitmentRule cannot be retired again.');
    }

    $series = $rule->get('series')->entity;
    if (!$series instanceof ActivitySeries) {
      throw new RuntimeException('TimeCommitmentRule references no persisted ActivitySeries.');
    }
    $this->requirePersistedSeries($series);

    $effectiveUntil = $this->utc($effectiveUntil);
    $this->assertFuture($effectiveUntil);
    $start = $this->fromStorage((string) $rule->get('effective_from')->value);
    if ($effectiveUntil < $start) {
      throw new InvalidArgumentException('TimeCommitmentRule effective-until cannot precede effective-from.');
    }

    return $effectiveUntil;
  }

  private function assertCurrentRule(TimeCommitmentRule $rule): void {
    if ($rule->isNew() || $rule->id() === NULL) {
      throw new InvalidArgumentException('TimeCommitmentRule lifecycle mutations require a persisted rule.');
    }
    $latestRevisionId = $this->ruleStorage()->getLatestRevisionId($rule->id());
    if ($latestRevisionId === NULL || (string) $latestRevisionId !== (string) $rule->getRevisionId()) {
      throw new InvalidArgumentException('TimeCommitmentRule lifecycle mutations require the current revision.');
    }
  }

  private function assertSupportedRule(TimeCommitmentRule $rule): void {
    if ((string) $rule->get('mode')->value !== TimeCommitmentRule::MODE_FULL_OCCURRENCE) {
      throw new RuntimeException('TimeCommitmentRule contains an unsupported mode.');
    }
  }

  private function requirePersistedSeries(ActivitySeries $series): ActivitySeries {
    if ($series->isNew() || $series->id() === NULL) {
      throw new InvalidArgumentException('TimeCommitmentRule requires a persisted ActivitySeries.');
    }
    $persisted = $this->entityTypeManager
      ->getStorage('personal_sec_activity_series')
      ->load($series->id());
    if (!$persisted instanceof ActivitySeries || $persisted->uuid() !== $series->uuid()) {
      throw new InvalidArgumentException('TimeCommitmentRule requires the current persisted ActivitySeries identity.');
    }
    return $persisted;
  }

  private function assertFuture(DateTimeImmutable $value): void {
    if ($value->getTimestamp() <= $this->time->getCurrentTime()) {
      throw new InvalidArgumentException('Time commitment transitions must be in the future.');
    }
  }

  private function ruleStorage(): RevisionableStorageInterface {
    $storage = $this->entityTypeManager->getStorage(TimeCommitmentRule::ENTITY_TYPE_ID);
    if (!$storage instanceof RevisionableStorageInterface) {
      throw new RuntimeException('TimeCommitmentRule storage must support revisions.');
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
    if (!$parsed instanceof DateTimeImmutable || $parsed->format(self::UTC_STORAGE_FORMAT) !== $value) {
      throw new RuntimeException('Stored TimeCommitmentRule UTC datetime is invalid.');
    }
    return $parsed;
  }

  private function utc(DateTimeImmutable $value): DateTimeImmutable {
    return $value->setTimezone(new DateTimeZone('UTC'));
  }

}
