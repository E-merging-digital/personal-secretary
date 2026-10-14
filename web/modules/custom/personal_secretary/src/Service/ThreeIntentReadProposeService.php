<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\personal_secretary\Entity\PersonalTask;
use Drupal\personal_secretary\Value\ActivityCaptureExtraction;
use Drupal\personal_secretary\Value\ActivityCaptureInput;
use InvalidArgumentException;
use RuntimeException;

/**
 * Offline typed READ/PROPOSE seam; never a transport or mutation surface.
 *
 * Identity always comes from the current persisted Drupal User in existing
 * application services, never from an untrusted caller argument.
 */
final class ThreeIntentReadProposeService {

  public const GET_TODAY = 'GET_TODAY';
  public const PROPOSE_TASK = 'PROPOSE_TASK';
  public const PROPOSE_ACTIVITY = 'PROPOSE_ACTIVITY';

  public function __construct(
    private readonly TodayService $today,
    private readonly CurrentUserActivityCreationService $activityCreation,
    private readonly ActivityCaptureResolver $activityResolver,
  ) {}

  /**
   * @param array<string, mixed> $input
   *   Strictly typed fictional caller input, not an LLM interpretation.
   *
   * @return array<string, mixed>
   *   Explicit status, effects and nonpersistence.
   */
  public function handle(string $intent, array $input = []): array {
    return match ($intent) {
      self::GET_TODAY => $this->getToday($input),
      self::PROPOSE_TASK => $this->proposeTask($input),
      self::PROPOSE_ACTIVITY => $this->proposeActivity($input),
      default => $this->result($intent, 'NONE', 'UNAVAILABLE', NULL, ['unsupported_intent']),
    };
  }

  /**
   * @param array<string, mixed> $input
   */
  private function getToday(array $input): array {
    if ($input !== []) {
      return $this->result(self::GET_TODAY, 'READ', 'UNAVAILABLE', NULL, ['unexpected_input']);
    }
    try {
      $today = $this->today->today();
    }
    catch (InvalidArgumentException|RuntimeException) {
      return $this->result(self::GET_TODAY, 'READ', 'ACCESS_DENIED', NULL, ['authorized_today_scope_required']);
    }

    // Whitelist presentation data; no entity ID, provider payload, or token.
    $tasks = array_map(static fn(array $row): array => [
      'title' => (string) $row['title'],
      'due_mode' => (string) $row['due_mode'],
      'due_label' => (string) $row['due_label'],
      'overdue' => (bool) $row['overdue'],
    ], $today['tasks']);
    $preparations = array_map(static fn(array $row): array => [
      'instruction' => (string) $row['instruction'],
      'due_time' => (string) $row['due_time'],
      'overdue' => (bool) $row['overdue'],
    ], $today['preparations']);
    $planning = array_map(static function (array $entry): array {
      $row = $entry['item'];
      $external = $entry['kind'] === 'external_event';
      return [
        'kind' => $external ? 'external_event' : 'native_activity',
        'title' => (string) ($external ? $row['title'] : $row['activity_label']),
        'all_day' => (bool) $row['all_day'],
        'start' => (string) ($row['effective_start_iso'] ?? ''),
        'end' => (string) ($row['effective_end_iso'] ?? ''),
        'all_day_start_date' => (string) ($row['all_day_start_date'] ?? ''),
        'all_day_end_date' => (string) ($row['all_day_end_date'] ?? ''),
        'source_label' => $external ? (string) $row['source_label'] : 'Personal Secretary',
        'editability' => $external ? (string) $row['editability'] : 'READ_ONLY_CAPABILITY',
        'source_freshness' => $external ? 'AS_STORED_NOT_LIVE_VERIFIED' : 'LOCAL_DOMAIN',
      ];
    }, $today['planning']);

    return $this->result(self::GET_TODAY, 'READ', 'READ_SUCCESS', [
      'timezone' => (string) $today['timezone'],
      'local_date' => (string) $today['local_date'],
      'local_start' => (string) $today['local_start'],
      'local_end' => (string) $today['local_end'],
      'tasks' => array_values($tasks),
      'preparations' => array_values($preparations),
      'planning' => array_values($planning),
    ]);
  }

  /**
   * @param array<string, mixed> $input
   */
  private function proposeTask(array $input): array {
    if (array_diff(array_keys($input), ['title', 'household_id', 'due_mode', 'due_date', 'due_at']) !== []) {
      return $this->result(self::PROPOSE_TASK, 'PROPOSE', 'UNAVAILABLE', NULL, ['unexpected_input']);
    }

    try {
      // Existing read-only creationScope checks persisted User, permissions,
      // CurrentPerson and eligible Household membership.
      $scope = $this->activityCreation->creationScope();
    }
    catch (InvalidArgumentException|RuntimeException) {
      return $this->result(self::PROPOSE_TASK, 'PROPOSE', 'ACCESS_DENIED', NULL, ['authorized_task_scope_required']);
    }
    $households = $scope['households'];
    if ($households === []) {
      return $this->result(self::PROPOSE_TASK, 'PROPOSE', 'ACCESS_DENIED', NULL, ['authorized_task_scope_required']);
    }

    $clarifications = [];
    $title = $input['title'] ?? NULL;
    if (!is_string($title) || trim($title) === '' || mb_strlen(trim($title)) > 255) {
      $clarifications[] = 'title_required_or_invalid';
    }
    $mode = $input['due_mode'] ?? PersonalTask::DUE_NONE;
    if (!in_array($mode, [PersonalTask::DUE_NONE, PersonalTask::DUE_DATE, PersonalTask::DUE_DATE_TIME], TRUE)) {
      $clarifications[] = 'due_mode_invalid';
    }

    $household = $input['household_id'] ?? NULL;
    if ($household === NULL && count($households) === 1) {
      $household = (int) array_key_first($households);
    }
    elseif ($household === NULL) {
      $clarifications[] = 'household_selection_required';
    }
    elseif (!is_int($household) || !isset($households[$household])) {
      return $this->result(self::PROPOSE_TASK, 'PROPOSE', 'ACCESS_DENIED', NULL, ['household_scope_conflict']);
    }

    $date = $input['due_date'] ?? NULL;
    $at = $input['due_at'] ?? NULL;
    if ($mode === PersonalTask::DUE_NONE && ($date !== NULL || $at !== NULL)) {
      $clarifications[] = 'unexpected_due_value';
    }
    elseif ($mode === PersonalTask::DUE_DATE && (!is_string($date) || !$this->validDate($date, 'Y-m-d') || $at !== NULL)) {
      $clarifications[] = 'due_date_required_or_invalid';
    }
    elseif ($mode === PersonalTask::DUE_DATE_TIME && (!is_string($at) || !$this->validDate($at, 'Y-m-d H:i') || $date !== NULL)) {
      $clarifications[] = 'due_at_required_or_invalid';
    }

    if ($clarifications !== []) {
      return $this->result(self::PROPOSE_TASK, 'PROPOSE', 'NEEDS_CLARIFICATION', NULL, $clarifications);
    }
    return $this->result(self::PROPOSE_TASK, 'PROPOSE', 'PROPOSAL_READY', [
      'title' => trim($title),
      'household_id' => $household,
      'due_mode' => $mode,
      'due_date' => $mode === PersonalTask::DUE_DATE ? $date : NULL,
      'due_at' => $mode === PersonalTask::DUE_DATE_TIME ? $at : NULL,
      'ready_for_confirmation' => TRUE,
    ]);
  }

  /**
   * @param array<string, mixed> $input
   */
  private function proposeActivity(array $input): array {
    if (count($input) !== 2
      || array_diff(array_keys($input), ['context', 'extraction']) !== []
      || !($input['context'] ?? NULL) instanceof ActivityCaptureInput
      || !($input['extraction'] ?? NULL) instanceof ActivityCaptureExtraction) {
      return $this->result(self::PROPOSE_ACTIVITY, 'PROPOSE', 'UNAVAILABLE', NULL, ['typed_activity_input_required']);
    }

    // Deterministic current-scope resolution only; never call interpreter.
    $proposal = $this->activityResolver->resolve($input['context'], $input['extraction']);
    if (in_array(ActivityCaptureResolver::CLARIFICATION_AUTHORIZED_SCOPE_UNAVAILABLE, $proposal->clarifications, TRUE)) {
      return $this->result(self::PROPOSE_ACTIVITY, 'PROPOSE', 'ACCESS_DENIED', NULL, ['authorized_activity_scope_required']);
    }
    return $this->result(
      self::PROPOSE_ACTIVITY,
      'PROPOSE',
      $proposal->readyForConfirmation() ? 'PROPOSAL_READY' : 'NEEDS_CLARIFICATION',
      $proposal->toArray(),
      $proposal->clarifications,
    );
  }

  private function validDate(string $value, string $format): bool {
    $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    return $date instanceof DateTimeImmutable
      && ($errors === FALSE || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
      && $date->format($format) === $value;
  }

  /**
   * @param string[] $clarifications
   * @return array<string, mixed>
   */
  private function result(string $intent, string $effect, string $status, ?array $data = NULL, array $clarifications = []): array {
    return [
      'intent' => $intent,
      'effect' => $effect,
      'status' => $status,
      'allowed_effects' => in_array($status, ['READ_SUCCESS', 'PROPOSAL_READY'], TRUE) ? [$effect] : [],
      'saved' => FALSE,
      'data' => $data,
      'clarifications' => array_values($clarifications),
    ];
  }

}
