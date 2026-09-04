<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\personal_secretary\Entity\Household;
use Drupal\personal_secretary\Entity\Person;
use Drupal\user\UserInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Governs the current Drupal User -> Household-member Person binding.
 */
final class LinkCurrentUserToPersonService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
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
   * @return array{user: \Drupal\user\UserInterface, person: \Drupal\personal_secretary\Entity\Person, already_linked: bool}
   */
  public function prepare(int $personId): array {
    if ($personId <= 0) {
      throw new InvalidArgumentException('A valid Household member must be selected.');
    }

    $user = $this->activeCurrentUser();
    $directory = $this->memberDirectory();
    $person = $directory[$personId] ?? NULL;
    if (!$person instanceof Person) {
      throw new InvalidArgumentException('Selected Person is not a current Household member.');
    }

    if (!$user->hasField(CurrentPersonResolver::FIELD_NAME)) {
      throw new RuntimeException('Current Person mapping field is unavailable.');
    }

    $mapping = $user->get(CurrentPersonResolver::FIELD_NAME);
    $currentPersonId = $mapping->isEmpty() ? NULL : (int) $mapping->target_id;

    return [
      'user' => $user,
      'person' => $person,
      'already_linked' => $currentPersonId === $personId,
    ];
  }

  /**
   * Links the current User and reports whether a User write occurred.
   */
  public function link(int $personId): bool {
    $plan = $this->prepare($personId);
    if ($plan['already_linked']) {
      return FALSE;
    }

    $plan['user']->set(CurrentPersonResolver::FIELD_NAME, [
      'target_id' => (int) $plan['person']->id(),
    ]);
    $plan['user']->save();
    return TRUE;
  }

  private function activeCurrentUser(): UserInterface {
    $uid = (int) $this->currentUser->id();
    if ($this->currentUser->isAnonymous() || $uid <= 0) {
      throw new InvalidArgumentException('Linking requires an authenticated Drupal User.');
    }

    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface || !$user->isActive()) {
      throw new InvalidArgumentException('Linking requires an active persisted Drupal User.');
    }
    return $user;
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
