<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\personal_secretary\Entity\CalendarAccountConnection;
use Drupal\personal_secretary\Entity\CalendarSyncState;
use Drupal\personal_secretary\Entity\ExternalEventShadow;

/**
 * Read-only unified-planning adapter for current-user external shadows.
 */
final class ExternalPlanningQueryService {

  public function __construct(
    private readonly GoogleCalendarConnectionService $connections,
    private readonly ExternalPlanningRepository $repository,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns current-user external planning items intersecting one local day.
   *
   * @return array<int, array<string, mixed>>
   *   Normalized external planning presentation items.
   */
  public function today(
    \DateTimeImmutable $utcStart,
    \DateTimeImmutable $utcEnd,
    string $localDate,
    string $displayTimezoneId,
  ): array {
    $connection = $this->eligibleConnection();
    if ($connection === NULL) {
      return [];
    }

    $displayTimezone = new \DateTimeZone($displayTimezoneId);
    $utc = new \DateTimeZone('UTC');
    $items = [];

    foreach ($this->repository->activeShadows($connection) as $shadow) {
      $mode = (string) $shadow->get('time_mode')->value;
      $status = (string) $shadow->get('provider_status')->value;
      $transparency = (string) $shadow->get('transparency')->value;
      if ($status === 'cancelled') {
        continue;
      }

      if ($mode === ExternalEventShadow::TIME_MODE_ALL_DAY) {
        $startDate = (string) $shadow->get('all_day_start')->value;
        $exclusiveEnd = (string) $shadow->get('all_day_end')->value;
        if ($startDate === '' || $exclusiveEnd === ''
          || !($startDate <= $localDate && $localDate < $exclusiveEnd)) {
          continue;
        }

        $displayEnd = (new \DateTimeImmutable(
          $exclusiveEnd . ' 00:00:00',
          $displayTimezone,
        ))->modify('-1 day')->format('Y-m-d');

        $items[] = [
          'sort_start' => $localDate . 'T00:00:00',
          'planning_key' => 'external:' . $shadow->uuid(),
          'title' => (string) $shadow->get('title')->value,
          'location' => (string) $shadow->get('location')->value,
          'all_day' => TRUE,
          'all_day_start_date' => $startDate,
          'all_day_end_date' => $displayEnd,
          'effective_start' => '',
          'effective_end' => '',
          'effective_start_iso' => '',
          'effective_end_iso' => '',
          'display_timezone' => $displayTimezoneId,
          'source_timezone' => '',
          'provider' => (string) $shadow->get('provider')->value,
          'source_label' => 'Google Calendar',
          'editability' => 'EXTERNAL_READ_ONLY',
          'busy_impact' => $this->busyImpact($status, $transparency),
        ];
        continue;
      }

      if ($mode !== ExternalEventShadow::TIME_MODE_TIMED) {
        continue;
      }

      $startValue = (string) $shadow->get('timed_start')->value;
      $endValue = (string) $shadow->get('timed_end')->value;
      if ($startValue === '' || $endValue === '') {
        continue;
      }
      $startUtc = \DateTimeImmutable::createFromFormat(
        '!' . DateTimeItemInterface::DATETIME_STORAGE_FORMAT,
        $startValue,
        $utc,
      );
      $endUtc = \DateTimeImmutable::createFromFormat(
        '!' . DateTimeItemInterface::DATETIME_STORAGE_FORMAT,
        $endValue,
        $utc,
      );
      if (!$startUtc || !$endUtc || !($startUtc < $utcEnd && $endUtc > $utcStart)) {
        continue;
      }

      $startLocal = $startUtc->setTimezone($displayTimezone);
      $endLocal = $endUtc->setTimezone($displayTimezone);
      $items[] = [
        'sort_start' => $startUtc->format(\DateTimeInterface::ATOM),
        'planning_key' => 'external:' . $shadow->uuid(),
        'title' => (string) $shadow->get('title')->value,
        'location' => (string) $shadow->get('location')->value,
        'all_day' => FALSE,
        'all_day_start_date' => '',
        'all_day_end_date' => '',
        'effective_start' => $startLocal->format('Y-m-d H:i'),
        'effective_end' => $endLocal->format('Y-m-d H:i'),
        'effective_start_iso' => $startLocal->format(\DateTimeInterface::ATOM),
        'effective_end_iso' => $endLocal->format(\DateTimeInterface::ATOM),
        'display_timezone' => $displayTimezoneId,
        'source_timezone' => (string) $shadow->get('source_timezone')->value,
        'provider' => (string) $shadow->get('provider')->value,
        'source_label' => 'Google Calendar',
        'editability' => 'EXTERNAL_READ_ONLY',
        'busy_impact' => $this->busyImpact($status, $transparency),
      ];
    }

    usort(
      $items,
      static fn(array $left, array $right): int =>
        [$left['sort_start'], $left['planning_key']]
        <=>
        [$right['sort_start'], $right['planning_key']],
    );

    return array_map(
      static function (array $item): array {
        unset($item['sort_start']);
        return $item;
      },
      $items,
    );
  }

  /**
   * Returns sanitized synchronization health for the current user.
   *
   * @return array{status:string,last_success_at:int,last_attempt_at:int,existing_grant:bool}
   *   Synchronization health without provider payload data.
   */
  public function syncStatus(): array {
    $connection = $this->eligibleConnection();
    if ($connection === NULL) {
      return [
        'status' => CalendarSyncState::NOT_SYNCED,
        'last_success_at' => 0,
        'last_attempt_at' => 0,
        'existing_grant' => FALSE,
      ];
    }

    $state = $this->repository->syncState($connection, FALSE);
    if (!$state instanceof CalendarSyncState) {
      return [
        'status' => CalendarSyncState::NOT_SYNCED,
        'last_success_at' => 0,
        'last_attempt_at' => 0,
        'existing_grant' => TRUE,
      ];
    }

    $status = (string) $state->get('status')->value;
    $lastSuccess = (int) ($state->get('last_success_at')->value ?? 0);
    if ($status === CalendarSyncState::CURRENT
      && ($lastSuccess <= 0 || $lastSuccess < $this->time->getCurrentTime() - 1800)) {
      $status = CalendarSyncState::STALE;
    }

    return [
      'status' => $status,
      'last_success_at' => $lastSuccess,
      'last_attempt_at' => (int) ($state->get('last_attempt_at')->value ?? 0),
      'existing_grant' => TRUE,
    ];
  }

  /**
   * Resolves the connected Google account eligible for owned-event reads.
   */
  private function eligibleConnection(): ?CalendarAccountConnection {
    $connection = $this->connections->currentConnection();
    return $connection instanceof CalendarAccountConnection
      && $connection->get('status')->value === CalendarAccountConnection::STATUS_CONNECTED
      && $connection->hasWriteScope()
        ? $connection
        : NULL;
  }

  /**
   * Maps provider status/transparency to planning impact.
   */
  private function busyImpact(string $status, string $transparency): string {
    if ($transparency === 'transparent') {
      return 'INFORMATIONAL';
    }
    return $status === 'tentative' ? 'TENTATIVE_BUSY' : 'BUSY';
  }

}
