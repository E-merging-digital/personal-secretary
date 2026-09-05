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
 * Builds derived current-user preparation read models from current domain truth.
 */
final class CurrentUserPreparationService {

  private const DEFAULT_WINDOW_DAYS = 7;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly HouseholdAuthorizationService $householdAuthorization,
    private readonly CurrentPersonResolver $currentPersonResolver,
    private readonly EffectiveOccurrenceProjectionService $effectiveOccurrences,
    private readonly EffectiveResponsibilityService $effectiveResponsibility,
    private readonly PreparationEligibilityService $preparationEligibility,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns active overdue preparations plus preparations due in the next 7 days.
   *
   * @return array{
   *   timezone:string,
   *   due_window_start:string,
   *   due_window_end:string,
   *   max_lead_time_seconds:int,
   *   occurrence_projection_end:string,
   *   items:array<int, array<string, mixed>>
   * }
   */
  public function mine(?DateTimeImmutable $nowUtc = NULL): array {
    $nowUtc = $nowUtc === NULL ? $this->nowUtc() : $this->utc($nowUtc);
    $windowEnd = $nowUtc->modify('+' . self::DEFAULT_WINDOW_DAYS . ' days');

    return $this->readActiveDueBefore($nowUtc, $nowUtc, $windowEnd);
  }

  /**
   * Returns active overdue preparations plus preparations due during local Today.
   *
   * @return array{
   *   timezone:string,
   *   due_window_start:string,
   *   due_window_end:string,
   *   max_lead_time_seconds:int,
   *   occurrence_projection_end:string,
   *   items:array<int, array<string, mixed>>
   * }
   */
  public function today(
    DateTimeImmutable $nowUtc,
    DateTimeImmutable $todayStartUtc,
    DateTimeImmutable $todayEndUtc,
  ): array {
    $nowUtc = $this->utc($nowUtc);
    $todayStartUtc = $this->utc($todayStartUtc);
    $todayEndUtc = $this->utc($todayEndUtc);
    if ($todayEndUtc <= $todayStartUtc) {
      throw new InvalidArgumentException('Today preparation read requires a complete civil-day UTC interval.');
    }
    if ($nowUtc < $todayStartUtc || $nowUtc >= $todayEndUtc) {
      throw new InvalidArgumentException('Today preparation read requires now inside the supplied Today interval.');
    }

    return $this->readActiveDueBefore($nowUtc, $todayStartUtc, $todayEndUtc);
  }

  /**
   * @return array{
   *   timezone:string,
   *   due_window_start:string,
   *   due_window_end:string,
   *   max_lead_time_seconds:int,
   *   occurrence_projection_end:string,
   *   items:array<int, array<string, mixed>>
   * }
   */
  private function readActiveDueBefore(
    DateTimeImmutable $nowUtc,
    DateTimeImmutable $dueWindowStartUtc,
    DateTimeImmutable $dueWindowEndUtc,
  ): array {
    $nowUtc = $this->utc($nowUtc);
    $dueWindowStartUtc = $this->utc($dueWindowStartUtc);
    $dueWindowEndUtc = $this->utc($dueWindowEndUtc);
    if ($dueWindowEndUtc <= $nowUtc || $dueWindowStartUtc > $nowUtc) {
      throw new InvalidArgumentException('Preparation due window must contain the current instant and end in the future.');
    }

    $user = $this->currentPersistedUser();
    $authorizedHouseholdIds = $this->normalizeHouseholdIds(
      $this->householdAuthorization->authorizedHouseholdIds($user),
    );
    if ($authorizedHouseholdIds === []) {
      throw new InvalidArgumentException('Current-user preparations require at least one authorized Household.');
    }
    $person = $this->currentPersonResolver->resolve($user);
    $personId = (int) $person->id();
    $personUuid = $person->uuid();
    if ($personId <= 0 || $personUuid === '') {
      throw new InvalidArgumentException('Current-user preparations require a valid CurrentPerson.');
    }

    $displayTimezoneId = $this->timezoneId($user);
    $displayTimezone = new DateTimeZone($displayTimezoneId);

    $seriesStorage = $this->entityTypeManager->getStorage('personal_sec_activity_series');
    $seriesIds = $seriesStorage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('household', $authorizedHouseholdIds, 'IN')
      ->execute();
    $seriesIds = array_map('intval', array_values($seriesIds));
    sort($seriesIds, SORT_NUMERIC);

    $baseModel = [
      'timezone' => $displayTimezoneId,
      'due_window_start' => $dueWindowStartUtc->format(DateTimeInterface::ATOM),
      'due_window_end' => $dueWindowEndUtc->format(DateTimeInterface::ATOM),
      'max_lead_time_seconds' => 0,
      'occurrence_projection_end' => $dueWindowEndUtc->format(DateTimeInterface::ATOM),
      'items' => [],
    ];
    if ($seriesIds === []) {
      return $baseModel;
    }

    $seriesEntities = $seriesStorage->loadMultiple($seriesIds);
    foreach ($seriesIds as $seriesId) {
      $series = $seriesEntities[$seriesId] ?? NULL;
      if (!$series instanceof ActivitySeries) {
        throw new RuntimeException('Current-user preparation ActivitySeries query returned an unexpected entity type.');
      }
      $householdId = (int) ($series->get('household')->target_id ?? 0);
      if (!in_array($householdId, $authorizedHouseholdIds, TRUE)) {
        throw new RuntimeException('Current-user preparation read crossed its authorized Household boundary.');
      }
    }

    $maximumLeadTimeSeconds = $this->preparationEligibility
      ->maximumLeadTimeSecondsForSeriesIds($seriesIds);
    if ($maximumLeadTimeSeconds === NULL) {
      return $baseModel;
    }

    $projectionEndUtc = $dueWindowEndUtc->modify(sprintf('+%d seconds', $maximumLeadTimeSeconds));
    $items = [];

    foreach ($seriesIds as $seriesId) {
      $series = $seriesEntities[$seriesId];
      $activityLabel = trim((string) $series->label());
      if ($activityLabel === '') {
        throw new RuntimeException('Current-user preparation ActivitySeries has no presentation label.');
      }

      foreach ($this->effectiveOccurrences->project($series, $nowUtc, $projectionEndUtc) as $occurrence) {
        $effectiveStartUtc = $this->utc(new DateTimeImmutable($occurrence->effectiveUtcStart));
        if ($effectiveStartUtc <= $nowUtc) {
          continue;
        }

        $responsibility = $this->effectiveResponsibility->resolve($series, $occurrence);
        if (
          $responsibility->state !== EffectiveResponsibility::STATE_ASSIGNED
          || $responsibility->responsiblePersonId !== $personId
          || $responsibility->responsiblePersonUuid !== $personUuid
        ) {
          continue;
        }

        foreach ($this->preparationEligibility->deriveForResponsibility($series, $occurrence, $responsibility) as $preparation) {
          $dueAtUtc = $this->utc(new DateTimeImmutable($preparation->dueAtUtc));
          if ($dueAtUtc >= $dueWindowEndUtc) {
            continue;
          }

          $dueLocal = $dueAtUtc->setTimezone($displayTimezone);
          $startLocal = $effectiveStartUtc->setTimezone($displayTimezone);
          $occurrenceIdentity = implode('|', [
            $preparation->seriesUuid,
            $preparation->seriesRevisionId,
            $preparation->originalOccurrenceKey,
          ]);

          $items[] = [
            'sort_due' => $dueAtUtc->format(DateTimeInterface::ATOM),
            'sort_occurrence' => $occurrenceIdentity,
            'sort_requirement' => $preparation->requirementId,
            'instruction' => $preparation->requirementLabel,
            'due_time' => $dueLocal->format('Y-m-d H:i'),
            'due_time_iso' => $dueLocal->format(DateTimeInterface::ATOM),
            'overdue' => $dueAtUtc < $nowUtc,
            'activity_label' => $activityLabel,
            'activity_start' => $startLocal->format('Y-m-d H:i'),
            'activity_start_iso' => $startLocal->format(DateTimeInterface::ATOM),
            'display_timezone' => $displayTimezoneId,
          ];
        }
      }
    }

    usort(
      $items,
      static fn(array $left, array $right): int =>
        [$left['sort_due'], $left['sort_occurrence'], $left['sort_requirement']]
        <=>
        [$right['sort_due'], $right['sort_occurrence'], $right['sort_requirement']],
    );

    $items = array_map(
      static function (array $item): array {
        unset($item['sort_due'], $item['sort_occurrence'], $item['sort_requirement']);
        return $item;
      },
      $items,
    );

    return [
      'timezone' => $displayTimezoneId,
      'due_window_start' => $dueWindowStartUtc->format(DateTimeInterface::ATOM),
      'due_window_end' => $dueWindowEndUtc->format(DateTimeInterface::ATOM),
      'max_lead_time_seconds' => $maximumLeadTimeSeconds,
      'occurrence_projection_end' => $projectionEndUtc->format(DateTimeInterface::ATOM),
      'items' => $items,
    ];
  }

  private function currentPersistedUser(): UserInterface {
    if ($this->currentUser->isAnonymous() || (int) $this->currentUser->id() <= 0) {
      throw new InvalidArgumentException('Current-user preparations require an authenticated Drupal User.');
    }

    $user = $this->entityTypeManager
      ->getStorage('user')
      ->load((int) $this->currentUser->id());
    if (!$user instanceof UserInterface || !$user->isActive()) {
      throw new InvalidArgumentException('Current-user preparations require an active persisted Drupal User.');
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
        throw new InvalidArgumentException('Current-user preparation Household IDs must be positive integers.');
      }
      $normalized[$value] = $value;
    }

    $normalized = array_values($normalized);
    sort($normalized, SORT_NUMERIC);
    return $normalized;
  }

  private function nowUtc(): DateTimeImmutable {
    return (new DateTimeImmutable('@' . $this->time->getCurrentTime()))
      ->setTimezone(new DateTimeZone('UTC'));
  }

  private function utc(DateTimeImmutable $value): DateTimeImmutable {
    return $value->setTimezone(new DateTimeZone('UTC'));
  }

}
