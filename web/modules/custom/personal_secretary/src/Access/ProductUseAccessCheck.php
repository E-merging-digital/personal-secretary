<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Symfony\Component\Routing\Route;

/**
 * Allows My upcoming only inside an explicit current product scope.
 */
final class ProductUseAccessCheck implements AccessInterface {

  public function __construct(
    private readonly HouseholdAuthorizationService $householdAuthorization,
  ) {}

  public function access(Route $route, AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission(HouseholdAuthorizationService::ADMIN_PERMISSION)) {
      return AccessResult::allowed()
        ->addCacheContexts(['user.permissions']);
    }

    $allowed = $this->householdAuthorization->authorizedHouseholdIds($account) !== [];

    return AccessResult::allowedIf($allowed)
      ->addCacheContexts(['user', 'user.permissions'])
      ->setCacheMaxAge(0);
  }

}
