<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\date_recur\DateRecurHelper;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\Household;
use Drupal\personal_secretary\Entity\Person;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Governed mutation boundary for Personal Secretary domain persistence.
 */
final class DomainMutationService {

  private const UTC_STORAGE_FORMAT = 'Y-m-d\TH:i:s';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly ActivityExceptionService $activityExceptions,
  ) {}

  public function createPerson(string $name): Person {
    $name = $this->requiredLabel($name, 'Person name');
    /** @var \Drupal\personal_secretary\Entity\Person $person */
    $person = $this->entityTypeManager->getStorage('personal_secretary_person')->create([
      'name' => $name,
    ]);
    $person->save();
    return $person;
  }

  /**
   * @param int[] $memberIds
   *   Existing Person entity IDs.
   */
  public function createHousehold(string $name, array $memberIds): Household {
    $name = $this->requiredLabel($name, 'Household name');
    $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
    if ($memberIds === []) {
      throw new InvalidArgumentException('A household must contain at least one Person.');
    }

    $people = $this->entityTypeManager
      ->getStorage('personal_secretary_person')
      ->loadMultiple($memberIds);
    if (count($people) !== count($memberIds)) {
      throw new InvalidArgumentException('Every household member must reference an existing Person.');
    }

    /** @var \Drupal\personal_secretary\Entity\Household $household */
    $household = $this->entityTypeManager->getStorage('personal_secretary_household')->create([
      'name' => $name,
      'members' => array_map(static fn(int $id): array => ['target_id' => $id], $memberIds),
    ]);
    $household->save();
    return $household;
  }

  public function addHouseholdMember(Household $household, Person $person): Household {
    if ($household->isNew() || $household->id() === NULL) {
      throw new InvalidArgumentException('Household must be persisted before a member can be added.');
    }
    if ($person->isNew() || $person->id() === NULL) {
      throw new InvalidArgumentException('Person must be persisted before Household membership can be added.');
    }

    $householdStorage = $this->entityTypeManager->getStorage('personal_secretary_household');
    $personStorage = $this->entityTypeManager->getStorage('personal_secretary_person');

    $persistedHousehold = $householdStorage->load($household->id());
    if (!$persistedHousehold instanceof Household) {
      throw new InvalidArgumentException('Household membership requires an existing Household.');
    }
    $persistedPerson = $personStorage->load($person->id());
    if (!$persistedPerson instanceof Person) {
      throw new InvalidArgumentException('Household membership requires an existing Person.');
    }

    $personId = (int) $persistedPerson->id();
    $memberIds = array_map(
      static fn(array $item): int => (int) ($item['target_id'] ?? 0),
      $persistedHousehold->get('members')->getValue(),
    );
    if (in_array($personId, $memberIds, TRUE)) {
      throw new InvalidArgumentException('Person is already a member of this Household.');
    }

    $persistedHousehold->get('members')->appendItem(['target_id' => $personId]);
    $persistedHousehold->save();
    return $persistedHousehold;
  }

  public function createActivitySeries(
    string $name,
    int $householdId,
    DateTimeImmutable $localStart,
    DateTimeImmutable $localEnd,
    string $rrule,
  ): ActivitySeries {
    $name = $this->requiredLabel($name, 'Activity series name');
    $this->requireHousehold($householdId);
    $recurrence = $this->recurrenceValue($localStart, $localEnd, $rrule);

    /** @var \Drupal\personal_secretary\Entity\ActivitySeries $series */
    $series = $this->seriesStorage()->create([
      'name' => $name,
      'household' => $householdId,
      'recurrence' => [$recurrence],
      'effective_from' => $this->toStorage($localStart),
    ]);
    $series->save();
    return $series;
  }

  public function updateActivitySeriesRecurrence(
    ActivitySeries $series,
    DateTimeImmutable $localStart,
    DateTimeImmutable $localEnd,
    string $rrule,
    DateTimeImmutable $effectiveFrom,
  ): ActivitySeries {
    if ($series->isNew() || $series->id() === NULL) {
      throw new InvalidArgumentException('ActivitySeries must be persisted before it can be revised.');
    }

    $storage = $this->seriesStorage();
    $latestRevisionId = $storage->getLatestRevisionId($series->id());
    if ($latestRevisionId === NULL || (string) $latestRevisionId !== (string) $series->getRevisionId()) {
      throw new InvalidArgumentException('Semantic ActivitySeries updates require the latest persisted revision.');
    }

    $latestEffectiveFrom = $this->fromStorage((string) $series->get('effective_from')->value);
    $effectiveFrom = $this->utc($effectiveFrom);
    if ($effectiveFrom <= $latestEffectiveFrom) {
      throw new InvalidArgumentException('ActivitySeries effective-from boundaries must be strictly increasing.');
    }

    $this->requireHousehold((int) $series->get('household')->target_id);
    $recurrence = $this->recurrenceValue($localStart, $localEnd, $rrule);

    $transaction = $this->database->startTransaction();
    try {
      $series->setNewRevision(TRUE);
      $series->set('recurrence', [$recurrence]);
      $series->set('effective_from', $this->toStorage($effectiveFrom));
      $series->save();

      // Domain consistency is synchronous: no queue/background truth may own
      // orphan transitions created by a new semantic series boundary.
      $this->activityExceptions->orphanSupersededTargets($series, $effectiveFrom);
    }
    catch (Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }

    return $series;
  }

  /**
   * @return array{value:string,end_value:string,rrule:string,timezone:string}
   */
  private function recurrenceValue(DateTimeImmutable $localStart, DateTimeImmutable $localEnd, string $rrule): array {
    $rrule = trim($rrule);
    if ($rrule === '') {
      throw new InvalidArgumentException('RRULE must not be empty.');
    }
    if ($localEnd <= $localStart) {
      throw new InvalidArgumentException('Activity series end must be after its start.');
    }

    $timezone = $localStart->getTimezone()->getName();
    if ($timezone === '' || $localEnd->getTimezone()->getName() !== $timezone) {
      throw new InvalidArgumentException('Start and end must use the same explicit source timezone.');
    }

    DateRecurHelper::create($rrule, $localStart, $localEnd);

    $utc = new DateTimeZone('UTC');
    return [
      'value' => $localStart->setTimezone($utc)->format(self::UTC_STORAGE_FORMAT),
      'end_value' => $localEnd->setTimezone($utc)->format(self::UTC_STORAGE_FORMAT),
      'rrule' => $rrule,
      'timezone' => $timezone,
    ];
  }

  private function requireHousehold(int $householdId): Household {
    /** @var \Drupal\personal_secretary\Entity\Household|null $household */
    $household = $this->entityTypeManager
      ->getStorage('personal_secretary_household')
      ->load($householdId);
    if ($household === NULL) {
      throw new InvalidArgumentException('ActivitySeries must reference an existing Household.');
    }
    return $household;
  }

  private function seriesStorage(): RevisionableStorageInterface {
    $storage = $this->entityTypeManager->getStorage('personal_sec_activity_series');
    if (!$storage instanceof RevisionableStorageInterface) {
      throw new RuntimeException('ActivitySeries storage must support revisions.');
    }
    return $storage;
  }

  private function requiredLabel(string $value, string $field): string {
    $value = trim($value);
    if ($value === '') {
      throw new InvalidArgumentException(sprintf('%s must not be empty.', $field));
    }
    return $value;
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
      throw new InvalidArgumentException('ActivitySeries effective-from boundary is invalid.');
    }
    return $parsed;
  }

  private function utc(DateTimeImmutable $value): DateTimeImmutable {
    return $value->setTimezone(new DateTimeZone('UTC'));
  }

}
