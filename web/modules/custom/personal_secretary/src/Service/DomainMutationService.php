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

  public function renamePerson(Person $person, string $name): Person {
    if ($person->isNew() || $person->id() === NULL) {
      throw new InvalidArgumentException('Person must be persisted before it can be renamed.');
    }

    $name = $this->requiredLabel($name, 'Person name');
    if (mb_strlen($name) > 255) {
      throw new InvalidArgumentException('Person name must not exceed 255 characters.');
    }

    $storage = $this->entityTypeManager->getStorage('personal_secretary_person');
    $storage->resetCache([(int) $person->id()]);
    $persistedPerson = $storage->load($person->id());
    if (!$persistedPerson instanceof Person) {
      throw new InvalidArgumentException('Person rename requires an existing Person.');
    }
    if ($persistedPerson->uuid() !== $person->uuid()) {
      throw new InvalidArgumentException('Person identity does not match the persisted Person.');
    }

    $persistedPerson->set('name', $name);
    $persistedPerson->save();
    return $persistedPerson;
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


  /**
   * Corrects only the name of the same persisted Household.
   *
   * $original must originate from the private server-side GET snapshot.
   *
   * @param array{id:int, uuid:string, name:string, members:array} $original
   */
  public function renameHousehold(int $id, string $name, array $original): Household {
    $name = trim($name);
    if ($id <= 0 || $name === '' || mb_strlen($name) > 255) {
      throw new InvalidArgumentException('Household name must contain between 1 and 255 characters.');
    }
    if (
      !isset($original['id'], $original['uuid'], $original['name'], $original['members'])
      || !is_int($original['id'])
      || !is_string($original['uuid'])
      || !is_string($original['name'])
      || !is_array($original['members'])
      || $original['id'] !== $id
    ) {
      throw new InvalidArgumentException('Trusted Household snapshot is invalid.');
    }

    $storage = $this->entityTypeManager->getStorage('personal_secretary_household');
    $storage->resetCache([$id]);
    $persisted = $storage->load($id);
    if (
      !$persisted instanceof Household
      || (int) $persisted->id() !== $id
      || $persisted->uuid() !== $original['uuid']
      || (string) $persisted->get('name')->value !== $original['name']
      || $persisted->get('members')->getValue() !== $original['members']
      || !$persisted->access('update')
    ) {
      throw new InvalidArgumentException('Household changed or update authority is unavailable.');
    }

    if ((string) $persisted->get('name')->value === $name) {
      return $persisted;
    }
    $persisted->set('name', $name);
    $persisted->save();
    return $persisted;
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
    string $location = '',
    array $concernedPersonIds = [],
    string $timeMode = ActivitySeries::TIME_MODE_TIMED,
    array $postalAddress = [],
  ): ActivitySeries {
    $name = $this->requiredLabel($name, 'Activity series name');
    $location = $this->optionalLocation($location);
    $postalAddress = $this->normalizedPostalAddress($postalAddress);
    $timeMode = $this->timeMode($timeMode);
    $household = $this->requireHousehold($householdId);
    $concernedPersonIds = $this->normalizeConcernedPersonIds($concernedPersonIds);
    $this->assertConcernedPersonsInHousehold($household, $concernedPersonIds);
    if ($timeMode === ActivitySeries::TIME_MODE_ALL_DAY) {
      $this->assertAllDayBoundaries($localStart, $localEnd);
    }
    $recurrence = $this->recurrenceValue($localStart, $localEnd, $rrule);

    /** @var \Drupal\personal_secretary\Entity\ActivitySeries $series */
    $series = $this->seriesStorage()->create([
      'name' => $name,
      'household' => $householdId,
      'concerned_persons' => array_map(
        static fn(int $id): array => ['target_id' => $id],
        $concernedPersonIds,
      ),
      'recurrence' => [$recurrence],
      'effective_from' => $this->toStorage($localStart),
      'location' => $location,
      'postal_address' => $postalAddress,
      'time_mode' => $timeMode,
    ]);
    if ($series->get('postal_address')->validate()->count() > 0) {
      throw new InvalidArgumentException('Activity postal address is invalid.');
    }
    $series->save();
    return $series;
  }

  /**
   * Updates only current-default non-revisionable location metadata.
   *
   * Preserves recurrence/exception target identity and revision chronology.
   */
  public function updateActivitySeriesLocation(ActivitySeries $series, string $location, ?array $postalAddress = NULL): ActivitySeries {
    if ($series->isNew() || $series->id() === NULL) {
      throw new InvalidArgumentException('ActivitySeries must be persisted before changing its location.');
    }
    $location = $this->optionalLocation($location);
    $storage = $this->seriesStorage();
    $storage->resetCache([(int) $series->id()]);
    $persisted = $storage->load($series->id());
    $latestRevisionId = $storage->getLatestRevisionId($series->id());
    if (
      !$persisted instanceof ActivitySeries
      || $persisted->uuid() !== $series->uuid()
      || $latestRevisionId === NULL
      || (string) $latestRevisionId !== (string) $series->getRevisionId()
      || (string) $latestRevisionId !== (string) $persisted->getRevisionId()
      || (string) ($persisted->get('location')->value ?? '') !== (string) ($series->get('location')->value ?? '')
      || $persisted->get('postal_address')->getValue() !== $series->get('postal_address')->getValue()
    ) {
      throw new InvalidArgumentException('ActivitySeries location update requires the latest unchanged persisted series.');
    }
    $postalAddress ??= $persisted->get('postal_address')->getValue();
    $postalAddress = $this->normalizedPostalAddress($postalAddress);
    if (
      (string) ($persisted->get('location')->value ?? '') === $location
      && $persisted->get('postal_address')->getValue() === $postalAddress
    ) {
      return $persisted;
    }
    $persisted->setNewRevision(FALSE);
    $persisted->set('location', $location);
    $persisted->set('postal_address', $postalAddress);
    if ($persisted->get('postal_address')->validate()->count() > 0) {
      throw new InvalidArgumentException('Activity postal address is invalid.');
    }
    $persisted->save();
    return $persisted;
  }

  /**
   * Corrects only the latest revision's label, never the semantic timeline.
   *
   * $originalName must come from the private GET snapshot, not a POST field.
   */
  public function renameActivitySeries(ActivitySeries $series, string $name, string $originalName): ActivitySeries {
    if ($series->isNew() || $series->id() === NULL) {
      throw new InvalidArgumentException('ActivitySeries must be persisted before renaming.');
    }
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 255) {
      throw new InvalidArgumentException('Activity name must contain between 1 and 255 characters.');
    }

    $storage = $this->seriesStorage();
    $storage->resetCache([(int) $series->id()]);
    $persisted = $storage->load($series->id());
    $latestRevisionId = $storage->getLatestRevisionId($series->id());
    if (
      !$persisted instanceof ActivitySeries
      || $persisted->uuid() !== $series->uuid()
      || $latestRevisionId === NULL
      || (string) $persisted->getRevisionId() !== (string) $latestRevisionId
      || (string) $series->getRevisionId() !== (string) $latestRevisionId
      || (string) $persisted->get('name')->value !== $originalName
      || !$persisted->access('update')
    ) {
      throw new InvalidArgumentException('Activity rename requires the latest unchanged authorized series.');
    }

    if ((string) $persisted->get('name')->value === $name) {
      return $persisted;
    }
    // Do not create a new semantic revision or change effective_from: all
    // exception/occurrence identities include the persisted revision ID.
    $persisted->setNewRevision(FALSE);
    $persisted->set('name', $name);
    $persisted->save();
    if ((string) $persisted->getRevisionId() !== (string) $latestRevisionId) {
      throw new RuntimeException('Activity rename unexpectedly created a semantic revision.');
    }
    return $persisted;
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
    if ($series->timeMode() === ActivitySeries::TIME_MODE_ALL_DAY) {
      $this->assertAllDayBoundaries($localStart, $localEnd);
    }
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

  /**
   * @param array<int, int|string> $concernedPersonIds
   *
   * @return int[]
   */
  private function normalizeConcernedPersonIds(array $concernedPersonIds): array {
    $normalized = [];
    foreach ($concernedPersonIds as $value) {
      if (is_string($value) && ctype_digit($value)) {
        $value = (int) $value;
      }
      if (!is_int($value) || $value <= 0) {
        throw new InvalidArgumentException('Concerned Person IDs must be positive integers.');
      }
      $normalized[$value] = $value;
    }

    $normalized = array_values($normalized);
    sort($normalized, SORT_NUMERIC);
    return $normalized;
  }

  /**
   * @param int[] $concernedPersonIds
   */
  private function assertConcernedPersonsInHousehold(Household $household, array $concernedPersonIds): void {
    if ($concernedPersonIds === []) {
      return;
    }

    $people = $this->entityTypeManager
      ->getStorage('personal_secretary_person')
      ->loadMultiple($concernedPersonIds);
    if (count($people) !== count($concernedPersonIds)) {
      throw new InvalidArgumentException('Every concerned Person must reference an existing Person.');
    }

    $memberIds = array_map(
      static fn(array $item): int => (int) ($item['target_id'] ?? 0),
      $household->get('members')->getValue(),
    );
    foreach ($concernedPersonIds as $personId) {
      if (!in_array($personId, $memberIds, TRUE)) {
        throw new InvalidArgumentException('Every concerned Person must belong to the selected Household.');
      }
    }
  }

  private function timeMode(string $timeMode): string {
    $timeMode = trim($timeMode);
    if (!ActivitySeries::supportsTimeMode($timeMode)) {
      throw new InvalidArgumentException('Activity time mode must be TIMED or ALL_DAY.');
    }
    return $timeMode;
  }

  private function assertAllDayBoundaries(DateTimeImmutable $localStart, DateTimeImmutable $localEnd): void {
    if (
      $localStart->format('H:i:s') !== '00:00:00'
      || $localEnd->format('H:i:s') !== '00:00:00'
    ) {
      throw new InvalidArgumentException('ALL_DAY ActivitySeries boundaries must be source-local midnights.');
    }
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

  /**
   * Accepts a single Drupal Address item or an empty optional address.
   *
   * A country selected by default does not create an address on its own.
   * @param array<string, mixed>|array<int, array<string, mixed>> $input
   * @return array<int, array<string, mixed>>
   */
  private function normalizedPostalAddress(array $input): array {
    if (array_is_list($input)) {
      if (count($input) > 1) {
        throw new InvalidArgumentException('Only one activity postal address is supported.');
      }
      $input = $input[0] ?? [];
    }
    if (!is_array($input)) {
      throw new InvalidArgumentException('Activity postal address must be a field item.');
    }
    $meaningful = FALSE;
    foreach ([
      'address_line1', 'address_line2', 'locality', 'dependent_locality',
      'postal_code', 'administrative_area', 'sorting_code', 'organization',
      'given_name', 'family_name',
    ] as $name) {
      if (isset($input[$name]) && is_string($input[$name]) && trim($input[$name]) !== '') {
        $meaningful = TRUE;
        break;
      }
    }
    if (!$meaningful) {
      return [];
    }
    if (!isset($input['country_code']) || !is_string($input['country_code']) || trim($input['country_code']) === '') {
      throw new InvalidArgumentException('Activity postal address requires a selected country.');
    }
    return [$input];
  }

  private function optionalLocation(string $value): string {
    $value = trim($value);
    if (mb_strlen($value) > 255) {
      throw new InvalidArgumentException('Activity location must not exceed 255 characters.');
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
