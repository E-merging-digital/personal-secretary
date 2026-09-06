<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\Person;
use Drupal\personal_secretary\Value\EffectiveResponsibility;
use InvalidArgumentException;
use RuntimeException;

/**
 * Builds read-only upcoming activity presentation data from domain truth.
 */
final class UpcomingActivityService {

  private const DEFAULT_WINDOW_DAYS = 7;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EffectiveOccurrenceProjectionService $effectiveOccurrences,
    private readonly EffectiveResponsibilityService $effectiveResponsibility,
    private readonly PreparationEligibilityService $preparationEligibility,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns the default Household-wide upcoming presentation window.
   *
   * @return array<int, array{
   *   activity_label: string,
   *   location: string,
   *   concerned_person_labels: string[],
   *   all_day: bool,
   *   all_day_start_date: string,
   *   all_day_end_date: string,
   *   effective_start: string,
   *   effective_end: string,
   *   effective_start_iso: string,
   *   effective_end_iso: string,
   *   source_timezone: string,
   *   responsibility_label: string,
   *   preparations: array<int, array{instruction: string, due_time: string, due_time_iso: string}>,
   *   schedule_target: array{series_id: int},
   *   responsibility_target: array{series_id: int, original_occurrence_key: string},
   *   cancel_target: ?array{series_id: int, original_occurrence_key: string}
   * }>
   */
  public function upcoming(): array {
    [$windowStart, $windowEnd] = $this->defaultWindow();

    return $this->aggregateInternal($windowStart, $windowEnd, NULL, NULL);
  }

  /**
   * Returns the default window filtered by effective responsibility for Person.
   *
   * This privileged-compatible path preserves the pre-#101 all-Household
   * behavior. Ordinary product reads must use upcomingForPersonInHouseholds().
   *
   * @return array<int, array{
   *   activity_label: string,
   *   location: string,
   *   concerned_person_labels: string[],
   *   all_day: bool,
   *   all_day_start_date: string,
   *   all_day_end_date: string,
   *   effective_start: string,
   *   effective_end: string,
   *   effective_start_iso: string,
   *   effective_end_iso: string,
   *   source_timezone: string,
   *   responsibility_label: string,
   *   preparations: array<int, array{instruction: string, due_time: string, due_time_iso: string}>,
   *   schedule_target: array{series_id: int},
   *   responsibility_target: array{series_id: int, original_occurrence_key: string},
   *   cancel_target: ?array{series_id: int, original_occurrence_key: string}
   * }>
   */
  public function upcomingForPerson(Person $person): array {
    $personId = $this->requirePersistedPersonId($person);
    [$windowStart, $windowEnd] = $this->defaultWindow();

    return $this->aggregateInternal(
      $windowStart,
      $windowEnd,
      $personId,
      NULL,
    );
  }

  /**
   * Returns personalized upcoming data after restricting the Household scope.
   *
   * @param int[] $householdIds
   *   Exact already-authorized Household IDs. An empty scope returns no data.
   *
   * @return array<int, array{
   *   activity_label: string,
   *   location: string,
   *   concerned_person_labels: string[],
   *   all_day: bool,
   *   all_day_start_date: string,
   *   all_day_end_date: string,
   *   effective_start: string,
   *   effective_end: string,
   *   effective_start_iso: string,
   *   effective_end_iso: string,
   *   source_timezone: string,
   *   responsibility_label: string,
   *   preparations: array<int, array{instruction: string, due_time: string, due_time_iso: string}>,
   *   schedule_target: array{series_id: int},
   *   responsibility_target: array{series_id: int, original_occurrence_key: string},
   *   cancel_target: ?array{series_id: int, original_occurrence_key: string}
   * }>
   */
  public function upcomingForPersonInHouseholds(Person $person, array $householdIds): array {
    $personId = $this->requirePersistedPersonId($person);
    $householdIds = $this->normalizeHouseholdIds($householdIds);
    if ($householdIds === []) {
      return [];
    }

    [$windowStart, $windowEnd] = $this->defaultWindow();

    return $this->aggregateInternal(
      $windowStart,
      $windowEnd,
      $personId,
      $householdIds,
    );
  }

  /**
   * Builds Household-wide presentation items for an explicit bounded UTC window.
   *
   * @return array<int, array{
   *   activity_label: string,
   *   location: string,
   *   concerned_person_labels: string[],
   *   all_day: bool,
   *   all_day_start_date: string,
   *   all_day_end_date: string,
   *   effective_start: string,
   *   effective_end: string,
   *   effective_start_iso: string,
   *   effective_end_iso: string,
   *   source_timezone: string,
   *   responsibility_label: string,
   *   preparations: array<int, array{instruction: string, due_time: string, due_time_iso: string}>,
   *   schedule_target: array{series_id: int},
   *   responsibility_target: array{series_id: int, original_occurrence_key: string},
   *   cancel_target: ?array{series_id: int, original_occurrence_key: string}
   * }>
   */
  public function aggregate(
    DateTimeImmutable $windowStart,
    DateTimeImmutable $windowEnd,
  ): array {
    return $this->aggregateInternal($windowStart, $windowEnd, NULL, NULL);
  }

  /**
   * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
   */
  private function defaultWindow(): array {
    $windowStart = (new DateTimeImmutable('@' . $this->time->getCurrentTime()))
      ->setTimezone(new DateTimeZone('UTC'));

    return [
      $windowStart,
      $windowStart->modify('+' . self::DEFAULT_WINDOW_DAYS . ' days'),
    ];
  }

  /**
   * @param int[]|null $householdIds
   *   NULL keeps the privileged all-Household aggregation. A non-NULL set is
   *   applied to the ActivitySeries query before any entity is loaded.
   *
   * @return array<int, array{
   *   activity_label: string,
   *   location: string,
   *   concerned_person_labels: string[],
   *   all_day: bool,
   *   all_day_start_date: string,
   *   all_day_end_date: string,
   *   effective_start: string,
   *   effective_end: string,
   *   effective_start_iso: string,
   *   effective_end_iso: string,
   *   source_timezone: string,
   *   responsibility_label: string,
   *   preparations: array<int, array{instruction: string, due_time: string, due_time_iso: string}>,
   *   schedule_target: array{series_id: int},
   *   responsibility_target: array{series_id: int, original_occurrence_key: string},
   *   cancel_target: ?array{series_id: int, original_occurrence_key: string}
   * }>
   */
  private function aggregateInternal(
    DateTimeImmutable $windowStart,
    DateTimeImmutable $windowEnd,
    ?int $responsiblePersonId,
    ?array $householdIds,
  ): array {
    $windowStart = $this->utc($windowStart);
    $windowEnd = $this->utc($windowEnd);
    if ($windowEnd <= $windowStart) {
      throw new InvalidArgumentException('Upcoming activity aggregation requires a bounded window with end after start.');
    }

    if ($householdIds !== NULL) {
      $householdIds = $this->normalizeHouseholdIds($householdIds);
      if ($householdIds === []) {
        return [];
      }
    }

    $sortable = [];
    $seriesStorage = $this->entityTypeManager->getStorage('personal_sec_activity_series');
    $personStorage = $this->entityTypeManager->getStorage('personal_secretary_person');

    if ($householdIds === NULL) {
      $seriesEntities = $seriesStorage->loadMultiple();
    }
    else {
      $seriesIds = $seriesStorage
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('household', $householdIds, 'IN')
        ->execute();
      $seriesEntities = $seriesStorage->loadMultiple($seriesIds);
    }

    foreach ($seriesEntities as $series) {
      if (!$series instanceof ActivitySeries) {
        throw new RuntimeException('ActivitySeries storage returned an unexpected entity type.');
      }

      if ($householdIds !== NULL) {
        $seriesHouseholdId = (int) ($series->get('household')->target_id ?? 0);
        if (!in_array($seriesHouseholdId, $householdIds, TRUE)) {
          throw new RuntimeException('Scoped ActivitySeries aggregation crossed its authorized Household boundary.');
        }
      }

      $activityLabel = trim((string) $series->label());
      if ($activityLabel === '') {
        throw new RuntimeException('Upcoming ActivitySeries has no presentation label.');
      }
      $location = trim((string) ($series->get('location')->value ?? ''));
      $concernedPersonLabels = $this->concernedPersonLabels($series);
      $allDay = $series->timeMode() === ActivitySeries::TIME_MODE_ALL_DAY;

      foreach ($this->effectiveOccurrences->project($series, $windowStart, $windowEnd) as $occurrence) {
        $responsibility = $this->effectiveResponsibility->resolve($series, $occurrence);

        if ($responsiblePersonId !== NULL && (
          $responsibility->state !== EffectiveResponsibility::STATE_ASSIGNED
          || $responsibility->responsiblePersonId !== $responsiblePersonId
        )) {
          continue;
        }

        $responsibilityLabel = '';
        $preparations = [];

        if ($responsibility->state === EffectiveResponsibility::STATE_ASSIGNED) {
          if ($responsibility->responsiblePersonId === NULL || $responsibility->responsiblePersonUuid === NULL) {
            throw new RuntimeException('Assigned EffectiveResponsibility has no responsible Person identity.');
          }
          $person = $personStorage->load($responsibility->responsiblePersonId);
          if (!$person instanceof Person || $person->uuid() !== $responsibility->responsiblePersonUuid) {
            throw new RuntimeException('Assigned EffectiveResponsibility references no current matching Person.');
          }
          $responsibilityLabel = trim((string) $person->label());
          if ($responsibilityLabel === '') {
            throw new RuntimeException('Responsible Person has no presentation label.');
          }

          $sourceTimezone = new DateTimeZone($occurrence->sourceTimezone);
          foreach ($this->preparationEligibility->derive($series, $occurrence) as $preparation) {
            $dueLocal = $this->utc(new DateTimeImmutable($preparation->dueAtUtc))
              ->setTimezone($sourceTimezone);
            $preparations[] = [
              'instruction' => $preparation->requirementLabel,
              'due_time' => $dueLocal->format('Y-m-d H:i'),
              'due_time_iso' => $dueLocal->format(DATE_ATOM),
            ];
          }
        }
        elseif ($responsibility->state !== EffectiveResponsibility::STATE_NONE) {
          throw new RuntimeException('Unknown EffectiveResponsibility state.');
        }

        $seriesId = $series->id();
        if ($seriesId === NULL) {
          throw new RuntimeException('Upcoming ActivitySeries has no persisted identity.');
        }
        $target = [
          'series_id' => (int) $seriesId,
          'original_occurrence_key' => $occurrence->originalOccurrenceKey,
        ];
        [$allDayStartDate, $allDayEndDate] = $allDay
          ? $this->allDayDates($occurrence->effectiveSourceLocalStart, $occurrence->effectiveSourceLocalEnd)
          : ['', ''];

        $sortable[] = [
          'sort_start' => $occurrence->effectiveUtcStart,
          'activity_label' => $activityLabel,
          'location' => $location,
          'concerned_person_labels' => $concernedPersonLabels,
          'all_day' => $allDay,
          'all_day_start_date' => $allDayStartDate,
          'all_day_end_date' => $allDayEndDate,
          'effective_start' => (new DateTimeImmutable($occurrence->effectiveSourceLocalStart))->format('Y-m-d H:i'),
          'effective_end' => (new DateTimeImmutable($occurrence->effectiveSourceLocalEnd))->format('Y-m-d H:i'),
          'effective_start_iso' => $occurrence->effectiveSourceLocalStart,
          'effective_end_iso' => $occurrence->effectiveSourceLocalEnd,
          'source_timezone' => $occurrence->sourceTimezone,
          'responsibility_label' => $responsibilityLabel,
          'preparations' => $preparations,
          'schedule_target' => ['series_id' => (int) $seriesId],
          'responsibility_target' => $target,
          'cancel_target' => $occurrence->exceptionUuid === NULL ? $target : NULL,
        ];
      }
    }

    usort(
      $sortable,
      static fn(array $left, array $right): int =>
        [$left['sort_start'], $left['activity_label']]
        <=>
        [$right['sort_start'], $right['activity_label']],
    );

    return array_map(
      static function (array $item): array {
        unset($item['sort_start']);
        return $item;
      },
      $sortable,
    );
  }

  /**
   * @return array{0:string,1:string}
   */
  private function allDayDates(string $sourceLocalStart, string $sourceLocalEnd): array {
    $start = new DateTimeImmutable($sourceLocalStart);
    $end = new DateTimeImmutable($sourceLocalEnd);
    if ($start->format('H:i:s') !== '00:00:00' || $end->format('H:i:s') !== '00:00:00' || $end <= $start) {
      throw new RuntimeException('ALL_DAY presentation requires positive source-local midnight boundaries.');
    }
    return [$start->format('Y-m-d'), $end->modify('-1 day')->format('Y-m-d')];
  }

  /**
   * @return string[]
   */
  private function concernedPersonLabels(ActivitySeries $series): array {
    $field = $series->get('concerned_persons');
    if ($field->isEmpty()) {
      return [];
    }

    $values = $field->getValue();
    $people = $field->referencedEntities();
    if (count($people) !== count($values)) {
      throw new RuntimeException('Concerned Person reference is not currently resolvable.');
    }

    $labels = [];
    foreach ($values as $delta => $value) {
      $person = $people[$delta] ?? NULL;
      if (!$person instanceof Person || (int) $person->id() !== (int) ($value['target_id'] ?? 0)) {
        throw new RuntimeException('Concerned Person reference does not match a current Person.');
      }
      $label = trim((string) $person->label());
      if ($label === '') {
        throw new RuntimeException('Concerned Person has no presentation label.');
      }
      $labels[] = $label;
    }
    return $labels;
  }

  private function requirePersistedPersonId(Person $person): int {
    if ($person->isNew() || $person->id() === NULL) {
      throw new InvalidArgumentException('Personalized upcoming requires a persisted Person.');
    }

    return (int) $person->id();
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
        throw new InvalidArgumentException('Scoped upcoming Household IDs must be positive integers.');
      }
      $normalized[$value] = $value;
    }

    $normalized = array_values($normalized);
    sort($normalized, SORT_NUMERIC);
    return $normalized;
  }

  private function utc(DateTimeImmutable $value): DateTimeImmutable {
    return $value->setTimezone(new DateTimeZone('UTC'));
  }

}
