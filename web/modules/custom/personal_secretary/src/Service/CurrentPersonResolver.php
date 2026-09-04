<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\personal_secretary\Entity\Person;
use Drupal\user\UserInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves the exact domain Person for one authenticated Drupal account.
 */
final class CurrentPersonResolver {

  public const FIELD_NAME = 'field_personal_secretary_person';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function resolve(AccountInterface $account): Person {
    $uid = (int) $account->id();
    if ($account->isAnonymous() || $uid <= 0) {
      throw new InvalidArgumentException('Current Person requires an authenticated Drupal User.');
    }

    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface || !$user->isActive()) {
      throw new InvalidArgumentException('Current Person requires an active persisted Drupal User.');
    }
    if (!$user->hasField(self::FIELD_NAME)) {
      throw new RuntimeException('Current Person mapping field is unavailable.');
    }

    $mapping = $user->get(self::FIELD_NAME);
    if ($mapping->isEmpty() || $mapping->count() !== 1) {
      throw new InvalidArgumentException('Drupal User has no valid Current Person mapping.');
    }

    $personId = (int) $mapping->target_id;
    if ($personId <= 0) {
      throw new InvalidArgumentException('Drupal User has an invalid Current Person reference.');
    }

    $person = $this->entityTypeManager
      ->getStorage('personal_secretary_person')
      ->load($personId);
    if (!$person instanceof Person || $person->isNew() || $person->id() === NULL) {
      throw new InvalidArgumentException('Drupal User references a missing Current Person.');
    }

    return $person;
  }

}
