<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\personal_secretary\Entity\CalendarAccountConnection;

/**
 * Current-User Google Calendar connection metadata boundary.
 */
final class GoogleCalendarConnectionService {

  public const STATE_NOT_CONNECTED = 'NOT_CONNECTED';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns the current User's single Google connection.
   */
  public function currentConnection(): ?CalendarAccountConnection {
    $uid = $this->currentUserId();
    $storage = $this->entityTypeManager->getStorage(
      CalendarAccountConnection::ENTITY_TYPE_ID,
    );

    $ids = array_values(
      $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('owner_user', $uid)
        ->condition(
          'provider_key',
          CalendarAccountConnection::PROVIDER_GOOGLE,
        )
        ->range(0, 2)
        ->execute(),
    );

    if (count($ids) > 1) {
      throw new EntityStorageException(
        'More than one Google Calendar connection exists for one User.',
      );
    }

    if ($ids === []) {
      return NULL;
    }

    $connection = $storage->load($ids[0]);
    return $connection instanceof CalendarAccountConnection
      ? $connection
      : NULL;
  }

  /**
   * Returns NOT_CONNECTED, CONNECTED, or INVALID.
   */
  public function currentState(): string {
    $connection = $this->currentConnection();
    if ($connection === NULL) {
      return self::STATE_NOT_CONNECTED;
    }

    return (string) $connection->get('status')->value;
  }

  /**
   * Creates or updates the current User's verified Google connection.
   *
   * @param string[] $scopes
   *   Exact verified/requested connection scopes.
   */
  public function connect(
    string $providerSubjectId,
    array $scopes,
  ): CalendarAccountConnection {
    $providerSubjectId = trim($providerSubjectId);
    if ($providerSubjectId === '') {
      throw new \InvalidArgumentException(
        'Google OIDC subject must not be empty.',
      );
    }

    $normalized = array_values(array_unique(array_map(
      static fn(mixed $scope): string => trim((string) $scope),
      $scopes,
    )));
    sort($normalized, SORT_STRING);

    if ($normalized !== CalendarAccountConnection::connectionScopes()) {
      throw new \InvalidArgumentException(
        'Google Calendar connection scopes are not exact.',
      );
    }

    $storage = $this->entityTypeManager->getStorage(
      CalendarAccountConnection::ENTITY_TYPE_ID,
    );

    $connection = $this->currentConnection();
    if ($connection === NULL) {
      $connection = $storage->create([
        'owner_user' => $this->currentUserId(),
        'provider_key' => CalendarAccountConnection::PROVIDER_GOOGLE,
      ]);
    }

    if (!$connection instanceof CalendarAccountConnection) {
      throw new EntityStorageException(
        'Unable to materialize Google Calendar connection.',
      );
    }

    $connection->set('provider_subject_id', $providerSubjectId);
    $connection->set(
      'scopes',
      array_map(
        static fn(string $scope): array => ['value' => $scope],
        $normalized,
      ),
    );
    $connection->set(
      'status',
      CalendarAccountConnection::STATUS_CONNECTED,
    );
    $connection->set(
      'connected_at',
      $this->time->getCurrentTime(),
    );
    $connection->save();

    return $connection;
  }

  /**
   * Marks an existing current-User connection invalid.
   */
  public function markInvalid(): void {
    $connection = $this->currentConnection();
    if ($connection === NULL) {
      return;
    }

    $connection->set(
      'status',
      CalendarAccountConnection::STATUS_INVALID,
    );
    $connection->save();
  }

  /**
   * Removes current-User connection metadata.
   */
  public function disconnectLocal(): void {
    $connection = $this->currentConnection();
    if ($connection !== NULL) {
      $connection->delete();
    }
  }

  /**
   * Returns the authenticated User ID.
   */
  private function currentUserId(): int {
    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      throw new \LogicException(
        'Google Calendar connection requires an authenticated User.',
      );
    }

    return $uid;
  }

}
