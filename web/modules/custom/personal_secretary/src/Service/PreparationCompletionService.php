<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\PreparationCompletion;
use Drupal\personal_secretary\Value\EffectiveResponsibility;
use Drupal\personal_secretary\Value\PreparationEligibility;
use Drupal\user\UserInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Governs the sparse completion overlay for exact derived preparations.
 */
final class PreparationCompletionService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly HouseholdAuthorizationService $householdAuthorization,
    private readonly CurrentPersonResolver $currentPersonResolver,
    private readonly CurrentEffectiveOccurrenceResolver $currentEffectiveOccurrence,
    private readonly EffectiveResponsibilityService $effectiveResponsibility,
    private readonly PreparationEligibilityService $preparationEligibility,
    private readonly TimeInterface $time,
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * Overlays sparse completion truth onto already-authorized derived candidates.
   *
   * @param array<int, array<string, mixed>> $candidates
   *
   * @return array<int, array<string, mixed>>
   */
  public function overlayCandidates(array $candidates): array {
    if ($candidates === []) {
      return [];
    }

    $candidateIndexes = [];
    $seriesIds = [];
    $revisionIds = [];
    $occurrenceKeys = [];
    $requirementIds = [];
    $personIds = [];

    foreach ($candidates as $index => $candidate) {
      $values = $this->candidateValues($candidate);
      $key = $this->semanticKey(...$values);
      if (isset($candidateIndexes[$key])) {
        throw new RuntimeException('Derived preparation candidates contain a duplicate completion identity.');
      }
      $candidateIndexes[$key] = $index;
      $seriesIds[$values[0]] = $values[0];
      $revisionIds[$values[1]] = $values[1];
      $occurrenceKeys[$values[2]] = $values[2];
      $requirementIds[$values[3]] = $values[3];
      $personIds[$values[4]] = $values[4];
    }

    $storage = $this->entityTypeManager->getStorage(PreparationCompletion::ENTITY_TYPE_ID);
    $ids = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('series', array_values($seriesIds), 'IN')
      ->condition('target_revision_id', array_values($revisionIds), 'IN')
      ->condition('original_occurrence_key', array_values($occurrenceKeys), 'IN')
      ->condition('preparation_requirement', array_values($requirementIds), 'IN')
      ->condition('responsible_person', array_values($personIds), 'IN')
      ->execute();

    $matched = [];
    foreach ($storage->loadMultiple($ids) as $completion) {
      if (!$completion instanceof PreparationCompletion) {
        throw new RuntimeException('PreparationCompletion query returned an unexpected entity type.');
      }
      $key = $this->semanticKey(...$this->completionValues($completion));
      if (!isset($candidateIndexes[$key])) {
        continue;
      }
      if (isset($matched[$key])) {
        throw new RuntimeException('Multiple PreparationCompletion rows exist for one exact preparation identity.');
      }
      $matched[$key] = $completion;
    }

    foreach ($candidates as $index => $candidate) {
      $key = $this->semanticKey(...$this->candidateValues($candidate));
      $completion = $matched[$key] ?? NULL;
      $candidate['prepared'] = $completion instanceof PreparationCompletion;
      $candidate['prepared_time'] = NULL;
      $candidate['prepared_time_iso'] = NULL;
      if ($completion instanceof PreparationCompletion) {
        $preparedAt = (int) $completion->get('prepared_at')->value;
        if ($preparedAt <= 0) {
          throw new RuntimeException('PreparationCompletion has an invalid prepared timestamp.');
        }
        $timezoneId = trim((string) ($candidate['display_timezone'] ?? ''));
        if ($timezoneId === '') {
          throw new RuntimeException('Derived preparation candidate has no display timezone.');
        }
        $preparedLocal = (new DateTimeImmutable('@' . $preparedAt))
          ->setTimezone(new DateTimeZone($timezoneId));
        $candidate['prepared_time'] = $preparedLocal->format('Y-m-d H:i');
        $candidate['prepared_time_iso'] = $preparedLocal->format(DateTimeInterface::ATOM);
      }
      $candidates[$index] = $candidate;
    }

    return array_values($candidates);
  }

  /**
   * Returns current authorized presentation state for one exact preparation.
   *
   * @return array{instruction:string, prepared:bool}
   */
  public function describeCurrentPreparation(
    int $seriesId,
    string $originalOccurrenceKey,
    int $requirementId,
  ): array {
    $context = $this->resolveAuthorizedPreparation($seriesId, $originalOccurrenceKey, $requirementId);
    $values = $this->contextValues($context);
    $rows = $this->exactCompletions(...$values);
    if (count($rows) > 1) {
      throw new RuntimeException('Multiple PreparationCompletion rows exist for one exact preparation identity.');
    }

    return [
      'instruction' => $context['preparation']->requirementLabel,
      'prepared' => count($rows) === 1,
    ];
  }

  /**
   * Marks one exact current preparation prepared. Returns TRUE on creation.
   */
  public function markPrepared(
    int $seriesId,
    string $originalOccurrenceKey,
    int $requirementId,
  ): bool {
    $context = $this->resolveAuthorizedPreparation($seriesId, $originalOccurrenceKey, $requirementId);
    $values = $this->contextValues($context);
    $lockName = $this->lockName($values);
    if (!$this->lock->acquire($lockName, 5.0)) {
      throw new RuntimeException('PreparationCompletion mutation lock is unavailable.');
    }

    try {
      // Re-resolve under the lock so authorization and derived truth are current at
      // the actual write boundary.
      $context = $this->resolveAuthorizedPreparation($seriesId, $originalOccurrenceKey, $requirementId);
      $lockedValues = $this->contextValues($context);
      if ($this->semanticKey(...$lockedValues) !== $this->semanticKey(...$values)) {
        throw new InvalidArgumentException('Preparation identity changed before completion could be written.');
      }

      $rows = $this->exactCompletions(...$lockedValues);
      if (count($rows) > 1) {
        throw new RuntimeException('Multiple PreparationCompletion rows exist for one exact preparation identity.');
      }
      if (count($rows) === 1) {
        return FALSE;
      }

      $completion = $this->entityTypeManager
        ->getStorage(PreparationCompletion::ENTITY_TYPE_ID)
        ->create([
          'series' => ['target_id' => $lockedValues[0]],
          'target_revision_id' => $lockedValues[1],
          'original_occurrence_key' => $lockedValues[2],
          'preparation_requirement' => ['target_id' => $lockedValues[3]],
          'responsible_person' => ['target_id' => $lockedValues[4]],
          'prepared_at' => $this->time->getCurrentTime(),
          'prepared_by_user' => ['target_id' => (int) $context['user']->id()],
        ]);
      if (!$completion instanceof PreparationCompletion) {
        throw new RuntimeException('Unable to create PreparationCompletion.');
      }
      $completion->save();
      return TRUE;
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Removes one exact current completion. Returns TRUE on deletion.
   */
  public function markNotPrepared(
    int $seriesId,
    string $originalOccurrenceKey,
    int $requirementId,
  ): bool {
    $context = $this->resolveAuthorizedPreparation($seriesId, $originalOccurrenceKey, $requirementId);
    $values = $this->contextValues($context);
    $lockName = $this->lockName($values);
    if (!$this->lock->acquire($lockName, 5.0)) {
      throw new RuntimeException('PreparationCompletion mutation lock is unavailable.');
    }

    try {
      $context = $this->resolveAuthorizedPreparation($seriesId, $originalOccurrenceKey, $requirementId);
      $lockedValues = $this->contextValues($context);
      if ($this->semanticKey(...$lockedValues) !== $this->semanticKey(...$values)) {
        throw new InvalidArgumentException('Preparation identity changed before completion could be removed.');
      }

      $rows = $this->exactCompletions(...$lockedValues);
      if (count($rows) > 1) {
        throw new RuntimeException('Multiple PreparationCompletion rows exist for one exact preparation identity.');
      }
      if ($rows === []) {
        return FALSE;
      }

      $this->entityTypeManager
        ->getStorage(PreparationCompletion::ENTITY_TYPE_ID)
        ->delete($rows);
      return TRUE;
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * @return array{
   *   user:\Drupal\user\UserInterface,
   *   person:\Drupal\personal_secretary\Entity\Person,
   *   series:\Drupal\personal_secretary\Entity\ActivitySeries,
   *   occurrence:\Drupal\personal_secretary\Value\EffectiveOccurrence,
   *   preparation:\Drupal\personal_secretary\Value\PreparationEligibility
   * }
   */
  private function resolveAuthorizedPreparation(
    int $seriesId,
    string $originalOccurrenceKey,
    int $requirementId,
  ): array {
    $originalOccurrenceKey = trim($originalOccurrenceKey);
    if ($seriesId <= 0 || $requirementId <= 0 || $originalOccurrenceKey === '') {
      throw new InvalidArgumentException('Preparation target identity is invalid.');
    }

    $user = $this->currentPersistedUser();
    $authorizedHouseholdIds = array_map(
      'intval',
      $this->householdAuthorization->authorizedHouseholdIds($user),
    );
    if ($authorizedHouseholdIds === []) {
      throw new InvalidArgumentException('Preparation completion requires an authorized Household.');
    }

    $person = $this->currentPersonResolver->resolve($user);
    if ($person->id() === NULL || $person->uuid() === '') {
      throw new InvalidArgumentException('Preparation completion requires a valid CurrentPerson.');
    }

    $series = $this->entityTypeManager
      ->getStorage('personal_sec_activity_series')
      ->load($seriesId);
    if (!$series instanceof ActivitySeries || $series->id() === NULL) {
      throw new InvalidArgumentException('Preparation ActivitySeries does not exist.');
    }
    $householdId = (int) ($series->get('household')->target_id ?? 0);
    if (!in_array($householdId, $authorizedHouseholdIds, TRUE)) {
      throw new InvalidArgumentException('Preparation ActivitySeries Household is not authorized.');
    }
    $this->householdAuthorization->requireAuthorized($user, $householdId);

    $resolved = $this->currentEffectiveOccurrence->resolve($seriesId, $originalOccurrenceKey);
    if ((int) $resolved['series']->id() !== $seriesId) {
      throw new RuntimeException('Resolved preparation occurrence crossed its ActivitySeries boundary.');
    }
    $occurrence = $resolved['occurrence'];
    $effectiveStart = (new DateTimeImmutable($occurrence->effectiveUtcStart))
      ->setTimezone(new DateTimeZone('UTC'));
    if ($effectiveStart <= $this->nowUtc()) {
      throw new InvalidArgumentException('Past or started preparations cannot be completed.');
    }

    $responsibility = $this->effectiveResponsibility->resolve($series, $occurrence);
    if (
      $responsibility->state !== EffectiveResponsibility::STATE_ASSIGNED
      || $responsibility->responsiblePersonId !== (int) $person->id()
      || $responsibility->responsiblePersonUuid !== $person->uuid()
    ) {
      throw new InvalidArgumentException('Preparation is not currently assigned to CurrentPerson.');
    }

    $matches = array_values(array_filter(
      $this->preparationEligibility->deriveForResponsibility($series, $occurrence, $responsibility),
      static fn(PreparationEligibility $preparation): bool => $preparation->requirementId === $requirementId,
    ));
    if (count($matches) !== 1) {
      throw new InvalidArgumentException('Preparation requirement is not currently eligible for this occurrence.');
    }
    $preparation = $matches[0];
    if (
      $preparation->seriesRevisionId !== $occurrence->seriesRevisionId
      || $preparation->originalOccurrenceKey !== $occurrence->originalOccurrenceKey
      || $preparation->responsiblePersonId !== (int) $person->id()
      || $preparation->responsiblePersonUuid !== $person->uuid()
    ) {
      throw new RuntimeException('Derived preparation identity is inconsistent with current domain truth.');
    }

    return [
      'user' => $user,
      'person' => $person,
      'series' => $series,
      'occurrence' => $occurrence,
      'preparation' => $preparation,
    ];
  }

  private function currentPersistedUser(): UserInterface {
    if ($this->currentUser->isAnonymous() || (int) $this->currentUser->id() <= 0) {
      throw new InvalidArgumentException('Preparation completion requires an authenticated Drupal User.');
    }
    $user = $this->entityTypeManager
      ->getStorage('user')
      ->load((int) $this->currentUser->id());
    if (!$user instanceof UserInterface || !$user->isActive()) {
      throw new InvalidArgumentException('Preparation completion requires an active persisted Drupal User.');
    }
    return $user;
  }

  private function nowUtc(): DateTimeImmutable {
    return (new DateTimeImmutable('@' . $this->time->getCurrentTime()))
      ->setTimezone(new DateTimeZone('UTC'));
  }

  /**
   * @param array<string, mixed> $candidate
   *
   * @return array{0:int,1:int,2:string,3:int,4:int}
   */
  private function candidateValues(array $candidate): array {
    $values = [
      (int) ($candidate['_completion_series_id'] ?? 0),
      (int) ($candidate['_completion_target_revision_id'] ?? 0),
      trim((string) ($candidate['_completion_original_occurrence_key'] ?? '')),
      (int) ($candidate['_completion_requirement_id'] ?? 0),
      (int) ($candidate['_completion_responsible_person_id'] ?? 0),
    ];
    $this->validateValues(...$values);
    return $values;
  }

  /**
   * @return array{0:int,1:int,2:string,3:int,4:int}
   */
  private function completionValues(PreparationCompletion $completion): array {
    $values = [
      (int) $completion->get('series')->target_id,
      (int) $completion->get('target_revision_id')->value,
      trim((string) $completion->get('original_occurrence_key')->value),
      (int) $completion->get('preparation_requirement')->target_id,
      (int) $completion->get('responsible_person')->target_id,
    ];
    $this->validateValues(...$values);
    return $values;
  }

  /**
   * @param array<string, mixed> $context
   *
   * @return array{0:int,1:int,2:string,3:int,4:int}
   */
  private function contextValues(array $context): array {
    /** @var \Drupal\personal_secretary\Entity\ActivitySeries $series */
    $series = $context['series'];
    /** @var \Drupal\personal_secretary\Value\PreparationEligibility $preparation */
    $preparation = $context['preparation'];
    $values = [
      (int) $series->id(),
      (int) $preparation->seriesRevisionId,
      $preparation->originalOccurrenceKey,
      $preparation->requirementId,
      $preparation->responsiblePersonId,
    ];
    $this->validateValues(...$values);
    return $values;
  }

  /**
   * @return \Drupal\personal_secretary\Entity\PreparationCompletion[]
   */
  private function exactCompletions(
    int $seriesId,
    int $targetRevisionId,
    string $originalOccurrenceKey,
    int $requirementId,
    int $responsiblePersonId,
  ): array {
    $this->validateValues($seriesId, $targetRevisionId, $originalOccurrenceKey, $requirementId, $responsiblePersonId);
    $storage = $this->entityTypeManager->getStorage(PreparationCompletion::ENTITY_TYPE_ID);
    $ids = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('series', $seriesId)
      ->condition('target_revision_id', $targetRevisionId)
      ->condition('original_occurrence_key', $originalOccurrenceKey)
      ->condition('preparation_requirement', $requirementId)
      ->condition('responsible_person', $responsiblePersonId)
      ->execute();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $completion) {
      if (!$completion instanceof PreparationCompletion) {
        throw new RuntimeException('PreparationCompletion query returned an unexpected entity type.');
      }
      $rows[] = $completion;
    }
    return $rows;
  }

  private function semanticKey(
    int $seriesId,
    int $targetRevisionId,
    string $originalOccurrenceKey,
    int $requirementId,
    int $responsiblePersonId,
  ): string {
    $this->validateValues($seriesId, $targetRevisionId, $originalOccurrenceKey, $requirementId, $responsiblePersonId);
    return json_encode(
      [$seriesId, $targetRevisionId, $originalOccurrenceKey, $requirementId, $responsiblePersonId],
      JSON_THROW_ON_ERROR,
    );
  }

  /**
   * @param array{0:int,1:int,2:string,3:int,4:int} $values
   */
  private function lockName(array $values): string {
    return 'personal_secretary.preparation_completion.' . hash('sha256', $this->semanticKey(...$values));
  }

  private function validateValues(
    int $seriesId,
    int $targetRevisionId,
    string $originalOccurrenceKey,
    int $requirementId,
    int $responsiblePersonId,
  ): void {
    if (
      $seriesId <= 0
      || $targetRevisionId <= 0
      || trim($originalOccurrenceKey) === ''
      || $requirementId <= 0
      || $responsiblePersonId <= 0
    ) {
      throw new RuntimeException('Preparation completion semantic identity is invalid.');
    }
  }

}
