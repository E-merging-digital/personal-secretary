<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\date_recur\DateRecurHelper;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\Household;
use Drupal\personal_secretary\Entity\Person;
use InvalidArgumentException;

/**
 * Governed mutation boundary for the first domain persistence slice.
 */
final class DomainMutationService {

  private const UTC_STORAGE_FORMAT = 'Y-m-d\\TH:i:s';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
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
    $series = $this->entityTypeManager->getStorage('personal_sec_activity_series')->create([
      'name' => $name,
      'household' => $householdId,
      'recurrence' => [$recurrence],
    ]);
    $series->save();
    return $series;
  }

  public function updateActivitySeriesRecurrence(
    ActivitySeries $series,
    DateTimeImmutable $localStart,
    DateTimeImmutable $localEnd,
    string $rrule,
  ): ActivitySeries {
    if ($series->isNew()) {
      throw new InvalidArgumentException('ActivitySeries must be persisted before it can be revised.');
    }

    $this->requireHousehold((int) $series->get('household')->target_id);
    $series->setNewRevision(TRUE);
    $series->set('recurrence', [$this->recurrenceValue($localStart, $localEnd, $rrule)]);
    $series->save();
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

    // Validate the already-adopted recurrence primitive before persistence.
    DateRecurHelper::create($rrule, $localStart, $localEnd);

    $utc = new DateTimeZone('UTC');
    return [
      'value' => $localStart->setTimezone($utc)->format(self::UTC_STORAGE_FORMAT),
      'end_value' => $localEnd->setTimezone($utc)->format(self::UTC_STORAGE_FORMAT),
      'rrule' => $rrule,
      // This date_recur property is the single canonical source-timezone truth.
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

  private function requiredLabel(string $value, string $field): string {
    $value = trim($value);
    if ($value === '') {
      throw new InvalidArgumentException(sprintf('%s must not be empty.', $field));
    }
    return $value;
  }

}
