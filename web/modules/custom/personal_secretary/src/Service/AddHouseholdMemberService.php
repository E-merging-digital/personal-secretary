<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\personal_secretary\Entity\Household;
use Drupal\personal_secretary\Entity\Person;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Coordinates creation of one new Person and Household membership.
 */
final class AddHouseholdMemberService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly DomainMutationService $domainMutations,
  ) {}

  /**
   * @return array<string, string>
   */
  public function householdOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('personal_secretary_household')->loadMultiple() as $household) {
      if (!$household instanceof Household || $household->id() === NULL) {
        throw new RuntimeException('Household storage returned an invalid entity.');
      }
      $options[(string) $household->id()] = (string) $household->label();
    }
    natcasesort($options);
    return $options;
  }

  /**
   * @return array{household: \Drupal\personal_secretary\Entity\Household, person_name: string}
   */
  public function prepare(int $householdId, string $personName): array {
    if ($householdId <= 0) {
      throw new InvalidArgumentException('A valid Household must be selected.');
    }

    $household = $this->entityTypeManager
      ->getStorage('personal_secretary_household')
      ->load($householdId);
    if (!$household instanceof Household) {
      throw new InvalidArgumentException('Selected Household does not exist.');
    }

    $personName = trim($personName);
    if ($personName === '') {
      throw new InvalidArgumentException('Person name must not be empty.');
    }
    if (mb_strlen($personName) > 255) {
      throw new InvalidArgumentException('Person name must not exceed 255 characters.');
    }

    return [
      'household' => $household,
      'person_name' => $personName,
    ];
  }

  /**
   * @return array{household: \Drupal\personal_secretary\Entity\Household, person: \Drupal\personal_secretary\Entity\Person}
   */
  public function addNewMember(int $householdId, string $personName): array {
    $plan = $this->prepare($householdId, $personName);
    $transaction = $this->database->startTransaction();

    try {
      $person = $this->domainMutations->createPerson($plan['person_name']);
      $household = $this->domainMutations->addHouseholdMember(
        $plan['household'],
        $person,
      );

      $transaction->commitOrRelease();
      return [
        'household' => $household,
        'person' => $person,
      ];
    }
    catch (Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

}
