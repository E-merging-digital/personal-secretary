<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Value\EffectiveResponsibility;
use Drupal\user\UserInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Composes the derived current-user Today read model.
 */
final class TodayService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly HouseholdAuthorizationService $householdAuthorization,
    private readonly CurrentPersonResolver $currentPersonResolver,
    private readonly PersonalTaskQueryService $personalTaskQuery,
    private readonly CurrentUserPreparationService $currentUserPreparations,
    private readonly EffectiveOccurrenceProjectionService $effectiveOccurrences,
    private readonly EffectiveResponsibilityService $effectiveResponsibility,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * @return array{
   *   timezone:string,
   *   local_date:string,
   *   local_start:string,
   *   local_end:string,
   *   utc_start:string,
   *   utc_end:string,
   *   tasks:array<int, array<string, mixed>>,
   *   preparations:array<int, array<string, mixed>>,
   *   activities:array<int, array<string, mixed>>
   * }
   */
  public function today(): array {
    $user = $this->currentPersistedUser();
    $authorizedHouseholdIds = $this->householdAuthorization->authorizedHouseholdIds($user);
    if ($authorizedHouseholdIds === []) {
      throw new InvalidArgumentException('Today requires at least one authorized Household.');
    }
    $person = $this->currentPersonResolver->resolve($user);

    $nowUtc = (new DateTimeImmutable('@' . $this->time->getCurrentTime()))
      ->setTimezone(new DateTimeZone('UTC'));
    $window = $this->windowFor($user, $nowUtc);

    $tasks = $this->personalTaskQuery->todayOpenTasks(
      $nowUtc,
      $window['utc_end'],
    );

    $preparations = $this->currentUserPreparations->today(
      $nowUtc,
      $window['utc_start'],
      $window['utc_end'],
    )['items'];

    $activities = $this->activities(
      $authorizedHouseholdIds,
      (int) $person->id(),
      $person->uuid(),
      $window['utc_start'],
      $window['utc_end'],
      $window['timezone'],
    );

    return [
      'timezone' => $window['timezone'],
      'local_date' => $window['local_date'],
      'local_start' => $window['local_start']->format(DateTimeInterface::ATOM),
      'local_end' => $window['local_end']->format(DateTimeInterface::ATOM),
      'utc_start' => $window['utc_start']->format(DateTimeInterface::ATOM),
      'utc_end' => $window['utc_end']->format(DateTimeInterface::ATOM),
      'tasks' => $tasks,
      'preparations' => $preparations,
      'activities' => $activities,
    ];
  }

  /**
   * Calculates the viewer's civil Today interval without fixed-duration math.
   *
   * @return array{
   *   timezone:string,
   *   local_date:string,
   *   local_start:\DateTimeImmutable,
   *   local_end:\DateTimeImmutable,
   *   utc_start:\DateTimeImmutable,
   *   utc_end:\DateTimeImmutable
   * }
   */
  public function windowFor(UserInterface $user, DateTimeImmutable $nowUtc): array {
    $timezone = new DateTimeZone($this->timezoneId($user));
    $nowUtc = $nowUtc->setTimezone(new DateTimeZone('UTC'));
    $localNow = $nowUtc->setTimezone($timezone);
    $localStart = $localNow->setTime(0, 0, 0);
    $localEnd = $localStart->modify('+1 day');

    return [
      'timezone' => $timezone->getName(),
      'local_date' => $localStart->format('Y-m-d'),
      'local_start' => $localStart,
      'local_end' => $localEnd,
      'utc_start' => $localStart->setTimezone(new DateTimeZone('UTC')),
      'utc_end' => $localEnd->setTimezone(new DateTimeZone('UTC')),
    ];
  }

  /**
   * @param int[] $authorizedHouseholdIds
   *
   * @return array<int, array<string, mixed>>
   */
  private function activities(
    array $authorizedHouseholdIds,
    int $currentPersonId,
    string $currentPersonUuid,
    DateTimeImmutable $todayStartUtc,
    DateTimeImmutable $todayEndUtc,
    string $displayTimezoneId,
  ): array {
    $authorizedHouseholdIds = $this->normalizeHouseholdIds($authorizedHouseholdIds);
    if ($authorizedHouseholdIds === [] || $currentPersonId <= 0 || $currentPersonUuid === '') {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('personal_sec_activity_series');
    $seriesIds = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('household', $authorizedHouseholdIds, 'IN')
      ->execute();
    if ($seriesIds === []) {
      return [];
    }

    $seriesEntities = $storage->loadMultiple($seriesIds);
    $displayTimezone = new DateTimeZone($displayTimezoneId);
    $items = [];

    foreach ($seriesIds as $seriesId) {
      $series = $seriesEntities[$seriesId] ?? NULL;
      if (!$series instanceof ActivitySeries) {
        throw new RuntimeException('Today ActivitySeries query returned an unexpected entity type.');
      }

      $householdId = (int) ($series->get('household')->target_id ?? 0);
      if (!in_array($householdId, $authorizedHouseholdIds, TRUE)) {
        throw new RuntimeException('Today activity read crossed its authorized Household boundary.');
      }

      foreach ($this->effectiveOccurrences->projectOverlapping($series, $todayStartUtc, $todayEndUtc) as $occurrence) {
        $responsibility = $this->effectiveResponsibility->resolve($series, $occurrence);
        if (
          $responsibility->state !== EffectiveResponsibility::STATE_ASSIGNED
          || $responsibility->responsiblePersonId !== $currentPersonId
          || $responsibility->responsiblePersonUuid !== $currentPersonUuid
        ) {
          continue;
        }

        $effectiveStartUtc = (new DateTimeImmutable($occurrence->effectiveUtcStart))
          ->setTimezone(new DateTimeZone('UTC'));
        $effectiveEndUtc = (new DateTimeImmutable($occurrence->effectiveUtcEnd))
          ->setTimezone(new DateTimeZone('UTC'));
        if (!($effectiveStartUtc < $todayEndUtc && $effectiveEndUtc > $todayStartUtc)) {
          throw new RuntimeException('Today overlap projection returned a non-overlapping occurrence.');
        }

        $startLocal = $effectiveStartUtc->setTimezone($displayTimezone);
        $endLocal = $effectiveEndUtc->setTimezone($displayTimezone);
        $label = trim((string) $series->label());
        if ($label === '') {
          throw new RuntimeException('Today ActivitySeries has no presentation label.');
        }

        $items[] = [
          'sort_start' => $effectiveStartUtc->format(DateTimeInterface::ATOM),
          'sort_revision' => $occurrence->seriesRevisionId,
          'sort_occurrence' => $occurrence->originalOccurrenceKey,
          'activity_label' => $label,
          'effective_start' => $startLocal->format('Y-m-d H:i'),
          'effective_end' => $endLocal->format('Y-m-d H:i'),
          'effective_start_iso' => $startLocal->format(DateTimeInterface::ATOM),
          'effective_end_iso' => $endLocal->format(DateTimeInterface::ATOM),
          'display_timezone' => $displayTimezoneId,
          'source_timezone' => $occurrence->sourceTimezone,
        ];
      }
    }

    usort(
      $items,
      static fn(array $left, array $right): int =>
        [$left['sort_start'], $left['sort_revision'], $left['sort_occurrence']]
        <=>
        [$right['sort_start'], $right['sort_revision'], $right['sort_occurrence']],
    );

    return array_map(
      static function (array $item): array {
        unset($item['sort_start'], $item['sort_revision'], $item['sort_occurrence']);
        return $item;
      },
      $items,
    );
  }

  private function currentPersistedUser(): UserInterface {
    if ($this->currentUser->isAnonymous() || (int) $this->currentUser->id() <= 0) {
      throw new InvalidArgumentException('Today requires an authenticated Drupal User.');
    }

    $user = $this->entityTypeManager
      ->getStorage('user')
      ->load((int) $this->currentUser->id());
    if (!$user instanceof UserInterface || !$user->isActive()) {
      throw new InvalidArgumentException('Today requires an active persisted Drupal User.');
    }
    return $user;
  }

  private function timezoneId(UserInterface $user): string {
    $timezone = trim((string) $user->getTimeZone());
    if ($timezone !== '') {
      return $timezone;
    }

    $fallback = trim((string) $this->configFactory->get('system.date')->get('timezone.default'));
    return $fallback !== '' ? $fallback : 'UTC';
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
        throw new InvalidArgumentException('Today Household IDs must be positive integers.');
      }
      $normalized[$value] = $value;
    }

    $normalized = array_values($normalized);
    sort($normalized, SORT_NUMERIC);
    return $normalized;
  }

}
