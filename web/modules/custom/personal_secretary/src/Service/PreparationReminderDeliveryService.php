<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\personal_secretary\Entity\PreparationReminderDelivery;
use Drupal\personal_secretary\Value\PreparationReminderCandidate;
use Drupal\user\UserInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

final class PreparationReminderDeliveryService {

  public const QUEUE_ID = 'personal_secretary_preparation_reminder';
  public const USER_SCAN_LIMIT = 64;
  public const CANDIDATE_LIMIT = 256;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PreparationReminderCandidateService $candidates,
    private readonly QueueFactory $queueFactory,
    private readonly LockBackendInterface $lock,
    private readonly MailManagerInterface $mailManager,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      PreparationReminderCandidateService::create($container),
      $container->get('queue'),
      $container->get('lock'),
      $container->get('plugin.manager.mail'),
      $container->get('datetime.time'),
      $container->get('config.factory'),
    );
  }

  /** Returns number of enqueued items. */
  public function enqueueDueReminders(): int {
    $storage = $this->entityTypeManager->getStorage('user');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition(PreparationReminderCandidateService::OPT_IN_FIELD, 1)
      ->sort('uid')
      ->range(0, self::USER_SCAN_LIMIT + 1)
      ->execute();
    if (count($ids) > self::USER_SCAN_LIMIT) {
      return 0;
    }

    $candidates = [];
    foreach ($storage->loadMultiple($ids) as $user) {
      if (!$user instanceof UserInterface) {
        continue;
      }
      foreach ($this->candidates->dueForUser($user) as $candidate) {
        $candidates[] = $candidate;
        if (count($candidates) > self::CANDIDATE_LIMIT) {
          return 0;
        }
      }
    }

    $accepted = [];
    foreach ($candidates as $candidate) {
      if (!$this->shouldSuppress($candidate)) {
        $accepted[] = $candidate;
      }
    }

    $queue = $this->queueFactory->get(self::QUEUE_ID);
    foreach ($accepted as $candidate) {
      $queue->createItem($candidate->queuePayload());
    }
    return count($accepted);
  }

  public function processPayload(array $payload): void {
    $candidate = $this->candidates->rederiveExact($payload);
    if (!$candidate instanceof PreparationReminderCandidate) {
      return;
    }
    $lockName = 'personal_secretary:prep-reminder:' . $candidate->identityHash();
    if (!$this->lock->acquire($lockName, 15.0)) {
      return;
    }
    try {
      $candidate = $this->candidates->rederiveExact($payload);
      if (!$candidate instanceof PreparationReminderCandidate || $this->shouldSuppress($candidate)) {
        return;
      }

      $delivery = $this->loadExact($candidate);
      if ($delivery instanceof PreparationReminderDelivery) {
        $state = (string) $delivery->get('state')->value;
        $attempts = (int) $delivery->get('attempt_count')->value;
        if (in_array($state, [PreparationReminderDelivery::STATE_SUBMITTED, PreparationReminderDelivery::STATE_UNKNOWN, PreparationReminderDelivery::STATE_ATTEMPTING], TRUE)) {
          return;
        }
        if ($state !== PreparationReminderDelivery::STATE_KNOWN_NOT_SUBMITTED || $attempts !== 1) {
          return;
        }
      }

      // Revalidate once more immediately before durably claiming the attempt.
      $candidate = $this->candidates->rederiveExact($payload);
      if (!$candidate instanceof PreparationReminderCandidate) {
        return;
      }
      $now = $this->time->getCurrentTime();
      if (!$delivery instanceof PreparationReminderDelivery) {
        $delivery = $this->entityTypeManager->getStorage(PreparationReminderDelivery::ENTITY_TYPE_ID)->create([
          'recipient_user' => $candidate->recipientUserId,
          'series' => $candidate->seriesId,
          'target_revision_id' => $candidate->targetRevisionId,
          'original_occurrence_key' => $candidate->originalOccurrenceKey,
          'preparation_requirement' => $candidate->requirementId,
          'intended_due_at' => $this->storageDate($candidate->intendedDueAtUtc),
          'channel' => PreparationReminderDelivery::CHANNEL_EMAIL,
          'state' => PreparationReminderDelivery::STATE_ATTEMPTING,
          'attempt_count' => 1,
          'last_attempt_at' => $now,
        ]);
      }
      else {
        $delivery->set('state', PreparationReminderDelivery::STATE_ATTEMPTING);
        $delivery->set('attempt_count', 2);
        $delivery->set('last_attempt_at', $now);
        $delivery->set('sanitized_error_category', NULL);
      }
      $delivery->save();

      // No database transaction spans the mail submission. A crash from here to
      // the outcome update leaves ATTEMPTING and therefore suppresses blind replay.
      $candidate = $this->candidates->rederiveExact($payload);
      if (!$candidate instanceof PreparationReminderCandidate) {
        $delivery->set('state', PreparationReminderDelivery::STATE_KNOWN_NOT_SUBMITTED);
        $delivery->set('sanitized_error_category', 'current_truth_changed');
        $delivery->save();
        return;
      }
      if (!$this->localSyntheticMailAllowed()) {
        $delivery->set('state', PreparationReminderDelivery::STATE_KNOWN_NOT_SUBMITTED);
        $delivery->set('sanitized_error_category', 'external_mail_disabled');
        $delivery->save();
        return;
      }

      $user = $this->entityTypeManager->getStorage('user')->load($candidate->recipientUserId);
      if (!$user instanceof UserInterface || trim((string) $user->getEmail()) === '') {
        $delivery->set('state', PreparationReminderDelivery::STATE_KNOWN_NOT_SUBMITTED);
        $delivery->set('sanitized_error_category', 'recipient_invalid');
        $delivery->save();
        return;
      }

      try {
        $result = $this->mailManager->mail(
          'personal_secretary',
          'preparation_reminder',
          $user->getEmail(),
          $user->getPreferredLangcode(),
          ['preparations_url' => '/personal-secretary/preparations/mine'],
          NULL,
          TRUE,
        );
        if (($result['result'] ?? FALSE) === TRUE) {
          $delivery->set('state', PreparationReminderDelivery::STATE_SUBMITTED);
          $delivery->set('submitted_at', $this->time->getCurrentTime());
          $delivery->set('sanitized_error_category', NULL);
        }
        else {
          $delivery->set('state', PreparationReminderDelivery::STATE_UNKNOWN);
          $delivery->set('sanitized_error_category', 'mail_result_ambiguous');
        }
      }
      catch (Throwable) {
        $delivery->set('state', PreparationReminderDelivery::STATE_UNKNOWN);
        $delivery->set('sanitized_error_category', 'mail_exception_ambiguous');
      }
      $delivery->save();
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  public function shouldSuppress(PreparationReminderCandidate $candidate): bool {
    $delivery = $this->loadExact($candidate);
    if (!$delivery instanceof PreparationReminderDelivery) {
      return FALSE;
    }
    $state = (string) $delivery->get('state')->value;
    $attempts = (int) $delivery->get('attempt_count')->value;
    if (in_array($state, [PreparationReminderDelivery::STATE_SUBMITTED, PreparationReminderDelivery::STATE_UNKNOWN, PreparationReminderDelivery::STATE_ATTEMPTING], TRUE)) {
      return TRUE;
    }
    return $state !== PreparationReminderDelivery::STATE_KNOWN_NOT_SUBMITTED || $attempts >= 2;
  }

  private function loadExact(PreparationReminderCandidate $candidate): ?PreparationReminderDelivery {
    $storage = $this->entityTypeManager->getStorage(PreparationReminderDelivery::ENTITY_TYPE_ID);
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('recipient_user', $candidate->recipientUserId)
      ->condition('series', $candidate->seriesId)
      ->condition('target_revision_id', $candidate->targetRevisionId)
      ->condition('original_occurrence_key', $candidate->originalOccurrenceKey)
      ->condition('preparation_requirement', $candidate->requirementId)
      ->condition('intended_due_at', $this->storageDate($candidate->intendedDueAtUtc))
      ->condition('channel', PreparationReminderDelivery::CHANNEL_EMAIL)
      ->execute();
    if (count($ids) > 1) {
      throw new RuntimeException('Multiple reminder delivery rows exist for one exact identity.');
    }
    if ($ids === []) {
      return NULL;
    }
    $delivery = $storage->load(reset($ids));
    return $delivery instanceof PreparationReminderDelivery ? $delivery : NULL;
  }

  private function localSyntheticMailAllowed(): bool {
    if (getenv('IS_DDEV_PROJECT') === 'true') {
      return TRUE;
    }
    return $this->configFactory->get('system.mail')->get('interface.default') === 'test_mail_collector';
  }

  private function storageDate(string $atom): string {
    return (new DateTimeImmutable($atom))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s');
  }
}
