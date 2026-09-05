<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\personal_secretary\Entity\Household;
use Drupal\user\UserInterface;
use InvalidArgumentException;

/**
 * Resolves explicit Drupal User -> Household product authority.
 */
final class HouseholdAuthorizationService {

  public const FIELD_NAME = 'field_personal_sec_households';
  public const PRODUCT_USE_PERMISSION = 'use personal secretary';
  public const ADMIN_PERMISSION = 'administer personal secretary domain';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns exact current Household IDs authorized for the persisted account.
   *
   * A stale grant fails closed for the complete ordinary-user scope.
   *
   * @return int[]
   */
  public function authorizedHouseholdIds(AccountInterface $account): array {
    $user = $this->loadActiveUser($account);
    if (!$user instanceof UserInterface) {
      return [];
    }

    $householdStorage = $this->entityTypeManager->getStorage('personal_secretary_household');

    if ($user->hasPermission(self::ADMIN_PERMISSION)) {
      $ids = $householdStorage
        ->getQuery()
        ->accessCheck(FALSE)
        ->execute();
      $ids = array_map('intval', array_values($ids));
      sort($ids, SORT_NUMERIC);
      return $ids;
    }

    if (!$user->hasPermission(self::PRODUCT_USE_PERMISSION) || !$user->hasField(self::FIELD_NAME)) {
      return [];
    }

    $ids = [];
    foreach ($user->get(self::FIELD_NAME) as $item) {
      $targetId = (int) ($item->target_id ?? 0);
      if ($targetId <= 0) {
        return [];
      }
      $ids[$targetId] = $targetId;
    }

    if ($ids === []) {
      return [];
    }

    $ids = array_values($ids);
    sort($ids, SORT_NUMERIC);
    $households = $householdStorage->loadMultiple($ids);
    if (count($households) !== count($ids)) {
      return [];
    }

    foreach ($ids as $id) {
      $household = $households[$id] ?? NULL;
      if (!$household instanceof Household || (int) $household->id() !== $id) {
        return [];
      }
    }

    return $ids;
  }

  public function isAuthorized(AccountInterface $account, Household|int $household): bool {
    $target = $this->loadHousehold($household);
    if (!$target instanceof Household || $target->id() === NULL) {
      return FALSE;
    }

    return in_array((int) $target->id(), $this->authorizedHouseholdIds($account), TRUE);
  }

  public function requireAuthorized(AccountInterface $account, Household|int $household): Household {
    $target = $this->loadHousehold($household);
    if (!$target instanceof Household || !$this->isAuthorized($account, $target)) {
      throw new InvalidArgumentException('The account is not authorized for the requested Household.');
    }

    return $target;
  }

  private function loadActiveUser(AccountInterface $account): ?UserInterface {
    if ($account->isAnonymous()) {
      return NULL;
    }

    $uid = (int) $account->id();
    if ($uid <= 0) {
      return NULL;
    }

    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface || !$user->isActive()) {
      return NULL;
    }

    return $user;
  }

  private function loadHousehold(Household|int $household): ?Household {
    $id = $household instanceof Household ? (int) $household->id() : $household;
    if ($id <= 0) {
      return NULL;
    }

    $persisted = $this->entityTypeManager
      ->getStorage('personal_secretary_household')
      ->load($id);

    return $persisted instanceof Household ? $persisted : NULL;
  }

}
