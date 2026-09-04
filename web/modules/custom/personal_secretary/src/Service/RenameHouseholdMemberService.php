<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\personal_secretary\Entity\Household;
use Drupal\personal_secretary\Entity\Person;
use InvalidArgumentException;
use RuntimeException;

/**
 * Coordinates one bounded Household-member label correction.
 */
final class RenameHouseholdMemberService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DomainMutationService $domainMutations,
  ) {}

  /**
   * @return array<string, string>
   */
  public function personOptions(): array {
    $options = [];
    foreach ($this->memberDirectory() as $personId => $person) {
      $label = trim((string) $person->label());
      if ($label === '') {
        throw new RuntimeException('Household member has no Person label.');
      }
      $options[(string) $personId] = $label;
    }
    natcasesort($options);
    return $options;
  }

  /**
   * @return array{person: \Drupal\personal_secretary\Entity\Person, new_name: string}
   */
  public function prepare(int $personId, string $newName): array {
    if ($personId <= 0) {
      throw new InvalidArgumentException('A valid Household member must be selected.');
    }

    $directory = $this->memberDirectory();
    if (!isset($directory[$personId])) {
      throw new InvalidArgumentException('Selected Person is not a current Household member.');
    }

    $newName = trim($newName);
    if ($newName === '') {
      throw new InvalidArgumentException('Person name must not be empty.');
    }
    if (mb_strlen($newName) > 255) {
      throw new InvalidArgumentException('Person name must not exceed 255 characters.');
    }

    return [
      'person' => $directory[$personId],
      'new_name' => $newName,
    ];
  }

  public function renameMember(int $personId, string $newName): Person {
    $plan = $this->prepare($personId, $newName);
    return $this->domainMutations->renamePerson(
      $plan['person'],
      $plan['new_name'],
    );
  }

  /**
   * @return array<int, \Drupal\personal_secretary\Entity\Person>
   */
  private function memberDirectory(): array {
    $memberIds = [];
    foreach ($this->entityTypeManager->getStorage('personal_secretary_household')->loadMultiple() as $household) {
      if (!$household instanceof Household || $household->id() === NULL) {
        throw new RuntimeException('Household storage returned an invalid entity.');
      }
      foreach ($household->get('members')->getValue() as $item) {
        $personId = (int) ($item['target_id'] ?? 0);
        if ($personId <= 0) {
          throw new RuntimeException('Household membership contains an invalid Person reference.');
        }
        $memberIds[$personId] = $personId;
      }
    }

    if ($memberIds === []) {
      return [];
    }

    $people = $this->entityTypeManager
      ->getStorage('personal_secretary_person')
      ->loadMultiple(array_values($memberIds));
    if (count($people) !== count($memberIds)) {
      throw new RuntimeException('Household membership references a missing Person.');
    }

    $directory = [];
    foreach ($memberIds as $personId) {
      $person = $people[$personId] ?? NULL;
      if (!$person instanceof Person || $person->id() === NULL) {
        throw new RuntimeException('Household membership references an invalid Person.');
      }
      $directory[$personId] = $person;
    }
    return $directory;
  }

}
