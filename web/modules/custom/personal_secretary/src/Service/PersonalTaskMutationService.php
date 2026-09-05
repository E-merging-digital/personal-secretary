<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\personal_secretary\Entity\Household;
use Drupal\personal_secretary\Entity\Person;
use Drupal\personal_secretary\Entity\PersonalTask;
use Drupal\user\UserInterface;
use InvalidArgumentException;

/**
 * Governs the first standalone PersonalTask mutation surface.
 */
final class PersonalTaskMutationService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly HouseholdAuthorizationService $householdAuthorization,
    private readonly CurrentPersonResolver $currentPersonResolver,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * @return array<int, \Drupal\personal_secretary\Entity\Household>
   */
  public function eligibleHouseholds(): array {
    $user = $this->currentPersistedUser();
    $person = $this->currentPersonResolver->resolve($user);
    $ids = $this->householdAuthorization->authorizedHouseholdIds($user);
    if ($ids === []) {
      return [];
    }

    $households = $this->entityTypeManager
      ->getStorage('personal_secretary_household')
      ->loadMultiple($ids);

    $eligible = [];
    foreach ($ids as $id) {
      $household = $households[$id] ?? NULL;
      if ($household instanceof Household && $this->isMember($household, $person)) {
        $eligible[(int) $household->id()] = $household;
      }
    }
    ksort($eligible, SORT_NUMERIC);
    return $eligible;
  }

  public function createTask(
    string $title,
    int $householdId,
    string $dueMode,
    ?string $dueDate = NULL,
    ?string $dueDateTimeLocal = NULL,
  ): PersonalTask {
    $user = $this->currentPersistedUser();
    $household = $this->householdAuthorization->requireAuthorized($user, $householdId);
    $person = $this->currentPersonResolver->resolve($user);
    $this->requireMembership($household, $person);
    [$normalizedDate, $normalizedAt] = $this->normalizeDue($dueMode, $dueDate, $dueDateTimeLocal, $user);

    $task = $this->entityTypeManager
      ->getStorage(PersonalTask::ENTITY_TYPE_ID)
      ->create([
        'title' => $this->normalizeTitle($title),
        'household' => ['target_id' => (int) $household->id()],
        'assigned_person' => ['target_id' => (int) $person->id()],
        'due_mode' => $dueMode,
        'due_date' => $normalizedDate,
        'due_at' => $normalizedAt,
        'status' => PersonalTask::STATUS_OPEN,
      ]);
    if (!$task instanceof PersonalTask) {
      throw new InvalidArgumentException('Unable to create PersonalTask.');
    }
    $task->save();
    return $task;
  }

  public function editTask(
    int $taskId,
    string $title,
    string $dueMode,
    ?string $dueDate = NULL,
    ?string $dueDateTimeLocal = NULL,
  ): PersonalTask {
    $task = $this->requireCurrentTask($taskId);
    if ((string) $task->get('status')->value !== PersonalTask::STATUS_OPEN) {
      throw new InvalidArgumentException('Completed PersonalTask must be reopened before editing.');
    }

    $user = $this->currentPersistedUser();
    [$normalizedDate, $normalizedAt] = $this->normalizeDue($dueMode, $dueDate, $dueDateTimeLocal, $user);
    $task->set('title', $this->normalizeTitle($title));
    $task->set('due_mode', $dueMode);
    $task->set('due_date', $normalizedDate);
    $task->set('due_at', $normalizedAt);
    $task->save();
    return $task;
  }

  public function completeTask(int $taskId): bool {
    $task = $this->requireCurrentTask($taskId);
    if ((string) $task->get('status')->value === PersonalTask::STATUS_COMPLETED) {
      return FALSE;
    }

    $user = $this->currentPersistedUser();
    $task->set('status', PersonalTask::STATUS_COMPLETED);
    $task->set('completed_at', $this->time->getCurrentTime());
    $task->set('completed_by_user', ['target_id' => (int) $user->id()]);
    $task->save();
    return TRUE;
  }

  public function reopenTask(int $taskId): bool {
    $task = $this->requireCurrentTask($taskId);
    if ((string) $task->get('status')->value === PersonalTask::STATUS_OPEN) {
      return FALSE;
    }

    $task->set('status', PersonalTask::STATUS_OPEN);
    $task->set('completed_at', NULL);
    $task->set('completed_by_user', NULL);
    $task->save();
    return TRUE;
  }

  public function deleteTask(int $taskId): void {
    $task = $this->requireCurrentTask($taskId);
    $this->entityTypeManager->getStorage(PersonalTask::ENTITY_TYPE_ID)->delete([$task]);
  }

  public function requireCurrentTask(int $taskId): PersonalTask {
    if ($taskId <= 0) {
      throw new InvalidArgumentException('PersonalTask ID is invalid.');
    }

    $task = $this->entityTypeManager
      ->getStorage(PersonalTask::ENTITY_TYPE_ID)
      ->load($taskId);
    if (!$task instanceof PersonalTask || $task->id() === NULL) {
      throw new InvalidArgumentException('PersonalTask does not exist.');
    }

    $user = $this->currentPersistedUser();
    $householdId = (int) $task->get('household')->target_id;
    $this->householdAuthorization->requireAuthorized($user, $householdId);
    $person = $this->currentPersonResolver->resolve($user);
    if ((int) $task->get('assigned_person')->target_id !== (int) $person->id()) {
      throw new InvalidArgumentException('PersonalTask is not assigned to CurrentPerson.');
    }

    $household = $task->get('household')->entity;
    if (!$household instanceof Household) {
      throw new InvalidArgumentException('PersonalTask Household is missing.');
    }
    $this->requireMembership($household, $person);

    return $task;
  }

  public function currentUserTimezoneId(): string {
    return $this->timezoneId($this->currentPersistedUser());
  }

  public function dueAtAsLocalInput(PersonalTask $task): ?string {
    $stored = (string) ($task->get('due_at')->value ?? '');
    if ($stored === '') {
      return NULL;
    }

    $utc = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s', $stored, new DateTimeZone('UTC'));
    if (!$utc instanceof DateTimeImmutable) {
      throw new InvalidArgumentException('PersonalTask has an invalid persisted due instant.');
    }
    return $utc
      ->setTimezone(new DateTimeZone($this->currentUserTimezoneId()))
      ->format('Y-m-d H:i');
  }

  private function currentPersistedUser(): UserInterface {
    if ($this->currentUser->isAnonymous() || (int) $this->currentUser->id() <= 0) {
      throw new InvalidArgumentException('PersonalTask requires an authenticated Drupal User.');
    }

    $user = $this->entityTypeManager
      ->getStorage('user')
      ->load((int) $this->currentUser->id());
    if (!$user instanceof UserInterface || !$user->isActive()) {
      throw new InvalidArgumentException('PersonalTask requires an active persisted Drupal User.');
    }
    return $user;
  }

  private function normalizeTitle(string $title): string {
    $title = trim($title);
    if ($title === '' || mb_strlen($title) > 255) {
      throw new InvalidArgumentException('PersonalTask title must contain between 1 and 255 characters.');
    }
    return $title;
  }

  /**
   * @return array{0: ?string, 1: ?string}
   */
  private function normalizeDue(
    string $dueMode,
    ?string $dueDate,
    ?string $dueDateTimeLocal,
    UserInterface $user,
  ): array {
    $dueDate = trim((string) $dueDate);
    $dueDateTimeLocal = trim((string) $dueDateTimeLocal);

    if ($dueMode === PersonalTask::DUE_NONE) {
      if ($dueDate !== '' || $dueDateTimeLocal !== '') {
        throw new InvalidArgumentException('Undated PersonalTask cannot include a due value.');
      }
      return [NULL, NULL];
    }

    if ($dueMode === PersonalTask::DUE_DATE) {
      if ($dueDate === '' || $dueDateTimeLocal !== '') {
        throw new InvalidArgumentException('Date-only PersonalTask requires exactly one civil date.');
      }
      $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate, new DateTimeZone('UTC'));
      $errors = DateTimeImmutable::getLastErrors();
      if (!$parsed instanceof DateTimeImmutable || ($errors !== FALSE && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsed->format('Y-m-d') !== $dueDate) {
        throw new InvalidArgumentException('PersonalTask due date is invalid.');
      }
      return [$dueDate, NULL];
    }

    if ($dueMode === PersonalTask::DUE_DATE_TIME) {
      if ($dueDate !== '' || $dueDateTimeLocal === '') {
        throw new InvalidArgumentException('Date-time PersonalTask requires exactly one local date-time.');
      }
      $timezone = new DateTimeZone($this->timezoneId($user));
      $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $dueDateTimeLocal, $timezone);
      $errors = DateTimeImmutable::getLastErrors();
      if (!$parsed instanceof DateTimeImmutable || ($errors !== FALSE && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsed->format('Y-m-d H:i') !== $dueDateTimeLocal) {
        throw new InvalidArgumentException('PersonalTask due date-time is invalid in the current User timezone.');
      }
      return [NULL, $parsed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s')];
    }

    throw new InvalidArgumentException('PersonalTask due mode is invalid.');
  }

  private function timezoneId(UserInterface $user): string {
    $timezone = trim((string) $user->getTimeZone());
    if ($timezone !== '') {
      return $timezone;
    }

    $fallback = trim((string) $this->configFactory->get('system.date')->get('timezone.default'));
    return $fallback !== '' ? $fallback : 'UTC';
  }

  private function requireMembership(Household $household, Person $person): void {
    if (!$this->isMember($household, $person)) {
      throw new InvalidArgumentException('CurrentPerson is not a current member of the selected Household.');
    }
  }

  private function isMember(Household $household, Person $person): bool {
    $personId = (int) $person->id();
    foreach ($household->get('members') as $member) {
      if ((int) ($member->target_id ?? 0) === $personId) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
