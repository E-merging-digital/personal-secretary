<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\PreparationRequirement;
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
    private readonly PreparationCompletionService $preparationCompletion,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public function mine(?DateTimeImmutable $nowUtc = NULL): array {
    return $this->mineForUser($this->currentPersistedUser(), $nowUtc);
  }

  /**
   * Account-parameterized equivalent of mine() for background product work.
   */
  public function mineForUser(UserInterface $user, ?DateTimeImmutable $nowUtc = NULL): array {
    $user = $this->persistedActiveUser($user);
    $nowUtc = $nowUtc === NULL ? $this->nowUtc() : $this->utc($nowUtc);
    $windowEnd = $nowUtc->modify('+' . self::DEFAULT_WINDOW_DAYS . ' days');

    return $this->readActiveDueBefore($user, $nowUtc, $nowUtc, $windowEnd);
  }

  public function today(
    DateTimeImmutable $nowUtc,
    DateTimeImmutable $todayStartUtc,
    DateTimeImmutable $todayEndUtc,
  ): array {
    return $this->todayForUser(
      $this->currentPersistedUser(),
      $nowUtc,
      $todayStartUtc,
      $todayEndUtc,
    );
  }

  /**
   * Account-parameterized equivalent of today() for shared deterministic truth.
   */
  public function todayForUser(
    UserInterface $user,
    DateTimeImmutable $nowUtc,
    DateTimeImmutable $todayStartUtc,
    DateTimeImmutable $todayEndUtc,
  ): array {
    $user = $this->persistedActiveUser($user);
    $nowUtc = $this->utc($nowUtc);
    $todayStartUtc = $this->utc($todayStartUtc);
    $todayEndUtc = $this->utc($todayEndUtc);
    if ($todayEndUtc <= $todayStartUtc) {
      throw new InvalidArgumentException('Today preparation read requires a complete civil-day UTC interval.');
    }
    if ($nowUtc < $todayStartUtc || $nowUtc >= $todayEndUtc) {
      throw new InvalidArgumentException('Today preparation read requires now inside the supplied Today interval.');
    }

    return $this->readActiveDueBefore($user, $nowUtc, $todayStartUtc, $todayEndUtc);
  }

  private function readActiveDueBefore(
    UserInterface $user,
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
    $seriesIds = $seriesStorage->getQuery()
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
    $requirementStorage = $this->entityTypeManager->getStorage('personal_sec_prep_req');

    foreach ($seriesIds as $seriesId) {
      $series = $seriesEntities[$seriesId];
      $activityLabel = trim((string) $series->label());
      if ($activityLabel === '') {
        throw new RuntimeException('Current-user preparation ActivitySeries has no presentation label.');
      }
      $allDay = $series->timeMode() === ActivitySeries::TIME_MODE_ALL_DAY;

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
          $requirement = $requirementStorage->load($preparation->requirementId);
          if (!$requirement instanceof PreparationRequirement || $requirement->uuid() === '') {
            throw new RuntimeException('Derived preparation requirement has no stable persisted identity.');
          }

          $dueLocal = $dueAtUtc->setTimezone($displayTimezone);
          $startLocal = $effectiveStartUtc->setTimezone($displayTimezone);
          $occurrenceIdentity = implode('|', [
            $preparation->seriesUuid,
            $preparation->seriesRevisionId,
            $preparation->originalOccurrenceKey,
          ]);
          [$allDayStartDate, $allDayEndDate] = $allDay
            ? $this->allDayDates($occurrence->effectiveSourceLocalStart, $occurrence->effectiveSourceLocalEnd)
            : ['', ''];

          $items[] = [
            'sort_due' => $dueAtUtc->format(DateTimeInterface::ATOM),
            'sort_occurrence' => $occurrenceIdentity,
            'sort_requirement' => $preparation->requirementId,
            'instruction' => $preparation->requirementLabel,
            'due_time' => $this->localizedDateTime($dueLocal, $displayTimezoneId),
            'due_time_iso' => $dueLocal->format(DateTimeInterface::ATOM),
            'overdue' => $dueAtUtc < $nowUtc,
            'activity_label' => $activityLabel,
            'all_day' => $allDay,
            'all_day_start_date' => $allDayStartDate,
            'all_day_end_date' => $allDayEndDate,
            'all_day_start_label' => $allDay
              ? $this->localizedCivilDate($allDayStartDate, $occurrence->sourceTimezone)
              : '',
            'all_day_end_label' => $allDay
              ? $this->localizedCivilDate($allDayEndDate, $occurrence->sourceTimezone)
              : '',
            'activity_start' => $allDay
              ? ''
              : $this->localizedDateTime($startLocal, $displayTimezoneId),
            'activity_start_iso' => $startLocal->format(DateTimeInterface::ATOM),
            'display_timezone' => $displayTimezoneId,
            '_completion_series_id' => $seriesId,
            '_completion_target_revision_id' => (int) $preparation->seriesRevisionId,
            '_completion_original_occurrence_key' => $preparation->originalOccurrenceKey,
            '_completion_requirement_id' => $preparation->requirementId,
            '_completion_responsible_person_id' => $preparation->responsiblePersonId,
            '_reminder_series_uuid' => $preparation->seriesUuid,
            '_reminder_requirement_uuid' => $requirement->uuid(),
            '_reminder_due_at_utc' => $dueAtUtc->format(DateTimeInterface::ATOM),
            '_reminder_effective_start_utc' => $effectiveStartUtc->format(DateTimeInterface::ATOM),
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
    $items = $this->preparationCompletion->overlayCandidates($items);

    return [
      'timezone' => $displayTimezoneId,
      'due_window_start' => $dueWindowStartUtc->format(DateTimeInterface::ATOM),
      'due_window_end' => $dueWindowEndUtc->format(DateTimeInterface::ATOM),
      'max_lead_time_seconds' => $maximumLeadTimeSeconds,
      'occurrence_projection_end' => $projectionEndUtc->format(DateTimeInterface::ATOM),
      'items' => $items,
    ];
  }

  /**
   * Formats a localized preparation date and time.
   *
   * @param \DateTimeImmutable $value
   *   Date and time value to format.
   * @param string $timezoneId
   *   Display timezone identifier.
   *
   * @return string
   *   Localized date and time label.
   */
  private function localizedDateTime(DateTimeImmutable $value, string $timezoneId): string {
    return $this->dateFormatter->format(
      $value->getTimestamp(),
      'custom',
      'j M Y, H:i',
      $timezoneId,
    );
  }

  /**
   * Formats a localized preparation date.
   *
   * @param \DateTimeImmutable $value
   *   Date value to format.
   * @param string $timezoneId
   *   Display timezone identifier.
   *
   * @return string
   *   Localized date label.
   */
  private function localizedDate(DateTimeImmutable $value, string $timezoneId): string {
    return $this->dateFormatter->format(
      $value->getTimestamp(),
      'custom',
      'j M Y',
      $timezoneId,
    );
  }

  /**
   * Formats one source-local civil date without inventing a clock time.
   *
   * @param string $date
   *   Civil date in Y-m-d format.
   * @param string $timezoneId
   *   Source timezone identifier.
   *
   * @return string
   *   Localized civil-date label.
   */
  private function localizedCivilDate(string $date, string $timezoneId): string {
    $timezone = new DateTimeZone($timezoneId);
    $value = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' 12:00', $timezone);
    if (!$value instanceof DateTimeImmutable || $value->format('Y-m-d') !== $date) {
      throw new RuntimeException('ALL_DAY preparation presentation received an invalid civil date.');
    }
    return $this->localizedDate($value, $timezoneId);
  }

  /**
   * Derives inclusive civil dates for an all-day occurrence.
   *
   * @param string $sourceLocalStart
   *   Source-local occurrence start.
   * @param string $sourceLocalEnd
   *   Source-local exclusive occurrence end.
   *
   * @return array{0:string,1:string}
   *   Inclusive start and end civil dates.
   */
  private function allDayDates(string $sourceLocalStart, string $sourceLocalEnd): array {
    $start = new DateTimeImmutable($sourceLocalStart);
    $end = new DateTimeImmutable($sourceLocalEnd);
    if (
      $start->format('H:i:s') !== '00:00:00'
      || $end->format('H:i:s') !== '00:00:00'
      || $end <= $start
    ) {
      throw new RuntimeException('ALL_DAY preparation presentation requires positive source-local midnight boundaries.');
    }

    return [
      $start->format('Y-m-d'),
      $end->modify('-1 day')->format('Y-m-d'),
    ];
  }

  private function currentPersistedUser(): UserInterface {
    if ($this->currentUser->isAnonymous() || (int) $this->currentUser->id() <= 0) {
      throw new InvalidArgumentException('Current-user preparations require an authenticated Drupal User.');
    }
    $user = $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser->id());
    if (!$user instanceof UserInterface || !$user->isActive()) {
      throw new InvalidArgumentException('Current-user preparations require an active persisted Drupal User.');
    }
    return $user;
  }

  private function persistedActiveUser(UserInterface $user): UserInterface {
    $uid = (int) $user->id();
    if ($uid <= 0) {
      throw new InvalidArgumentException('Account-parameterized preparations require a persisted Drupal User.');
    }
    $persisted = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$persisted instanceof UserInterface || !$persisted->isActive()) {
      throw new InvalidArgumentException('Account-parameterized preparations require an active persisted Drupal User.');
    }
    return $persisted;
  }

  private function timezoneId(UserInterface $user): string {
    $timezone = trim((string) $user->getTimeZone());
    if ($timezone !== '') {
      return $timezone;
    }
    $fallback = trim((string) $this->configFactory->get('system.date')->get('timezone.default'));
    return $fallback !== '' ? $fallback : 'UTC';
  }

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
    return (new DateTimeImmutable('@' . $this->time->getCurrentTime()))->setTimezone(new DateTimeZone('UTC'));
  }

  private function utc(DateTimeImmutable $value): DateTimeImmutable {
    return $value->setTimezone(new DateTimeZone('UTC'));
  }

}
