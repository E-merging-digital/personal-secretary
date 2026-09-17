<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Value;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\personal_secretary\Entity\ActivitySeries;

/**
 * Application-generated, non-authoritative review proposal.
 */
final readonly class ActivityCaptureProposal {

  public const INTENT_ONE_OFF = 'one_off';
  public const INTENT_WEEKLY = 'weekly';

  /**
   * @param int[] $concernedPersonIds
   *   Server-resolved Person IDs inside authorized scope.
   * @param string[] $clarifications
   *   Deterministic clarification codes blocking confirmation.
   */
  public function __construct(
    public ?int $householdId,
    public ?string $intent,
    public ?string $label,
    public ?string $location,
    public ?string $timeMode,
    public ?string $absoluteDate,
    public ?string $localStartTime,
    public ?string $localEndTime,
    public string $sourceTimezone,
    public ?string $weekday,
    public array $concernedPersonIds,
    public ?int $responsiblePersonId,
    public array $clarifications,
  ) {}

  /**
   * Returns whether the proposal can proceed to explicit user confirmation.
   */
  public function readyForConfirmation(): bool {
    if ($this->clarifications !== []) {
      return FALSE;
    }
    if ($this->householdId === NULL || $this->householdId <= 0) {
      return FALSE;
    }
    if (!in_array($this->intent, [self::INTENT_ONE_OFF, self::INTENT_WEEKLY], TRUE)) {
      return FALSE;
    }
    if ($this->label === NULL || trim($this->label) === '') {
      return FALSE;
    }
    if (!ActivitySeries::supportsTimeMode((string) $this->timeMode)) {
      return FALSE;
    }
    if (!$this->validDate($this->absoluteDate) || !$this->validTimezone($this->sourceTimezone)) {
      return FALSE;
    }
    if ($this->intent === self::INTENT_WEEKLY && ($this->weekday === NULL || $this->responsiblePersonId === NULL)) {
      return FALSE;
    }
    if ($this->timeMode === ActivitySeries::TIME_MODE_TIMED) {
      return $this->validTime($this->localStartTime)
        && $this->validTime($this->localEndTime)
        && $this->localEndTime > $this->localStartTime;
    }

    return $this->localStartTime === NULL && $this->localEndTime === NULL;
  }

  /**
   * Returns review-prefill data without implying mutation authority.
   */
  public function toArray(): array {
    return [
      'household_id' => $this->householdId,
      'intent' => $this->intent,
      'label' => $this->label,
      'location' => $this->location,
      'time_mode' => $this->timeMode,
      'absolute_date' => $this->absoluteDate,
      'local_start_time' => $this->localStartTime,
      'local_end_time' => $this->localEndTime,
      'source_timezone' => $this->sourceTimezone,
      'weekday' => $this->weekday,
      'concerned_person_ids' => $this->concernedPersonIds,
      'responsible_person_id' => $this->responsiblePersonId,
      'clarifications' => $this->clarifications,
      'ready_for_confirmation' => $this->readyForConfirmation(),
    ];
  }

  private function validDate(?string $date): bool {
    if ($date === NULL) {
      return FALSE;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();
    return $parsed !== FALSE
      && ($errors === FALSE || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
      && $parsed->format('Y-m-d') === $date;
  }

  private function validTime(?string $time): bool {
    return $time !== NULL && preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $time) === 1;
  }

  private function validTimezone(string $timezone): bool {
    try {
      new DateTimeZone($timezone);
      return TRUE;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

}
