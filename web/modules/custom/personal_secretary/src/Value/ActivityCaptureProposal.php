<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Value;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Immutable, non-authoritative proposal returned by activity interpretation.
 */
final readonly class ActivityCaptureProposal {

  public const INTENT_ONE_OFF = 'ONE_OFF';
  public const INTENT_WEEKLY = 'WEEKLY';
  public const TIME_MODE_TIMED = 'TIMED';
  public const TIME_MODE_ALL_DAY = 'ALL_DAY';
  public const RESPONSIBILITY_SELF = 'SELF';
  public const RESPONSIBILITY_TEXT = 'TEXT';
  public const RESPONSIBILITY_NONE = 'NONE';

  private const WEEKDAYS = [
    'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY',
    'FRIDAY', 'SATURDAY', 'SUNDAY',
  ];

  public function __construct(
    public string $intent,
    public string $label,
    public ?string $location,
    public string $timeMode,
    public ?string $relativeDateExpression,
    public ?string $absoluteDate,
    public ?string $weekday,
    public ?string $localTime,
    public string $sourceTimezone,
    public array $concernedPersonCandidates,
    public string $responsibility,
    public ?string $responsibilityText,
    public ?string $preparationInstruction,
    public ?string $preparationLead,
    public bool $ambiguous,
    public bool $unsupported,
  ) {}

  public static function structuredJsonSchema(): array {
    return [
      'type' => 'object',
      'additionalProperties' => FALSE,
      'properties' => [
        'intent' => ['type' => 'string', 'enum' => [self::INTENT_ONE_OFF, self::INTENT_WEEKLY]],
        'label' => ['type' => 'string'],
        'location' => ['type' => ['string', 'null']],
        'time_mode' => ['type' => 'string', 'enum' => [self::TIME_MODE_TIMED, self::TIME_MODE_ALL_DAY]],
        'relative_date_expression' => ['type' => ['string', 'null']],
        'absolute_date' => ['type' => ['string', 'null']],
        'weekday' => ['type' => ['string', 'null'], 'enum' => [...self::WEEKDAYS, NULL]],
        'local_time' => ['type' => ['string', 'null']],
        'source_timezone' => ['type' => 'string'],
        'concerned_person_candidates' => ['type' => 'array', 'items' => ['type' => 'string']],
        'responsibility' => ['type' => 'string', 'enum' => [self::RESPONSIBILITY_SELF, self::RESPONSIBILITY_TEXT, self::RESPONSIBILITY_NONE]],
        'responsibility_text' => ['type' => ['string', 'null']],
        'preparation_instruction' => ['type' => ['string', 'null']],
        'preparation_lead' => ['type' => ['string', 'null']],
        'ambiguous' => ['type' => 'boolean'],
        'unsupported' => ['type' => 'boolean'],
      ],
      'required' => [
        'intent', 'label', 'location', 'time_mode', 'relative_date_expression',
        'absolute_date', 'weekday', 'local_time', 'source_timezone',
        'concerned_person_candidates', 'responsibility', 'responsibility_text',
        'preparation_instruction', 'preparation_lead', 'ambiguous', 'unsupported',
      ],
    ];
  }

  public static function fromArray(array $data): self {
    $schema = self::structuredJsonSchema();
    $missing = array_diff($schema['required'], array_keys($data));
    $unknown = array_diff(array_keys($data), array_keys($schema['properties']));
    if ($missing !== [] || $unknown !== []) {
      throw new InvalidArgumentException('Activity capture proposal does not match the bounded schema.');
    }

    $intent = self::requiredEnum($data['intent'], [self::INTENT_ONE_OFF, self::INTENT_WEEKLY], 'intent');
    $label = self::requiredString($data['label'], 'label');
    $location = self::nullableString($data['location'], 'location');
    $timeMode = self::requiredEnum($data['time_mode'], [self::TIME_MODE_TIMED, self::TIME_MODE_ALL_DAY], 'time_mode');
    $relative = self::nullableString($data['relative_date_expression'], 'relative_date_expression');
    $absolute = self::nullableString($data['absolute_date'], 'absolute_date');
    if ($absolute !== NULL && !self::isValidDate($absolute)) {
      throw new InvalidArgumentException('Activity capture absolute_date must be YYYY-MM-DD.');
    }
    $weekday = self::nullableString($data['weekday'], 'weekday');
    if ($weekday !== NULL && !in_array($weekday, self::WEEKDAYS, TRUE)) {
      throw new InvalidArgumentException('Activity capture weekday is invalid.');
    }
    $localTime = self::nullableString($data['local_time'], 'local_time');
    if ($localTime !== NULL && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $localTime) !== 1) {
      throw new InvalidArgumentException('Activity capture local_time must be HH:MM.');
    }
    $sourceTimezone = self::requiredString($data['source_timezone'], 'source_timezone');
    try {
      new DateTimeZone($sourceTimezone);
    }
    catch (\Throwable $e) {
      throw new InvalidArgumentException('Activity capture source_timezone is invalid.', 0, $e);
    }

    if (!is_array($data['concerned_person_candidates'])) {
      throw new InvalidArgumentException('Activity capture concerned_person_candidates must be an array.');
    }
    $candidates = [];
    foreach ($data['concerned_person_candidates'] as $candidate) {
      $candidates[] = self::requiredString($candidate, 'concerned_person_candidates');
    }

    $responsibility = self::requiredEnum($data['responsibility'], [self::RESPONSIBILITY_SELF, self::RESPONSIBILITY_TEXT, self::RESPONSIBILITY_NONE], 'responsibility');
    $responsibilityText = self::nullableString($data['responsibility_text'], 'responsibility_text');
    if ($responsibility === self::RESPONSIBILITY_TEXT && $responsibilityText === NULL) {
      throw new InvalidArgumentException('TEXT responsibility requires responsibility_text.');
    }
    if (!is_bool($data['ambiguous']) || !is_bool($data['unsupported'])) {
      throw new InvalidArgumentException('Activity capture ambiguity and unsupported signals must be boolean.');
    }

    return new self(
      intent: $intent,
      label: $label,
      location: $location,
      timeMode: $timeMode,
      relativeDateExpression: $relative,
      absoluteDate: $absolute,
      weekday: $weekday,
      localTime: $localTime,
      sourceTimezone: $sourceTimezone,
      concernedPersonCandidates: $candidates,
      responsibility: $responsibility,
      responsibilityText: $responsibilityText,
      preparationInstruction: self::nullableString($data['preparation_instruction'], 'preparation_instruction'),
      preparationLead: self::nullableString($data['preparation_lead'], 'preparation_lead'),
      ambiguous: $data['ambiguous'],
      unsupported: $data['unsupported'],
    );
  }

  public function toArray(): array {
    return [
      'intent' => $this->intent,
      'label' => $this->label,
      'location' => $this->location,
      'time_mode' => $this->timeMode,
      'relative_date_expression' => $this->relativeDateExpression,
      'absolute_date' => $this->absoluteDate,
      'weekday' => $this->weekday,
      'local_time' => $this->localTime,
      'source_timezone' => $this->sourceTimezone,
      'concerned_person_candidates' => $this->concernedPersonCandidates,
      'responsibility' => $this->responsibility,
      'responsibility_text' => $this->responsibilityText,
      'preparation_instruction' => $this->preparationInstruction,
      'preparation_lead' => $this->preparationLead,
      'ambiguous' => $this->ambiguous,
      'unsupported' => $this->unsupported,
    ];
  }

  public function containsInternalIdentity(): bool {
    $serialized = json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($serialized)) {
      return TRUE;
    }
    return preg_match('/\b(?:person_id|household_id|uuid)\b|[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i', $serialized) === 1;
  }

  private static function requiredString(mixed $value, string $field): string {
    if (!is_string($value) || trim($value) === '') {
      throw new InvalidArgumentException("Activity capture {$field} must be a non-empty string.");
    }
    return trim($value);
  }

  private static function nullableString(mixed $value, string $field): ?string {
    if ($value === NULL) {
      return NULL;
    }
    if (!is_string($value)) {
      throw new InvalidArgumentException("Activity capture {$field} must be a string or null.");
    }
    $value = trim($value);
    return $value === '' ? NULL : $value;
  }

  private static function requiredEnum(mixed $value, array $allowed, string $field): string {
    if (!is_string($value) || !in_array($value, $allowed, TRUE)) {
      throw new InvalidArgumentException("Activity capture {$field} has an unsupported value.");
    }
    return $value;
  }

  private static function isValidDate(string $date): bool {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();
    return $parsed !== FALSE
      && ($errors === FALSE || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
      && $parsed->format('Y-m-d') === $date;
  }

}
