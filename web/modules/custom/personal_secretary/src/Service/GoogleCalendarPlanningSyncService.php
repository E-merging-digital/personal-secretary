<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\personal_secretary\Entity\CalendarAccountConnection;
use Drupal\personal_secretary\Entity\CalendarSyncState;
use Drupal\personal_secretary\Entity\ExternalEventShadow;

/**
 * Orchestrates bounded Google primary-calendar planning ingestion.
 */
final class GoogleCalendarPlanningSyncService {

  private const MAX_PAGES = 100;

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly GoogleCalendarConnectionService $connections,
    private readonly ExternalPlanningRepository $repository,
    private readonly GoogleCalendarIngestionTransport $transport,
  ) {}

  /**
   * Refreshes the current User's Google primary-calendar planning shadows.
   *
   * @return array<string, int|string>
   *   Sanitized synchronization receipt counts.
   */
  public function refreshCurrentUser(): array {
    $connection = $this->connections->currentConnection();
    if (!$connection instanceof CalendarAccountConnection
      || $connection->get('status')->value !== CalendarAccountConnection::STATUS_CONNECTED
      || !$connection->hasWriteScope()) {
      throw new \InvalidArgumentException(
        'Google planning refresh requires the existing owned-event grant.',
      );
    }

    $state = $this->repository->syncState($connection);
    if (!$state instanceof CalendarSyncState) {
      throw new \RuntimeException('Google planning sync state is unavailable.');
    }

    $now = $this->time->getCurrentTime();
    $todayUtc = gmdate('Y-m-d', $now);
    $token = trim((string) $state->get('sync_token')->value);
    $status = (string) $state->get('status')->value;
    $lastRebase = (string) $state->get('last_rebase_date')->value;

    $bootstrap = $token === ''
      || $lastRebase !== $todayUtc
      || in_array(
        $status,
        [CalendarSyncState::TOKEN_INVALID, CalendarSyncState::REBASE_REQUIRED],
        TRUE,
      );

    $state->set('status', CalendarSyncState::SYNCING)
      ->set('last_attempt_at', $now)
      ->set('last_error_class', '')
      ->save();

    try {
      if ($bootstrap) {
        return $this->bootstrap($connection, $state, $now, $todayUtc);
      }

      $collected = $this->collectIncremental($token);
      if ($collected['token_invalid']) {
        $state->set('status', CalendarSyncState::TOKEN_INVALID)
          ->set('last_error_class', 'TOKEN_INVALID')
          ->save();

        return $this->bootstrap($connection, $state, $now, $todayUtc);
      }

      return $this->publish(
        $connection,
        $state,
        $collected['items'],
        $collected['next_sync_token'],
        $now,
        'incremental',
        FALSE,
        $lastRebase,
      );
    }
    catch (\Throwable $exception) {
      $state->set(
        'status',
        $status === CalendarSyncState::TOKEN_INVALID
          || (string) $state->get('status')->value === CalendarSyncState::TOKEN_INVALID
          ? CalendarSyncState::REBASE_REQUIRED
          : CalendarSyncState::ERROR,
      );
      $state->set('last_error_class', $this->failureClass($exception))->save();
      throw new \RuntimeException(
        'Google Calendar planning refresh failed without replacing last-known-good planning data.',
        0,
        $exception,
      );
    }
  }

  /**
   * Performs one bounded full bootstrap and publishes it atomically.
   *
   * @return array<string, int|string>
   *   Sanitized synchronization receipt counts.
   */
  private function bootstrap(
    CalendarAccountConnection $connection,
    CalendarSyncState $state,
    int $now,
    string $todayUtc,
  ): array {
    $utc = new \DateTimeZone('UTC');
    $instant = (new \DateTimeImmutable('@' . $now))->setTimezone($utc);
    $collected = $this->collectBootstrap(
      $instant->modify('-1 day'),
      $instant->modify('+90 days'),
    );

    return $this->publish(
      $connection,
      $state,
      $collected['items'],
      $collected['next_sync_token'],
      $now,
      'bootstrap',
      TRUE,
      $todayUtc,
    );
  }

  /**
   * Collects every bootstrap page before any replacement publication.
   *
   * @return array{items:array<int,array<string,mixed>>,next_sync_token:string,token_invalid:bool}
   *   Complete provider items and final replacement cursor.
   */
  private function collectBootstrap(
    \DateTimeImmutable $timeMin,
    \DateTimeImmutable $timeMax,
  ): array {
    $items = [];
    $pageToken = NULL;
    $seenPageTokens = [];

    for ($page = 0; $page < self::MAX_PAGES; $page++) {
      $result = $this->transport->bootstrapPage($timeMin, $timeMax, $pageToken);
      if ($result['token_invalid']) {
        throw new \RuntimeException('Bootstrap unexpectedly reported an invalid sync token.');
      }
      array_push($items, ...$result['items']);

      $nextPageToken = $result['next_page_token'];
      if ($nextPageToken === NULL) {
        $nextSyncToken = $result['next_sync_token'];
        if (!is_string($nextSyncToken) || $nextSyncToken === '') {
          throw new \RuntimeException('Complete bootstrap did not return nextSyncToken.');
        }
        return [
          'items' => $items,
          'next_sync_token' => $nextSyncToken,
          'token_invalid' => FALSE,
        ];
      }

      if (isset($seenPageTokens[$nextPageToken])) {
        throw new \RuntimeException('Google bootstrap pagination repeated a page token.');
      }
      $seenPageTokens[$nextPageToken] = TRUE;
      $pageToken = $nextPageToken;
    }

    throw new \RuntimeException('Google bootstrap exceeded the bounded page limit.');
  }

  /**
   * Collects every incremental page for one exact persisted cursor.
   *
   * @return array{items:array<int,array<string,mixed>>,next_sync_token:string,token_invalid:bool}
   *   Complete provider items and replacement cursor.
   */
  private function collectIncremental(string $syncToken): array {
    $items = [];
    $pageToken = NULL;
    $seenPageTokens = [];

    for ($page = 0; $page < self::MAX_PAGES; $page++) {
      $result = $this->transport->incrementalPage($syncToken, $pageToken);
      if ($result['token_invalid']) {
        return [
          'items' => [],
          'next_sync_token' => '',
          'token_invalid' => TRUE,
        ];
      }
      array_push($items, ...$result['items']);

      $nextPageToken = $result['next_page_token'];
      if ($nextPageToken === NULL) {
        $nextSyncToken = $result['next_sync_token'];
        if (!is_string($nextSyncToken) || $nextSyncToken === '') {
          throw new \RuntimeException('Complete incremental sync did not return nextSyncToken.');
        }
        return [
          'items' => $items,
          'next_sync_token' => $nextSyncToken,
          'token_invalid' => FALSE,
        ];
      }

      if (isset($seenPageTokens[$nextPageToken])) {
        throw new \RuntimeException('Google incremental pagination repeated a page token.');
      }
      $seenPageTokens[$nextPageToken] = TRUE;
      $pageToken = $nextPageToken;
    }

    throw new \RuntimeException('Google incremental sync exceeded the bounded page limit.');
  }

  /**
   * Publishes one fully collected traversal transactionally.
   *
   * @param \Drupal\personal_secretary\Entity\CalendarAccountConnection $connection
   *   Authorized current-user Google connection.
   * @param \Drupal\personal_secretary\Entity\CalendarSyncState $state
   *   Durable cursor and synchronization health state.
   * @param array<int, array<string, mixed>> $providerItems
   *   Fully collected provider items.
   * @param string $nextSyncToken
   *   Cursor returned only after the final successful page.
   * @param int $now
   *   Synchronization timestamp.
   * @param string $mode
   *   Bootstrap or incremental synchronization mode.
   * @param bool $replaceWindow
   *   Whether this traversal replaces the bounded planning window.
   * @param string $lastRebaseDate
   *   Date recorded for a successful rolling bootstrap.
   *
   * @return array<string, int|string>
   *   Sanitized publication receipt counts.
   */
  private function publish(
    CalendarAccountConnection $connection,
    CalendarSyncState $state,
    array $providerItems,
    string $nextSyncToken,
    int $now,
    string $mode,
    bool $replaceWindow,
    string $lastRebaseDate,
  ): array {
    $normalized = [];
    $eventIds = [];

    foreach ($providerItems as $providerItem) {
      $item = $this->normalize($providerItem, $now);
      $eventId = $item['provider_event_id'];
      if (isset($eventIds[$eventId])) {
        throw new \RuntimeException('One Google traversal returned a duplicate event ID.');
      }
      $eventIds[$eventId] = TRUE;
      $normalized[] = $item;
    }

    $linkedIds = $this->repository->linkedEventIds(
      $connection,
      array_keys($eventIds),
    );

    $transaction = $this->database->startTransaction();
    try {
      $created = 0;
      $updated = 0;
      $deleted = 0;
      $deduplicated = 0;

      foreach ($normalized as $item) {
        $eventId = $item['provider_event_id'];

        if (isset($linkedIds[$eventId])) {
          if ($this->repository->deactivateShadow($connection, $eventId, $now)) {
            $deleted++;
          }
          $deduplicated++;
          continue;
        }

        if ($item['provider_status'] === 'cancelled') {
          if ($this->repository->deactivateShadow($connection, $eventId, $now)) {
            $deleted++;
          }
          continue;
        }

        $action = $this->repository->upsertShadow($connection, $item);
        if ($action === 'created') {
          $created++;
        }
        else {
          $updated++;
        }
      }

      if ($replaceWindow) {
        $deleted += $this->repository->deactivateMissing(
          $connection,
          array_keys($eventIds),
          $now,
        );
      }

      $state->set('sync_token', $nextSyncToken)
        ->set('status', CalendarSyncState::CURRENT)
        ->set('last_mode', $mode)
        ->set('last_error_class', '')
        ->set('last_success_at', $now)
        ->set(
          'cursor_generation',
          (int) $state->get('cursor_generation')->value + 1,
        )
        ->set('provider_item_count', count($providerItems))
        ->set('shadow_create_count', $created)
        ->set('shadow_update_count', $updated)
        ->set('shadow_delete_count', $deleted)
        ->set('linked_deduplicated_count', $deduplicated);

      if ($replaceWindow) {
        $state->set('last_rebase_date', $lastRebaseDate);
      }
      $state->save();
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }

    return [
      'mode' => $mode,
      'provider_items' => count($providerItems),
      'created' => $created,
      'updated' => $updated,
      'deleted' => $deleted,
      'linked_deduplicated' => $deduplicated,
      'cursor_generation' => (int) $state->get('cursor_generation')->value,
    ];
  }

  /**
   * Normalizes one minimized Google event payload for local planning.
   *
   * @param array<string, mixed> $event
   *   Provider event payload from the bounded fields projection.
   * @param int $now
   *   Synchronization timestamp.
   *
   * @return array<string, mixed>
   *   Validated minimized shadow values.
   */
  private function normalize(array $event, int $now): array {
    $eventId = $this->boundedString($event['id'] ?? NULL, 1024, 'event ID', TRUE);
    $status = $this->boundedString($event['status'] ?? NULL, 32, 'event status', TRUE);
    if (!in_array($status, ['confirmed', 'tentative', 'cancelled'], TRUE)) {
      throw new \RuntimeException('Google event status is unsupported.');
    }

    if ($status === 'cancelled') {
      return [
        'provider_event_id' => $eventId,
        'provider_status' => 'cancelled',
      ];
    }

    $etag = $this->boundedString($event['etag'] ?? NULL, 255, 'event ETag', TRUE);
    if ($etag === '*') {
      throw new \RuntimeException('Google event ETag is invalid.');
    }

    $title = $this->boundedString($event['summary'] ?? '', 255, 'event title', FALSE);
    $location = $this->boundedString($event['location'] ?? '', 255, 'event location', FALSE);
    $transparency = $this->boundedString(
      $event['transparency'] ?? 'opaque',
      16,
      'event transparency',
      TRUE,
    );
    if (!in_array($transparency, ['opaque', 'transparent'], TRUE)) {
      throw new \RuntimeException('Google event transparency is unsupported.');
    }

    $updated = $this->providerDateTime($event['updated'] ?? NULL, 'updated', TRUE);
    $recurringEventId = $this->boundedString(
      $event['recurringEventId'] ?? '',
      1024,
      'recurring event ID',
      FALSE,
    );

    $originalStart = '';
    $originalStartTimezone = '';
    if (isset($event['originalStartTime'])) {
      if (!is_array($event['originalStartTime'])) {
        throw new \RuntimeException('Google originalStartTime is malformed.');
      }
      $originalStart = $this->providerStartValue($event['originalStartTime']);
      $originalStartTimezone = $this->timezoneValue(
        $event['originalStartTime']['timeZone'] ?? '',
      );
    }

    $start = $event['start'] ?? NULL;
    $end = $event['end'] ?? NULL;
    if (!is_array($start) || !is_array($end)) {
      throw new \RuntimeException('Google event requires start and end.');
    }

    $values = [
      'provider_event_id' => $eventId,
      'etag' => $etag,
      'provider_status' => $status,
      'title' => $title,
      'location' => $location,
      'transparency' => $transparency,
      'recurring_event_id' => $recurringEventId,
      'original_start_time' => $originalStart,
      'original_start_timezone' => $originalStartTimezone,
      'provider_updated' => $updated,
      'active' => TRUE,
      'last_seen_at' => $now,
    ];

    if (isset($start['date']) || isset($end['date'])) {
      $startDate = $this->civilDate($start['date'] ?? NULL, 'start date');
      $endDate = $this->civilDate($end['date'] ?? NULL, 'end date');
      if ($endDate <= $startDate) {
        throw new \RuntimeException('Google ALL_DAY event has invalid civil dates.');
      }

      return $values + [
        'time_mode' => ExternalEventShadow::TIME_MODE_ALL_DAY,
        'all_day_start' => $startDate,
        'all_day_end' => $endDate,
        'timed_start' => NULL,
        'timed_end' => NULL,
        'source_timezone' => '',
      ];
    }

    $startDateTime = $this->providerDateTime($start['dateTime'] ?? NULL, 'start dateTime', TRUE);
    $endDateTime = $this->providerDateTime($end['dateTime'] ?? NULL, 'end dateTime', TRUE);
    if ($endDateTime <= $startDateTime) {
      throw new \RuntimeException('Google TIMED event has invalid instants.');
    }

    return $values + [
      'time_mode' => ExternalEventShadow::TIME_MODE_TIMED,
      'timed_start' => $startDateTime,
      'timed_end' => $endDateTime,
      'all_day_start' => NULL,
      'all_day_end' => NULL,
      'source_timezone' => $this->timezoneValue($start['timeZone'] ?? ''),
    ];
  }

  /**
   * Normalizes recurring-instance original start semantics.
   */
  private function providerStartValue(array $value): string {
    if (isset($value['date'])) {
      return $this->civilDate($value['date'], 'original start date');
    }
    if (isset($value['dateTime'])) {
      return $this->boundedString(
        $value['dateTime'],
        255,
        'original start dateTime',
        TRUE,
      );
    }
    throw new \RuntimeException('Google originalStartTime has no supported value.');
  }

  /**
   * Normalizes one provider dateTime to Drupal UTC storage format.
   */
  private function providerDateTime(
    mixed $value,
    string $label,
    bool $required,
  ): string {
    if (($value === NULL || $value === '') && !$required) {
      return '';
    }
    $raw = $this->boundedString($value, 255, $label, $required);
    try {
      $date = new \DateTimeImmutable($raw);
    }
    catch (\Throwable $exception) {
      throw new \RuntimeException('Google ' . $label . ' is invalid.', 0, $exception);
    }
    return $date
      ->setTimezone(new \DateTimeZone('UTC'))
      ->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
  }

  /**
   * Validates one provider civil date without UTC conversion.
   */
  private function civilDate(mixed $value, string $label): string {
    $date = $this->boundedString($value, 10, $label, TRUE);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
      throw new \RuntimeException('Google ' . $label . ' is invalid.');
    }

    $parsed = \DateTimeImmutable::createFromFormat(
      '!Y-m-d',
      $date,
      new \DateTimeZone('UTC'),
    );
    $errors = \DateTimeImmutable::getLastErrors();
    if (!$parsed || ($errors !== FALSE && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
      || $parsed->format('Y-m-d') !== $date) {
      throw new \RuntimeException('Google ' . $label . ' is not a civil date.');
    }
    return $date;
  }

  /**
   * Validates an optional provider timezone identifier.
   */
  private function timezoneValue(mixed $value): string {
    $timezone = $this->boundedString($value, 64, 'timezone', FALSE);
    if ($timezone === '') {
      return '';
    }
    try {
      new \DateTimeZone($timezone);
    }
    catch (\Throwable $exception) {
      throw new \RuntimeException('Google event timezone is invalid.', 0, $exception);
    }
    return $timezone;
  }

  /**
   * Validates one minimized bounded provider string.
   */
  private function boundedString(
    mixed $value,
    int $maxLength,
    string $label,
    bool $required,
  ): string {
    if (!is_string($value)) {
      if (!$required && $value === NULL) {
        return '';
      }
      throw new \RuntimeException('Google ' . $label . ' is invalid.');
    }

    $value = trim($value);
    if (($required && $value === '') || strlen($value) > $maxLength
      || preg_match('/[\r\n]/', $value)) {
      throw new \RuntimeException('Google ' . $label . ' is invalid.');
    }
    return $value;
  }

  /**
   * Returns a sanitized synchronization failure classification.
   */
  private function failureClass(\Throwable $exception): string {
    $class = (new \ReflectionClass($exception))->getShortName();
    return preg_match('/^[A-Za-z0-9_]{1,64}$/D', $class)
      ? strtoupper($class)
      : 'UNKNOWN';
  }

}
