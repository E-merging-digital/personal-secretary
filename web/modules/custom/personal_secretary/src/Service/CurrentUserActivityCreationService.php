<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\Household;
use Drupal\personal_secretary\Entity\Person;
use Drupal\user\UserInterface;
use InvalidArgumentException;

/**
 * Authorizes ordinary current-user activity creation before domain mutation.
 */
final class CurrentUserActivityCreationService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly HouseholdAuthorizationService $householdAuthorization,
    private readonly CurrentPersonResolver $currentPersonResolver,
    private readonly AddActivityService $addActivity,
  ) {}

  /**
   * Returns only Households and Persons eligible for the ordinary-user form.
   *
   * @return array{
   *   current_person: \Drupal\personal_secretary\Entity\Person,
   *   households: array<int, \Drupal\personal_secretary\Entity\Household>,
   *   people: array<int, \Drupal\personal_secretary\Entity\Person>
   * }
   */
  public function creationScope(): array {
    $user = $this->currentPersistedUser();
    $authorizedIds = $this->householdAuthorization->authorizedHouseholdIds($user);
    $currentPerson = $this->freshCurrentPerson($user);
    $currentPersonId = (int) $currentPerson->id();

    if ($authorizedIds === []) {
      return [
        'current_person' => $currentPerson,
        'households' => [],
        'people' => [],
      ];
    }

    $householdStorage = $this->entityTypeManager->getStorage('personal_secretary_household');
    $householdStorage->resetCache($authorizedIds);
    $loaded = $householdStorage->loadMultiple($authorizedIds);

    $eligible = [];
    $eligiblePersonIds = [];
    foreach ($authorizedIds as $householdId) {
      $household = $loaded[$householdId] ?? NULL;
      if (!$household instanceof Household) {
        continue;
      }
      $memberIds = $this->memberIds($household);
      if (!in_array($currentPersonId, $memberIds, TRUE)) {
        continue;
      }
      $eligible[$householdId] = $household;
      foreach ($memberIds as $memberId) {
        $eligiblePersonIds[$memberId] = $memberId;
      }
    }

    if ($eligible === []) {
      return [
        'current_person' => $currentPerson,
        'households' => [],
        'people' => [],
      ];
    }

    $personIds = array_values($eligiblePersonIds);
    sort($personIds, SORT_NUMERIC);
    $personStorage = $this->entityTypeManager->getStorage('personal_secretary_person');
    $personStorage->resetCache($personIds);
    $loadedPeople = $personStorage->loadMultiple($personIds);
    $people = [];
    foreach ($personIds as $personId) {
      $person = $loadedPeople[$personId] ?? NULL;
      if ($person instanceof Person && !$person->isNew() && $person->id() !== NULL) {
        $people[$personId] = $person;
      }
    }

    return [
      'current_person' => $currentPerson,
      'households' => $eligible,
      'people' => $people,
    ];
  }

  public function addWeeklyActivity(
    int $renderedCurrentPersonId,
    int $householdId,
    string $activityLabel,
    DateTimeImmutable $localStart,
    DateTimeImmutable $localEnd,
    string $preparationInstruction = '',
    int $preparationLeadMinutes = 0,
    string $location = '',
    array $concernedPersonIds = [],
    string $timeMode = ActivitySeries::TIME_MODE_TIMED,
  ): ActivitySeries {
    $context = $this->freshAuthorizedContext(
      $renderedCurrentPersonId,
      $householdId,
      $concernedPersonIds,
    );

    return $this->addActivity->addWeeklyActivity(
      $householdId,
      $context['current_person_id'],
      $activityLabel,
      $localStart,
      $localEnd,
      $preparationInstruction,
      $preparationLeadMinutes,
      $location,
      $context['concerned_person_ids'],
      $timeMode,
    );
  }

  public function addOneOffActivity(
    int $renderedCurrentPersonId,
    int $householdId,
    string $activityLabel,
    DateTimeImmutable $localStart,
    DateTimeImmutable $localEnd,
    string $preparationInstruction = '',
    int $preparationLeadMinutes = 0,
    string $location = '',
    array $concernedPersonIds = [],
    string $timeMode = ActivitySeries::TIME_MODE_TIMED,
  ): ActivitySeries {
    $context = $this->freshAuthorizedContext(
      $renderedCurrentPersonId,
      $householdId,
      $concernedPersonIds,
    );

    return $this->addActivity->addOneOffActivity(
      $householdId,
      $context['current_person_id'],
      $activityLabel,
      $localStart,
      $localEnd,
      $preparationInstruction,
      $preparationLeadMinutes,
      $location,
      $context['concerned_person_ids'],
      $timeMode,
    );
  }

  /**
   * @return array{current_person_id: int, concerned_person_ids: int[]}
   */
  private function freshAuthorizedContext(
    int $renderedCurrentPersonId,
    int $householdId,
    array $concernedPersonIds,
  ): array {
    if ($renderedCurrentPersonId <= 0 || $householdId <= 0) {
      throw new InvalidArgumentException('Activity creation context is invalid.');
    }

    $user = $this->currentPersistedUser();
    $authorizedIds = $this->householdAuthorization->authorizedHouseholdIds($user);
    if (!in_array($householdId, $authorizedIds, TRUE)) {
      throw new InvalidArgumentException('The target Household is not currently authorized.');
    }

    $currentPerson = $this->freshCurrentPerson($user);
    $currentPersonId = (int) $currentPerson->id();
    if ($currentPersonId !== $renderedCurrentPersonId) {
      throw new InvalidArgumentException('Current Person changed after the creation form was rendered.');
    }

    $householdStorage = $this->entityTypeManager->getStorage('personal_secretary_household');
    $householdStorage->resetCache([$householdId]);
    $household = $householdStorage->load($householdId);
    if (!$household instanceof Household) {
      throw new InvalidArgumentException('The target Household no longer exists.');
    }

    $memberIds = $this->memberIds($household);
    if (!in_array($currentPersonId, $memberIds, TRUE)) {
      throw new InvalidArgumentException('Current Person is not a current member of the target Household.');
    }

    $concernedIds = $this->normalizePersonIds($concernedPersonIds);
    foreach ($concernedIds as $personId) {
      if (!in_array($personId, $memberIds, TRUE)) {
        throw new InvalidArgumentException('A concerned Person is not a current member of the target Household.');
      }
    }

    if ($concernedIds !== []) {
      $personStorage = $this->entityTypeManager->getStorage('personal_secretary_person');
      $personStorage->resetCache($concernedIds);
      $people = $personStorage->loadMultiple($concernedIds);
      foreach ($concernedIds as $personId) {
        if (!($people[$personId] ?? NULL) instanceof Person) {
          throw new InvalidArgumentException('A concerned Person no longer exists.');
        }
      }
    }

    return [
      'current_person_id' => $currentPersonId,
      'concerned_person_ids' => $concernedIds,
    ];
  }

  private function currentPersistedUser(): UserInterface {
    $uid = (int) $this->currentUser->id();
    if ($this->currentUser->isAnonymous() || $uid <= 0) {
      throw new InvalidArgumentException('Activity creation requires an authenticated Drupal User.');
    }

    $userStorage = $this->entityTypeManager->getStorage('user');
    $userStorage->resetCache([$uid]);
    $user = $userStorage->load($uid);
    if (!$user instanceof UserInterface || !$user->isActive()) {
      throw new InvalidArgumentException('Activity creation requires an active persisted Drupal User.');
    }
    if (!$user->hasPermission(HouseholdAuthorizationService::PRODUCT_USE_PERMISSION)) {
      throw new InvalidArgumentException('Activity creation requires Personal Secretary product access.');
    }

    return $user;
  }

  private function freshCurrentPerson(UserInterface $user): Person {
    $person = $this->currentPersonResolver->resolve($user);
    $personId = (int) $person->id();
    if ($personId <= 0) {
      throw new InvalidArgumentException('Activity creation requires a valid Current Person.');
    }

    $personStorage = $this->entityTypeManager->getStorage('personal_secretary_person');
    $personStorage->resetCache([$personId]);
    $persisted = $personStorage->load($personId);
    if (!$persisted instanceof Person || $persisted->isNew() || $persisted->id() === NULL) {
      throw new InvalidArgumentException('Activity creation requires a current persisted Person.');
    }

    return $persisted;
  }

  /**
   * @return int[]
   */
  private function memberIds(Household $household): array {
    $ids = [];
    foreach ($household->get('members') as $item) {
      $personId = (int) ($item->target_id ?? 0);
      if ($personId > 0) {
        $ids[$personId] = $personId;
      }
    }
    $ids = array_values($ids);
    sort($ids, SORT_NUMERIC);
    return $ids;
  }

  /**
   * @return int[]
   */
  private function normalizePersonIds(array $values): array {
    $ids = [];
    foreach ($values as $value) {
      if (is_string($value) && ctype_digit($value)) {
        $value = (int) $value;
      }
      if (!is_int($value) || $value <= 0) {
        throw new InvalidArgumentException('Concerned Person IDs must be positive integers.');
      }
      $ids[$value] = $value;
    }
    $ids = array_values($ids);
    sort($ids, SORT_NUMERIC);
    return $ids;
  }

}
