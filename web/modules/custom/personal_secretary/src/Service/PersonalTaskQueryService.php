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
use Drupal\personal_secretary\Entity\PersonalTask;
use Drupal\user\UserInterface;
use InvalidArgumentException;

/**
 * Builds the authorized, personalized OPEN PersonalTask read model.
 */
final class PersonalTaskQueryService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly HouseholdAuthorizationService $householdAuthorization,
    private readonly CurrentPersonResolver $currentPersonResolver,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * @return array<int, array<string, mixed>>
   */
  public function myOpenTasks(): array {
    $user = $this->currentPersistedUser();
    $authorizedIds = $this->householdAuthorization->authorizedHouseholdIds($user);
    $person = $this->currentPersonResolver->resolve($user);
    $eligibleIds = $this->eligibleHouseholdIds($authorizedIds, (int) $person->id());
    if ($eligibleIds === []) {
      return [];
    }

    $ids = $this->entityTypeManager
      ->getStorage(PersonalTask::ENTITY_TYPE_ID)
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('household', $eligibleIds, 'IN')
      ->condition('assigned_person', (int) $person->id())
      ->condition('status', PersonalTask::STATUS_OPEN)
      ->execute();
    if ($ids === []) {
      return [];
    }

    return $this->loadAndPresent($ids, $user);
  }

  /**
   * Returns only OPEN tasks whose due values are relevant to current-user Today.
   *
   * The Today predicate is part of the authorized EntityQuery before any task
   * entities are loaded.
   *
   * @return array<int, array<string, mixed>>
   */
  public function todayOpenTasks(
    DateTimeImmutable $nowUtc,
    DateTimeImmutable $todayEndUtc,
  ): array {
    $nowUtc = $nowUtc->setTimezone(new DateTimeZone('UTC'));
    $todayEndUtc = $todayEndUtc->setTimezone(new DateTimeZone('UTC'));
    if ($todayEndUtc <= $nowUtc) {
      throw new InvalidArgumentException('Today task query requires a future local-day end boundary.');
    }

    $user = $this->currentPersistedUser();
    $authorizedIds = $this->householdAuthorization->authorizedHouseholdIds($user);
    $person = $this->currentPersonResolver->resolve($user);
    $eligibleIds = $this->eligibleHouseholdIds($authorizedIds, (int) $person->id());
    if ($eligibleIds === []) {
      return [];
    }

    $timezone = new DateTimeZone($this->timezoneId($user));
    $localToday = $nowUtc->setTimezone($timezone)->format('Y-m-d');
    $todayEndStorage = $todayEndUtc->format('Y-m-d\TH:i:s');

    $storage = $this->entityTypeManager->getStorage(PersonalTask::ENTITY_TYPE_ID);
    $query = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('household', $eligibleIds, 'IN')
      ->condition('assigned_person', (int) $person->id())
      ->condition('status', PersonalTask::STATUS_OPEN);

    $dueToday = $query->orConditionGroup();

    $dateDue = $query->andConditionGroup()
      ->condition('due_mode', PersonalTask::DUE_DATE)
      ->condition('due_date', $localToday, '<=');
    $dueToday->condition($dateDue);

    $dateTimeDue = $query->andConditionGroup()
      ->condition('due_mode', PersonalTask::DUE_DATE_TIME)
      ->condition('due_at', $todayEndStorage, '<');
    $dueToday->condition($dateTimeDue);

    $ids = $query
      ->condition($dueToday)
      ->execute();
    if ($ids === []) {
      return [];
    }

    return $this->loadAndPresent($ids, $user, $nowUtc);
  }

  /**
   * @return array<string, mixed>
   */
  public function present(PersonalTask $task): array {
    return $this->presentation($task, $this->currentPersistedUser());
  }

  /**
   * @param array<int|string, int|string> $ids
   *
   * @return array<int, array<string, mixed>>
   */
  private function loadAndPresent(
    array $ids,
    UserInterface $user,
    ?DateTimeImmutable $nowUtc = NULL,
  ): array {
    $tasks = $this->entityTypeManager
      ->getStorage(PersonalTask::ENTITY_TYPE_ID)
      ->loadMultiple($ids);
    $items = [];
    foreach ($ids as $id) {
      $task = $tasks[$id] ?? NULL;
      if ($task instanceof PersonalTask) {
        $items[] = $this->presentation($task, $user, $nowUtc);
      }
    }

    usort($items, static function (array $left, array $right): int {
      foreach (['sort_bucket', 'sort_due', 'id'] as $key) {
        $comparison = $left[$key] <=> $right[$key];
        if ($comparison !== 0) {
          return $comparison;
        }
      }
      return 0;
    });

    return $items;
  }

  /**
   * @param int[] $authorizedIds
   *
   * @return int[]
   */
  private function eligibleHouseholdIds(array $authorizedIds, int $personId): array {
    if ($authorizedIds === [] || $personId <= 0) {
      return [];
    }

    $households = $this->entityTypeManager
      ->getStorage('personal_secretary_household')
      ->loadMultiple($authorizedIds);
    $eligible = [];
    foreach ($authorizedIds as $id) {
      $household = $households[$id] ?? NULL;
      if (!$household instanceof Household) {
        continue;
      }
      foreach ($household->get('members') as $member) {
        if ((int) ($member->target_id ?? 0) === $personId) {
          $eligible[] = (int) $id;
          break;
        }
      }
    }
    sort($eligible, SORT_NUMERIC);
    return $eligible;
  }

  /**
   * @return array<string, mixed>
   */
  private function presentation(
    PersonalTask $task,
    UserInterface $user,
    ?DateTimeImmutable $nowUtc = NULL,
  ): array {
    $mode = (string) $task->get('due_mode')->value;
    $timezone = new DateTimeZone($this->timezoneId($user));
    $nowUtc ??= (new DateTimeImmutable('@' . $this->time->getCurrentTime()))
      ->setTimezone(new DateTimeZone('UTC'));
    $nowUtc = $nowUtc->setTimezone(new DateTimeZone('UTC'));
    $localToday = $nowUtc->setTimezone($timezone)->format('Y-m-d');

    $dueLabel = '';
    $overdue = FALSE;
    $sortDue = PHP_INT_MAX;

    if ($mode === PersonalTask::DUE_DATE) {
      $dueDate = (string) $task->get('due_date')->value;
      if ($dueDate === '') {
        throw new InvalidArgumentException('Date-only PersonalTask is missing its civil due date.');
      }
      $overdue = $localToday > $dueDate;
      $dueLabel = $dueDate;
      $sortDue = (new DateTimeImmutable($dueDate . ' 23:59:59', $timezone))->getTimestamp();
    }
    elseif ($mode === PersonalTask::DUE_DATE_TIME) {
      $stored = (string) $task->get('due_at')->value;
      $dueUtc = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $stored, new DateTimeZone('UTC'));
      if (!$dueUtc instanceof DateTimeImmutable) {
        throw new InvalidArgumentException('Date-time PersonalTask has an invalid persisted due instant.');
      }
      $overdue = $dueUtc < $nowUtc;
      $dueLabel = $dueUtc->setTimezone($timezone)->format('Y-m-d H:i');
      $sortDue = $dueUtc->getTimestamp();
    }
    elseif ($mode !== PersonalTask::DUE_NONE) {
      throw new InvalidArgumentException('PersonalTask due mode is invalid.');
    }

    return [
      'id' => (int) $task->id(),
      'title' => (string) $task->get('title')->value,
      'due_mode' => $mode,
      'due_label' => $dueLabel,
      'overdue' => $overdue,
      'status' => (string) $task->get('status')->value,
      'sort_bucket' => $overdue ? 0 : ($mode === PersonalTask::DUE_NONE ? 2 : 1),
      'sort_due' => $sortDue,
    ];
  }

  private function currentPersistedUser(): UserInterface {
    if ($this->currentUser->isAnonymous() || (int) $this->currentUser->id() <= 0) {
      throw new InvalidArgumentException('My tasks requires an authenticated Drupal User.');
    }
    $user = $this->entityTypeManager
      ->getStorage('user')
      ->load((int) $this->currentUser->id());
    if (!$user instanceof UserInterface || !$user->isActive()) {
      throw new InvalidArgumentException('My tasks requires an active persisted Drupal User.');
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

}
