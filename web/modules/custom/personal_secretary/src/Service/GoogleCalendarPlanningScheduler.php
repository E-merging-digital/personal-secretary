<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\personal_secretary\Entity\CalendarAccountConnection;
use Drupal\user\UserInterface;

/**
 * Governs approximately-15-minute Google planning refreshes from Drupal cron.
 */
final class GoogleCalendarPlanningScheduler {

  private const MIN_INTERVAL = 900;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
    private readonly ExternalPlanningRepository $repository,
    private readonly GoogleCalendarPlanningSyncService $sync,
  ) {}

  /**
   * Refreshes due connected accounts through the governed cron path.
   */
  public function refreshDueConnections(): int {
    $connectionStorage = $this->entityTypeManager->getStorage(
      CalendarAccountConnection::ENTITY_TYPE_ID,
    );
    $ids = $connectionStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('provider_key', CalendarAccountConnection::PROVIDER_GOOGLE)
      ->condition('status', CalendarAccountConnection::STATUS_CONNECTED)
      ->execute();

    $users = $this->entityTypeManager->getStorage('user');
    $now = $this->time->getCurrentTime();
    $refreshed = 0;

    foreach ($connectionStorage->loadMultiple($ids) as $connection) {
      if (!$connection instanceof CalendarAccountConnection
        || !$connection->hasWriteScope()) {
        continue;
      }

      $account = $users->load((int) $connection->get('owner_user')->target_id);
      if (!$account instanceof UserInterface || !$account->isActive()) {
        continue;
      }

      $lockId = 'personal_secretary.calendar_sync.' . $connection->id();
      if (!$this->lock->acquire($lockId, self::MIN_INTERVAL - 30)) {
        continue;
      }

      $this->accountSwitcher->switchTo($account);
      try {
        $state = $this->repository->syncState($connection, FALSE);
        $lastAttempt = $state === NULL
          ? 0
          : (int) ($state->get('last_attempt_at')->value ?? 0);
        if ($lastAttempt > 0 && $lastAttempt > $now - self::MIN_INTERVAL) {
          continue;
        }

        try {
          $this->sync->refreshCurrentUser();
          $refreshed++;
        }
        catch (\Throwable) {
          // The sync service persists sanitized health state.
          // Cron remains bounded.
        }
      }
      finally {
        $this->accountSwitcher->switchBack();
        $this->lock->release($lockId);
      }
    }

    return $refreshed;
  }

}
