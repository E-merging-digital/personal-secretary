<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeZone;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\Household;
use Drupal\personal_secretary\Entity\Person;
use Drupal\personal_secretary\Value\ActivityCaptureExtraction;
use Drupal\personal_secretary\Value\ActivityCaptureInput;
use Drupal\personal_secretary\Value\ActivityCaptureProposal;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves narrow AI extraction through deterministic application authority.
 */
final class ActivityCaptureResolver {

  public const CLARIFICATION_LABEL_REQUIRED = 'label_required';
  public const CLARIFICATION_DATE_REQUIRED = 'date_required';
  public const CLARIFICATION_DATE_UNRESOLVED = 'date_unresolved';
  public const CLARIFICATION_TIME_MODE_REQUIRED = 'time_mode_required';
  public const CLARIFICATION_TIME_MODE_CONFLICT = 'time_mode_conflict';
  public const CLARIFICATION_START_TIME_INVALID = 'start_time_invalid';
  public const CLARIFICATION_END_TIME_REQUIRED = 'end_time_required';
  public const CLARIFICATION_END_TIME_INVALID = 'end_time_invalid';
  public const CLARIFICATION_END_TIME_AFTER_START = 'end_time_after_start_required';
  public const CLARIFICATION_UNSUPPORTED_RECURRENCE = 'unsupported_recurrence';
  public const CLARIFICATION_PERSON_ALTERNATIVE = 'person_alternative_requires_selection';
  public const CLARIFICATION_PERSON_NOT_FOUND = 'person_not_found';
  public const CLARIFICATION_PERSON_AMBIGUOUS = 'person_ambiguous';
  public const CLARIFICATION_RESPONSIBILITY_NOT_FOUND = 'responsibility_not_found';
  public const CLARIFICATION_RESPONSIBILITY_AMBIGUOUS = 'responsibility_ambiguous';
  public const CLARIFICATION_RESPONSIBILITY_REQUIRED = 'responsibility_required_for_weekly';
  public const CLARIFICATION_HOUSEHOLD_SELECTION = 'household_selection_required';
  public const CLARIFICATION_HOUSEHOLD_SCOPE_CONFLICT = 'household_scope_conflict';
  public const CLARIFICATION_AUTHORIZED_SCOPE_UNAVAILABLE = 'authorized_scope_unavailable';

  private const WEEKDAYS = [
    'lundi' => ['MONDAY', 1],
    'lundis' => ['MONDAY', 1],
    'monday' => ['MONDAY', 1],
    'mardi' => ['TUESDAY', 2],
    'mardis' => ['TUESDAY', 2],
    'tuesday' => ['TUESDAY', 2],
    'mercredi' => ['WEDNESDAY', 3],
    'mercredis' => ['WEDNESDAY', 3],
    'wednesday' => ['WEDNESDAY', 3],
    'jeudi' => ['THURSDAY', 4],
    'jeudis' => ['THURSDAY', 4],
    'thursday' => ['THURSDAY', 4],
    'vendredi' => ['FRIDAY', 5],
    'vendredis' => ['FRIDAY', 5],
    'friday' => ['FRIDAY', 5],
    'samedi' => ['SATURDAY', 6],
    'samedis' => ['SATURDAY', 6],
    'saturday' => ['SATURDAY', 6],
    'dimanche' => ['SUNDAY', 7],
    'dimanches' => ['SUNDAY', 7],
    'sunday' => ['SUNDAY', 7],
  ];



  public function __construct(
    private readonly CurrentUserActivityCreationService $activityCreation,
  ) {}

  /**
   * Produces an editable review proposal without mutating domain state.
   */
  public function resolve(
    ActivityCaptureInput $input,
    ActivityCaptureExtraction $extraction,
  ): ActivityCaptureProposal {
    $clarifications = [];

    $label = $this->nullableText($extraction->labelText);
    if ($label === NULL) {
      $this->clarify($clarifications, self::CLARIFICATION_LABEL_REQUIRED);
    }

    [$intent, $weekday] = $this->resolveRecurrence(
      $extraction->recurrenceExpression,
      $clarifications,
    );
    $absoluteDate = $this->resolveDate($input, $extraction, $clarifications);
    [$timeMode, $startTime, $endTime] = $this->resolveTime(
      $extraction,
      $clarifications,
    );

    $householdId = NULL;
    $concernedPersonIds = [];
    $responsiblePersonId = NULL;

    try {
      $scope = $this->activityCreation->creationScope();
      $people = $scope['people'];
      $households = $scope['households'];
      $currentPerson = $scope['current_person'];

      if ($extraction->concernedPersonAlternative && count($extraction->concernedPersonMentions) > 1) {
        $this->clarify($clarifications, self::CLARIFICATION_PERSON_ALTERNATIVE);
      }
      else {
        foreach ($extraction->concernedPersonMentions as $mention) {
          $matches = $this->personMatches($people, $mention);
          if ($matches === []) {
            $this->clarify($clarifications, self::CLARIFICATION_PERSON_NOT_FOUND);
            continue;
          }
          if (count($matches) !== 1) {
            $this->clarify($clarifications, self::CLARIFICATION_PERSON_AMBIGUOUS);
            continue;
          }
          $concernedPersonIds[$matches[0]] = $matches[0];
        }
      }

      $responsibility = $this->nullableText($extraction->responsibilityCandidate);
      if ($responsibility !== NULL) {
        if (strcasecmp($responsibility, ActivityCaptureExtraction::RESPONSIBILITY_SELF) === 0) {
          $responsiblePersonId = (int) $currentPerson->id();
        }
        else {
          $matches = $this->personMatches($people, $responsibility);
          if ($matches === []) {
            $this->clarify($clarifications, self::CLARIFICATION_RESPONSIBILITY_NOT_FOUND);
          }
          elseif (count($matches) !== 1) {
            $this->clarify($clarifications, self::CLARIFICATION_RESPONSIBILITY_AMBIGUOUS);
          }
          else {
            $responsiblePersonId = $matches[0];
          }
        }
      }

      $requiredPersonIds = $concernedPersonIds;
      if ($responsiblePersonId !== NULL) {
        $requiredPersonIds[$responsiblePersonId] = $responsiblePersonId;
      }
      $householdId = $this->resolveHousehold(
        $households,
        array_values($requiredPersonIds),
        $clarifications,
      );
    }
    catch (InvalidArgumentException|RuntimeException) {
      $this->clarify($clarifications, self::CLARIFICATION_AUTHORIZED_SCOPE_UNAVAILABLE);
    }

    $concernedPersonIds = array_values($concernedPersonIds);
    sort($concernedPersonIds, SORT_NUMERIC);

    if ($intent === ActivityCaptureProposal::INTENT_WEEKLY && $responsiblePersonId === NULL) {
      $this->clarify($clarifications, self::CLARIFICATION_RESPONSIBILITY_REQUIRED);
    }

    return new ActivityCaptureProposal(
      householdId: $householdId,
      intent: $intent,
      label: $label,
      location: $this->nullableText($extraction->locationText),
      timeMode: $timeMode,
      absoluteDate: $absoluteDate,
      localStartTime: $startTime,
      localEndTime: $endTime,
      sourceTimezone: $input->sourceTimezone,
      weekday: $weekday,
      concernedPersonIds: $concernedPersonIds,
      responsiblePersonId: $responsiblePersonId,
      clarifications: array_values($clarifications),
    );
  }

  /**
   * @param string[] $clarifications
   *   Mutable clarification accumulator.
   *
   * @return array{0: ?string, 1: ?string}
   *   Final supported intent and normalized weekday.
   */
  private function resolveRecurrence(?string $expression, array &$clarifications): array {
    $expression = $this->nullableText($expression);
    if ($expression === NULL) {
      return [ActivityCaptureProposal::INTENT_ONE_OFF, NULL];
    }

    $normalized = $this->normalizeLanguage($expression);
    if (preg_match('/\\b(?:mois|mensuel|mensuelle|month|monthly)\\b/u', $normalized) === 1) {
      $this->clarify($clarifications, self::CLARIFICATION_UNSUPPORTED_RECURRENCE);
      return [NULL, NULL];
    }

    $weekday = $this->weekdayFromText($normalized);
    $weeklyCue = preg_match('/\\b(?:tous|toutes|chaque|hebdomadaire|hebdomadaires|semaine|weekly|every)\\b/u', $normalized) === 1;
    if ($weeklyCue && $weekday !== NULL) {
      return [ActivityCaptureProposal::INTENT_WEEKLY, $weekday[0]];
    }

    $this->clarify($clarifications, self::CLARIFICATION_UNSUPPORTED_RECURRENCE);
    return [NULL, NULL];
  }

  /**
   * Resolves the final reviewed civil date from frozen input context.
   */
  private function resolveDate(
    ActivityCaptureInput $input,
    ActivityCaptureExtraction $extraction,
    array &$clarifications,
  ): ?string {
    $timezone = new DateTimeZone($input->sourceTimezone);
    $context = $input->contextInstantUtc->setTimezone($timezone);

    if ($extraction->relativeDayOffset !== NULL) {
      return $context
        ->setTime(0, 0)
        ->modify(sprintf('%+d days', $extraction->relativeDayOffset))
        ->format('Y-m-d');
    }

    if (
      $extraction->dateDay !== NULL
      || $extraction->dateMonth !== NULL
      || $extraction->dateYear !== NULL
    ) {
      if (
        $extraction->dateDay === NULL
        || $extraction->dateMonth === NULL
        || $extraction->dateYear === NULL
      ) {
        $this->clarify($clarifications, self::CLARIFICATION_DATE_UNRESOLVED);
        return NULL;
      }
      return $this->validatedDate(
        $extraction->dateYear,
        $extraction->dateMonth,
        $extraction->dateDay,
        $clarifications,
      );
    }

    $expression = $this->nullableText($extraction->dateExpression);
    if ($expression === NULL) {
      $this->clarify($clarifications, self::CLARIFICATION_DATE_REQUIRED);
      return NULL;
    }

    $normalized = $this->normalizeLanguage($expression);
    $weekday = $this->weekdayFromText($normalized);
    if ($weekday !== NULL) {
      $currentDay = (int) $context->format('N');
      $delta = ($weekday[1] - $currentDay + 7) % 7;
      return $context->setTime(0, 0)->modify(sprintf('+%d days', $delta))->format('Y-m-d');
    }

    $this->clarify($clarifications, self::CLARIFICATION_DATE_UNRESOLVED);
    return NULL;
  }

  /**
   * @return array{0: ?string, 1: ?string, 2: ?string}
   *   Domain time mode, normalized start time and normalized end time.
   */
  private function resolveTime(
    ActivityCaptureExtraction $extraction,
    array &$clarifications,
  ): array {
    $start = $this->normalizedTime($extraction->startTimeExpression);
    $end = $this->normalizedTime($extraction->endTimeExpression);
    $hasStartExpression = $this->nullableText($extraction->startTimeExpression) !== NULL;
    $hasEndExpression = $this->nullableText($extraction->endTimeExpression) !== NULL;

    if ($hasStartExpression && $start === NULL) {
      $this->clarify($clarifications, self::CLARIFICATION_START_TIME_INVALID);
    }
    if ($hasEndExpression && $end === NULL) {
      $this->clarify($clarifications, self::CLARIFICATION_END_TIME_INVALID);
    }

    $timed = $extraction->explicitTimedSignal || $hasStartExpression;
    if ($extraction->explicitAllDaySignal && $timed) {
      $this->clarify($clarifications, self::CLARIFICATION_TIME_MODE_CONFLICT);
      return [NULL, $start, $end];
    }

    if ($extraction->explicitAllDaySignal) {
      return [ActivitySeries::TIME_MODE_ALL_DAY, NULL, NULL];
    }

    if (!$timed) {
      $this->clarify($clarifications, self::CLARIFICATION_TIME_MODE_REQUIRED);
      return [NULL, NULL, NULL];
    }

    if ($start === NULL) {
      $this->clarify($clarifications, self::CLARIFICATION_START_TIME_INVALID);
    }
    if (!$hasEndExpression) {
      $this->clarify($clarifications, self::CLARIFICATION_END_TIME_REQUIRED);
    }
    elseif ($end !== NULL && $start !== NULL && $end <= $start) {
      $this->clarify($clarifications, self::CLARIFICATION_END_TIME_AFTER_START);
    }

    return [ActivitySeries::TIME_MODE_TIMED, $start, $end];
  }

  /**
   * @param array<int, Person> $people
   *   People in authorized creation scope.
   *
   * @return int[]
   *   Exact case-insensitive label matches.
   */
  private function personMatches(array $people, string $mention): array {
    $needle = $this->normalizePersonLabel($mention);
    $matches = [];
    foreach ($people as $person) {
      if (!$person instanceof Person || $person->id() === NULL) {
        continue;
      }
      if ($this->normalizePersonLabel((string) $person->label()) === $needle) {
        $id = (int) $person->id();
        $matches[$id] = $id;
      }
    }
    $matches = array_values($matches);
    sort($matches, SORT_NUMERIC);
    return $matches;
  }

  /**
   * @param array<int, Household> $households
   *   Authorized, CurrentPerson-member Households.
   * @param int[] $requiredPersonIds
   *   Resolved Persons that must belong to the selected Household.
   * @param string[] $clarifications
   *   Mutable clarification accumulator.
   */
  private function resolveHousehold(
    array $households,
    array $requiredPersonIds,
    array &$clarifications,
  ): ?int {
    $candidates = [];
    foreach ($households as $household) {
      if (!$household instanceof Household || $household->id() === NULL) {
        continue;
      }
      $memberIds = [];
      foreach ($household->get('members') as $item) {
        $personId = (int) ($item->target_id ?? 0);
        if ($personId > 0) {
          $memberIds[$personId] = $personId;
        }
      }
      if (array_diff($requiredPersonIds, array_values($memberIds)) === []) {
        $id = (int) $household->id();
        $candidates[$id] = $id;
      }
    }

    if (count($candidates) === 1) {
      return (int) reset($candidates);
    }
    if ($candidates === []) {
      $this->clarify($clarifications, self::CLARIFICATION_HOUSEHOLD_SCOPE_CONFLICT);
      return NULL;
    }

    $this->clarify($clarifications, self::CLARIFICATION_HOUSEHOLD_SELECTION);
    return NULL;
  }

  /**
   * @return array{0: string, 1: int}|null
   *   Normalized weekday constant and ISO weekday number.
   */
  private function weekdayFromText(string $value): ?array {
    foreach (self::WEEKDAYS as $token => $weekday) {
      if (preg_match('/\\b' . preg_quote($token, '/') . '\\b/u', $value) === 1) {
        return $weekday;
      }
    }
    return NULL;
  }

  /**
   * Converts a bounded explicit time expression to HH:MM.
   */
  private function normalizedTime(?string $expression): ?string {
    $expression = $this->nullableText($expression);
    if ($expression === NULL) {
      return NULL;
    }

    if (preg_match('/\\b([01]?\\d|2[0-3])\\s*h(?:\\s*([0-5]\\d))?\\b/ui', $expression, $match) === 1) {
      return sprintf('%02d:%02d', (int) $match[1], isset($match[2]) && $match[2] !== '' ? (int) $match[2] : 0);
    }
    if (preg_match('/\\b([01]?\\d|2[0-3]):([0-5]\\d)\\b/u', $expression, $match) === 1) {
      return sprintf('%02d:%02d', (int) $match[1], (int) $match[2]);
    }

    return NULL;
  }

  private function validatedDate(
    int $year,
    int $month,
    int $day,
    array &$clarifications,
  ): ?string {
    if (!checkdate($month, $day, $year)) {
      $this->clarify($clarifications, self::CLARIFICATION_DATE_UNRESOLVED);
      return NULL;
    }
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
  }

  private function normalizeLanguage(string $value): string {
    $value = mb_strtolower(trim($value));
    return preg_replace('/\\s+/u', ' ', $value) ?? $value;
  }

  private function normalizePersonLabel(string $value): string {
    return $this->normalizeLanguage($value);
  }

  private function nullableText(?string $value): ?string {
    if ($value === NULL) {
      return NULL;
    }
    $value = trim($value);
    return $value === '' ? NULL : $value;
  }

  /**
   * Adds one deterministic clarification code at most once.
   *
   * @param string[] $clarifications
   *   Mutable clarification accumulator.
   */
  private function clarify(array &$clarifications, string $code): void {
    $clarifications[$code] = $code;
  }

}
