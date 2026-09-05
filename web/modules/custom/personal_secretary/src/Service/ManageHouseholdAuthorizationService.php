<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\personal_secretary\Entity\Household;
use Drupal\user\UserInterface;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Governs the first administrative User -> Household grant bootstrap.
 */
final class ManageHouseholdAuthorizationService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Replaces one target User's complete explicit Household authorization set.
   *
   * @param array<int, int|string> $householdIds
   *   Selected current Household IDs.
   *
   * @return bool
   *   TRUE when the User was saved, FALSE for an equivalent no-op.
   */
  public function replaceAuthorizationSet(int $targetUserId, array $householdIds): bool {
    if (!$this->currentUser->hasPermission(HouseholdAuthorizationService::ADMIN_PERMISSION)) {
      throw new AccessDeniedHttpException('Administrative Household grant authority is required.');
    }
    if ($targetUserId <= 0) {
      throw new InvalidArgumentException('A persisted non-anonymous target User is required.');
    }

    $userStorage = $this->entityTypeManager->getStorage('user');
    $user = $userStorage->load($targetUserId);
    if (!$user instanceof UserInterface || $user->isAnonymous()) {
      throw new InvalidArgumentException('The target User does not exist.');
    }
    if (!$user->hasField(HouseholdAuthorizationService::FIELD_NAME)) {
      throw new InvalidArgumentException('The Household authorization field is not installed.');
    }

    $normalized = $this->normalizeHouseholdIds($householdIds);
    $householdStorage = $this->entityTypeManager->getStorage('personal_secretary_household');
    $households = $normalized === [] ? [] : $householdStorage->loadMultiple($normalized);
    if (count($households) !== count($normalized)) {
      throw new InvalidArgumentException('Every selected Household must currently exist.');
    }
    foreach ($normalized as $householdId) {
      if (!isset($households[$householdId]) || !$households[$householdId] instanceof Household) {
        throw new InvalidArgumentException('Every selected Household must currently exist.');
      }
    }

    $existing = [];
    foreach ($user->get(HouseholdAuthorizationService::FIELD_NAME) as $item) {
      $id = (int) ($item->target_id ?? 0);
      if ($id > 0) {
        $existing[$id] = $id;
      }
    }
    $existing = array_values($existing);
    sort($existing, SORT_NUMERIC);

    if ($existing === $normalized) {
      return FALSE;
    }

    $user->set(
      HouseholdAuthorizationService::FIELD_NAME,
      array_map(
        static fn(int $householdId): array => ['target_id' => $householdId],
        $normalized,
      ),
    );
    $user->save();

    return TRUE;
  }

  /**
   * @param array<int, int|string> $householdIds
   *
   * @return int[]
   */
  private function normalizeHouseholdIds(array $householdIds): array {
    $normalized = [];
    foreach ($householdIds as $value) {
      if (is_string($value) && ctype_digit($value)) {
        $value = (int) $value;
      }
      if (!is_int($value) || $value <= 0) {
        throw new InvalidArgumentException('Household authorization IDs must be positive integers.');
      }
      $normalized[$value] = $value;
    }

    $normalized = array_values($normalized);
    sort($normalized, SORT_NUMERIC);
    return $normalized;
  }

}
