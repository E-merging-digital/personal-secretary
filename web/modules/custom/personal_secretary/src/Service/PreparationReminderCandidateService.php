<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\personal_secretary\Value\PreparationReminderCandidate;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use InvalidArgumentException;

/**
 * Derives current preparation reminder candidates without persisting them.
 */
final class PreparationReminderCandidateService {

  public const OPT_IN_FIELD = 'field_personal_sec_prep_reminder';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CurrentUserPreparationService $preparations,
    private readonly TimeInterface $time,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('personal_secretary.current_user_preparation'),
      $container->get('datetime.time'),
    );
  }

  /** @return \Drupal\personal_secretary\Value\PreparationReminderCandidate[] */
  public function dueForUser(UserInterface $user, ?DateTimeImmutable $nowUtc = NULL): array {
    $user = $this->currentRecipient($user);
    if ($user === NULL) {
      return [];
    }
    $nowUtc = $nowUtc ?? (new DateTimeImmutable('@' . $this->time->getCurrentTime()))
      ->setTimezone(new DateTimeZone('UTC'));
    $nowUtc = $nowUtc->setTimezone(new DateTimeZone('UTC'));

    try {
      $model = $this->preparations->mineForUser($user, $nowUtc);
    }
    catch (InvalidArgumentException) {
      return [];
    }

    $candidates = [];
    foreach ($model['items'] as $item) {
      if ((bool) ($item['prepared'] ?? FALSE)) {
        continue;
      }
      $dueAt = new DateTimeImmutable((string) ($item['_reminder_due_at_utc'] ?? ''));
      $startAt = new DateTimeImmutable((string) ($item['_reminder_effective_start_utc'] ?? ''));
      if ($dueAt > $nowUtc || $startAt <= $nowUtc) {
        continue;
      }
      $candidate = new PreparationReminderCandidate(
        (int) $user->id(),
        (int) ($item['_completion_series_id'] ?? 0),
        (string) ($item['_reminder_series_uuid'] ?? ''),
        (int) ($item['_completion_target_revision_id'] ?? 0),
        (string) ($item['_completion_original_occurrence_key'] ?? ''),
        (int) ($item['_completion_requirement_id'] ?? 0),
        (string) ($item['_reminder_requirement_uuid'] ?? ''),
        $dueAt->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
      );
      if (
        $candidate->seriesId <= 0
        || $candidate->seriesUuid === ''
        || $candidate->targetRevisionId <= 0
        || $candidate->originalOccurrenceKey === ''
        || $candidate->requirementId <= 0
        || $candidate->requirementUuid === ''
      ) {
        throw new InvalidArgumentException('Derived reminder candidate identity is incomplete.');
      }
      $candidates[] = $candidate;
    }
    return $candidates;
  }

  public function rederiveExact(array $payload, ?DateTimeImmutable $nowUtc = NULL): ?PreparationReminderCandidate {
    $uid = (int) ($payload['recipient_user_id'] ?? 0);
    if ($uid <= 0) {
      return NULL;
    }
    $userStorage = $this->entityTypeManager->getStorage('user');
    $userStorage->resetCache([$uid]);
    $user = $userStorage->load($uid);
    if (!$user instanceof UserInterface) {
      return NULL;
    }
    foreach ($this->dueForUser($user, $nowUtc) as $candidate) {
      if (
        $candidate->seriesId === (int) ($payload['series_id'] ?? 0)
        && $candidate->targetRevisionId === (int) ($payload['target_revision_id'] ?? 0)
        && hash_equals($candidate->originalOccurrenceKey, trim((string) ($payload['original_occurrence_key'] ?? '')))
        && $candidate->requirementId === (int) ($payload['requirement_id'] ?? 0)
        && hash_equals($candidate->intendedDueAtUtc, trim((string) ($payload['intended_due_at_utc'] ?? '')))
      ) {
        return $candidate;
      }
    }
    return NULL;
  }

  private function currentRecipient(UserInterface $user): ?UserInterface {
    $uid = (int) $user->id();
    if ($uid <= 0) {
      return NULL;
    }
    $persisted = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$persisted instanceof UserInterface || !$persisted->isActive()) {
      return NULL;
    }
    if (!$persisted->hasPermission(HouseholdAuthorizationService::PRODUCT_USE_PERMISSION)) {
      return NULL;
    }
    if (!$persisted->hasField(self::OPT_IN_FIELD) || !(bool) $persisted->get(self::OPT_IN_FIELD)->value) {
      return NULL;
    }
    if (trim((string) $persisted->getEmail()) === '') {
      return NULL;
    }
    return $persisted;
  }

}
