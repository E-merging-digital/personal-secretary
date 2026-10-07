<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\personal_secretary\Entity\CalendarAccountConnection;
use Drupal\personal_secretary\Entity\CalendarSyncState;
use Drupal\personal_secretary\Entity\ExternalEventShadow;
use Drupal\personal_secretary\Entity\GoogleCalendarProjection;

/**
 * Current-user persistence boundary for external planning state.
 */
final class ExternalPlanningRepository {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Loads or creates synchronization state for one authorized connection.
   */
  public function syncState(
    CalendarAccountConnection $connection,
    bool $create = TRUE,
  ): ?CalendarSyncState {
    $this->assertConnection($connection);
    $storage = $this->entityTypeManager->getStorage(CalendarSyncState::ENTITY_TYPE_ID);
    $ids = array_values($storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('account_connection', (int) $connection->id())
      ->condition('provider', 'google')
      ->condition('calendar_id', 'primary')
      ->range(0, 2)
      ->execute());

    if (count($ids) > 1) {
      throw new \LogicException('Duplicate Google primary calendar sync state.');
    }
    if ($ids !== []) {
      $storage->resetCache($ids);
      $entity = $storage->load($ids[0]);
      return $entity instanceof CalendarSyncState ? $entity : NULL;
    }
    if (!$create) {
      return NULL;
    }

    $state = $storage->create([
      'owner_user' => $this->uid(),
      'account_connection' => (int) $connection->id(),
      'provider' => 'google',
      'calendar_id' => 'primary',
      'status' => CalendarSyncState::NOT_SYNCED,
      'sync_token' => '',
      'last_mode' => '',
      'last_error_class' => '',
      'last_rebase_date' => '',
    ]);
    $state->save();
    return $state instanceof CalendarSyncState ? $state : NULL;
  }

  /**
   * Returns exact provider event IDs already linked to native occurrences.
   *
   * @param \Drupal\personal_secretary\Entity\CalendarAccountConnection $connection
   *   Authorized current-user calendar connection.
   * @param string[] $eventIds
   *   Provider event IDs from the current traversal.
   *
   * @return array<string, true>
   *   Set of linked provider event IDs.
   */
  public function linkedEventIds(
    CalendarAccountConnection $connection,
    array $eventIds,
  ): array {
    $this->assertConnection($connection);
    $eventIds = array_values(array_unique(array_filter(
      array_map('strval', $eventIds),
      static fn(string $value): bool => $value !== '',
    )));
    if ($eventIds === []) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage(GoogleCalendarProjection::ENTITY_TYPE_ID);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('owner_user', $this->uid())
      ->condition('provider', 'google')
      ->condition('target', 'primary')
      ->condition(
        'provider_subject_id',
        (string) $connection->get('provider_subject_id')->value,
      )
      ->condition('event_id', $eventIds, 'IN')
      ->execute();

    $linked = [];
    foreach ($storage->loadMultiple($ids) as $mapping) {
      if ($mapping instanceof GoogleCalendarProjection) {
        $linked[(string) $mapping->get('event_id')->value] = TRUE;
      }
    }
    return $linked;
  }

  /**
   * Loads one shadow by exact connection and provider event identity.
   */
  public function shadow(
    CalendarAccountConnection $connection,
    string $eventId,
  ): ?ExternalEventShadow {
    $this->assertConnection($connection);
    $storage = $this->entityTypeManager->getStorage(ExternalEventShadow::ENTITY_TYPE_ID);
    $ids = array_values($storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('account_connection', (int) $connection->id())
      ->condition('provider', 'google')
      ->condition('calendar_id', 'primary')
      ->condition('provider_event_id', $eventId)
      ->range(0, 2)
      ->execute());
    if (count($ids) > 1) {
      throw new \LogicException('Duplicate external event shadow.');
    }
    if ($ids === []) {
      return NULL;
    }
    $storage->resetCache($ids);
    $entity = $storage->load($ids[0]);
    return $entity instanceof ExternalEventShadow ? $entity : NULL;
  }

  /**
   * Creates or updates one exact external event shadow.
   *
   * @param \Drupal\personal_secretary\Entity\CalendarAccountConnection $connection
   *   Authorized current-user calendar connection.
   * @param array<string, mixed> $values
   *   Normalized planning values.
   *
   * @return 'created'|'updated'
   *   Persistence action performed.
   */
  public function upsertShadow(
    CalendarAccountConnection $connection,
    array $values,
  ): string {
    $this->assertConnection($connection);
    $eventId = (string) ($values['provider_event_id'] ?? '');
    if ($eventId === '') {
      throw new \InvalidArgumentException('External event upsert requires provider event ID.');
    }

    $shadow = $this->shadow($connection, $eventId);
    $action = $shadow === NULL ? 'created' : 'updated';
    if ($shadow === NULL) {
      $shadow = $this->entityTypeManager
        ->getStorage(ExternalEventShadow::ENTITY_TYPE_ID)
        ->create([
          'owner_user' => $this->uid(),
          'account_connection' => (int) $connection->id(),
          'provider' => 'google',
          'calendar_id' => 'primary',
          'provider_event_id' => $eventId,
        ]);
    }

    if (!$shadow instanceof ExternalEventShadow) {
      throw new \LogicException('External event shadow could not be materialized.');
    }

    foreach ($values as $name => $value) {
      if ($shadow->hasField($name)) {
        $shadow->set($name, $value);
      }
    }
    $shadow->save();
    return $action;
  }

  /**
   * Deactivates and minimizes one exact provider-event tombstone.
   */
  public function deactivateShadow(
    CalendarAccountConnection $connection,
    string $eventId,
    int $seenAt,
  ): bool {
    $shadow = $this->shadow($connection, $eventId);
    if ($shadow === NULL) {
      return FALSE;
    }
    $changed = (bool) $shadow->get('active')->value;
    $shadow->set('active', FALSE)
      ->set('provider_status', 'cancelled')
      ->set('last_seen_at', $seenAt);
    $this->scrubInactiveShadow($shadow);
    $shadow->save();
    return $changed;
  }

  /**
   * Deactivates active shadows absent from a successful replacement bootstrap.
   *
   * @param \Drupal\personal_secretary\Entity\CalendarAccountConnection $connection
   *   Authorized current-user calendar connection.
   * @param string[] $seenEventIds
   *   Exact provider event IDs present in the replacement window.
   * @param int $seenAt
   *   Synchronization timestamp.
   */
  public function deactivateMissing(
    CalendarAccountConnection $connection,
    array $seenEventIds,
    int $seenAt,
  ): int {
    $this->assertConnection($connection);
    $storage = $this->entityTypeManager->getStorage(ExternalEventShadow::ENTITY_TYPE_ID);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('account_connection', (int) $connection->id())
      ->condition('provider', 'google')
      ->condition('calendar_id', 'primary')
      ->condition('active', TRUE)
      ->execute();

    $seen = array_fill_keys($seenEventIds, TRUE);
    $count = 0;
    foreach ($storage->loadMultiple($ids) as $shadow) {
      if (!$shadow instanceof ExternalEventShadow) {
        continue;
      }
      $eventId = (string) $shadow->get('provider_event_id')->value;
      if (isset($seen[$eventId])) {
        continue;
      }
      $shadow->set('active', FALSE)
        ->set('last_seen_at', $seenAt);
      $this->scrubInactiveShadow($shadow);
      $shadow->save();
      $count++;
    }
    return $count;
  }

  /**
   * Returns active planning shadows for one authorized connection.
   *
   * @return \Drupal\personal_secretary\Entity\ExternalEventShadow[]
   *   Active provider planning shadows.
   */
  public function activeShadows(CalendarAccountConnection $connection): array {
    $this->assertConnection($connection);
    $storage = $this->entityTypeManager->getStorage(ExternalEventShadow::ENTITY_TYPE_ID);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('account_connection', (int) $connection->id())
      ->condition('provider', 'google')
      ->condition('calendar_id', 'primary')
      ->condition('active', TRUE)
      ->execute();
    return array_values(array_filter(
      $storage->loadMultiple($ids),
      static fn(mixed $entity): bool => $entity instanceof ExternalEventShadow,
    ));
  }

  /**
   * Removes planning payload from an inactive tombstone while keeping identity.
   */
  private function scrubInactiveShadow(ExternalEventShadow $shadow): void {
    foreach ([
      'title',
      'location',
      'source_timezone',
      'transparency',
      'recurring_event_id',
      'original_start_time',
      'original_start_timezone',
      'timed_start',
      'timed_end',
      'all_day_start',
      'all_day_end',
    ] as $field) {
      $shadow->set($field, NULL);
    }
  }

  /**
   * Verifies the current-user connection is the authorized Google account.
   */
  private function assertConnection(CalendarAccountConnection $connection): void {
    if ((int) $connection->get('owner_user')->target_id !== $this->uid()
      || $connection->get('provider_key')->value !== CalendarAccountConnection::PROVIDER_GOOGLE
      || $connection->get('status')->value !== CalendarAccountConnection::STATUS_CONNECTED) {
      throw new \InvalidArgumentException('External planning connection authority mismatch.');
    }
  }

  /**
   * Returns the authenticated current-user ID.
   */
  private function uid(): int {
    $uid = (int) $this->currentUser->id();
    if ($uid <= 0 || !$this->currentUser->isAuthenticated()) {
      throw new \LogicException('External planning requires an authenticated User.');
    }
    return $uid;
  }

}
