<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Value;

final readonly class PreparationReminderCandidate {

  public const KIND = 'PREPARATION_DUE';
  public const CHANNEL = 'EMAIL';

  public function __construct(
    public int $recipientUserId,
    public int $seriesId,
    public string $seriesUuid,
    public int $targetRevisionId,
    public string $originalOccurrenceKey,
    public int $requirementId,
    public string $requirementUuid,
    public string $intendedDueAtUtc,
  ) {}

  public function queuePayload(): array {
    return [
      'recipient_user_id' => $this->recipientUserId,
      'series_id' => $this->seriesId,
      'target_revision_id' => $this->targetRevisionId,
      'original_occurrence_key' => $this->originalOccurrenceKey,
      'requirement_id' => $this->requirementId,
      'intended_due_at_utc' => $this->intendedDueAtUtc,
    ];
  }

  public function identityMaterial(): string {
    return implode('|', [
      (string) $this->recipientUserId,
      $this->seriesUuid,
      (string) $this->targetRevisionId,
      $this->originalOccurrenceKey,
      $this->requirementUuid,
      self::KIND,
      $this->intendedDueAtUtc,
      self::CHANNEL,
    ]);
  }

  public function identityHash(): string {
    return hash('sha256', $this->identityMaterial());
  }

}
