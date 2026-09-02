<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Enforces the initial least-privilege boundary for domain entities.
 */
final class DomainEntityAccessControlHandler extends EntityAccessControlHandler {

  private const ADMIN_PERMISSION = 'administer personal secretary domain';

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, self::ADMIN_PERMISSION)
      ->cachePerPermissions();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, self::ADMIN_PERMISSION)
      ->cachePerPermissions();
  }

}
