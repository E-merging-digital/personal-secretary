<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\personal_secretary\Entity\ActivityException;
use Drupal\personal_secretary\Entity\Household;
use Drupal\personal_secretary\Entity\Person;
use Drupal\personal_secretary\Value\EffectiveOccurrence;
use Drupal\personal_secretary\Value\EffectiveResponsibility;
use Drupal\user\UserInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Authorizes current-user cancellation before reusing domain cancellation.
 */
final class CurrentUserOccurrenceCancellationService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly HouseholdAuthorizationService $householdAuthorization,
    private readonly CurrentPersonResolver $currentPersonResolver,
    private readonly CancelOccurrenceService $cancelOccurrence,
    private readonly EffectiveOccurrenceProjectionService $effectiveOccurrences,
    private readonly EffectiveResponsibilityService $effectiveResponsibility,
  ) {}

  /**
   * Authorizes an exact target before confirmation details are rendered.
   *
   * @return array{
   *   series: \Drupal\personal_secretary\Entity\ActivitySeries,
   *   occurrence: \Drupal\personal_secretary\Value\BaseOccurrence
   * }
   */
  public function authorize(int $seriesId, string $originalOccurrenceKey): array {
    $user = $this->currentPersistedUser();
    $resolved = $this->cancelOccurrence->resolve($seriesId, $originalOccurrenceKey);

    if ($user->hasPermission(HouseholdAuthorizationService::ADMIN_PERMISSION)) {
      return $resolved;
    }

    try {
      $householdId = (int) ($resolved['series']->get('household')->target_id ?? 0);
      if ($householdId <= 0) {
        throw new InvalidArgumentException('Cancellation target has no valid Household.');
      }

      $authorizedIds = $this->householdAuthorization->authorizedHouseholdIds($user);
      if (!in_array($householdId, $authorizedIds, TRUE)) {
        throw new InvalidArgumentException('Cancellation target Household is not authorized.');
      }

      $currentPerson = $this->freshCurrentPerson($user);
      $currentPersonId = (int) $currentPerson->id();
      $household = $this->freshHousehold($householdId);
      if (!in_array($currentPersonId, $this->memberIds($household), TRUE)) {
        throw new InvalidArgumentException('Current Person is not a current target-Household member.');
      }

      $effectiveOccurrence = $this->exactEffectiveOccurrence(
        $resolved['series'],
        $resolved['occurrence'],
      );
      $responsibility = $this->effectiveResponsibility->resolve(
        $resolved['series'],
        $effectiveOccurrence,
      );
      if (
        $responsibility->state !== EffectiveResponsibility::STATE_ASSIGNED
        || $responsibility->responsiblePersonId !== $currentPersonId
      ) {
        throw new InvalidArgumentException('Current Person is not currently responsible for the target occurrence.');
      }
    }
    catch (InvalidArgumentException|RuntimeException $exception) {
      throw new InvalidArgumentException(
        'The requested occurrence is not currently authorized for cancellation.',
        0,
        $exception,
      );
    }

    return $resolved;
  }

  public function cancel(int $seriesId, string $originalOccurrenceKey): ActivityException {
    $this->authorize($seriesId, $originalOccurrenceKey);

    return $this->cancelOccurrence->cancel($seriesId, $originalOccurrenceKey);
  }

  private function currentPersistedUser(): UserInterface {
    $uid = (int) $this->currentUser->id();
    if ($this->currentUser->isAnonymous() || $uid <= 0) {
      throw new InvalidArgumentException('Occurrence cancellation requires an authenticated Drupal User.');
    }

    $storage = $this->entityTypeManager->getStorage('user');
    $storage->resetCache([$uid]);
    $user = $storage->load($uid);
    if (!$user instanceof UserInterface || !$user->isActive()) {
      throw new InvalidArgumentException('Occurrence cancellation requires an active persisted Drupal User.');
    }
    if (
      !$user->hasPermission(HouseholdAuthorizationService::ADMIN_PERMISSION)
      && !$user->hasPermission(HouseholdAuthorizationService::PRODUCT_USE_PERMISSION)
    ) {
      throw new InvalidArgumentException('Occurrence cancellation requires Personal Secretary product access.');
    }

    return $user;
  }

  private function freshCurrentPerson(UserInterface $user): Person {
    try {
      $person = $this->currentPersonResolver->resolve($user);
    }
    catch (InvalidArgumentException|RuntimeException $exception) {
      throw new InvalidArgumentException('Occurrence cancellation requires a valid Current Person.', 0, $exception);
    }

    $personId = (int) $person->id();
    if ($personId <= 0) {
      throw new InvalidArgumentException('Occurrence cancellation requires a persisted Current Person.');
    }

    $storage = $this->entityTypeManager->getStorage('personal_secretary_person');
    $storage->resetCache([$personId]);
    $persisted = $storage->load($personId);
    if (!$persisted instanceof Person || $persisted->isNew() || $persisted->id() === NULL) {
      throw new InvalidArgumentException('Occurrence cancellation requires a current persisted Person.');
    }

    return $persisted;
  }

  private function freshHousehold(int $householdId): Household {
    $storage = $this->entityTypeManager->getStorage('personal_secretary_household');
    $storage->resetCache([$householdId]);
    $household = $storage->load($householdId);
    if (!$household instanceof Household || $household->isNew() || $household->id() === NULL) {
      throw new InvalidArgumentException('Occurrence cancellation target Household is unavailable.');
    }
    return $household;
  }

  private function exactEffectiveOccurrence(
    \Drupal\personal_secretary\Entity\ActivitySeries $series,
    \Drupal\personal_secretary\Value\BaseOccurrence $target,
  ): EffectiveOccurrence {
    $instant = new DateTimeImmutable($target->utcStart);
    $matches = array_values(array_filter(
      $this->effectiveOccurrences->project(
        $series,
        $instant->modify('-1 second'),
        $instant->modify('+1 second'),
      ),
      static fn(EffectiveOccurrence $candidate): bool =>
        (string) $candidate->seriesRevisionId === (string) $target->seriesRevisionId
        && $candidate->originalOccurrenceKey === $target->originalOccurrenceKey
        && $candidate->exceptionUuid === NULL,
    ));

    if (count($matches) !== 1) {
      throw new InvalidArgumentException('Occurrence target is no longer one exact cancellable EffectiveOccurrence.');
    }

    return $matches[0];
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

}
