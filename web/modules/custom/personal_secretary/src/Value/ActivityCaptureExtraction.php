<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Value;

use InvalidArgumentException;

/**
 * Narrow, non-authoritative linguistic extraction returned by local AI.
 */
final readonly class ActivityCaptureExtraction {

  public const RESPONSIBILITY_SELF = 'SELF';

  /**
   * @param string[] $concernedPersonMentions
   *   Explicit textual Person mentions only.
   */
  public function __construct(
    public ?string $labelText,
    public ?string $locationText,
    public array $concernedPersonMentions,
    public bool $concernedPersonAlternative,
    public ?string $responsibilityCandidate,
    public ?string $dateExpression,
    public ?int $dateDay,
    public ?int $dateMonth,
    public ?int $dateYear,
    public ?int $relativeDayOffset,
    public ?string $startTimeExpression,
    public ?string $endTimeExpression,
    public ?string $recurrenceExpression,
    public bool $explicitAllDaySignal,
    public bool $explicitTimedSignal,
  ) {}

  /**
   * Returns the bounded model-output schema.
   */
  public static function structuredJsonSchema(): array {
    return [
      'type' => 'object',
      'additionalProperties' => FALSE,
      'properties' => [
        'label_text' => ['type' => ['string', 'null']],
        'location_text' => ['type' => ['string', 'null']],
        'concerned_person_mentions' => [
          'type' => 'array',
          'items' => ['type' => 'string'],
        ],
        'concerned_person_alternative' => ['type' => 'boolean'],
        'responsibility_candidate' => ['type' => ['string', 'null']],
        'date_expression' => ['type' => ['string', 'null']],
        'date_day' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 31],
        'date_month' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 12],
        'date_year' => ['type' => ['integer', 'null'], 'minimum' => 1970, 'maximum' => 2200],
        'relative_day_offset' => [
          'type' => ['integer', 'null'],
          'minimum' => -366,
          'maximum' => 366,
        ],
        'start_time_expression' => ['type' => ['string', 'null']],
        'end_time_expression' => ['type' => ['string', 'null']],
        'recurrence_expression' => ['type' => ['string', 'null']],
        'explicit_all_day_signal' => ['type' => 'boolean'],
        'explicit_timed_signal' => ['type' => 'boolean'],
      ],
      'required' => [
        'label_text',
        'location_text',
        'concerned_person_mentions',
        'concerned_person_alternative',
        'responsibility_candidate',
        'date_expression',
        'date_day',
        'date_month',
        'date_year',
        'relative_day_offset',
        'start_time_expression',
        'end_time_expression',
        'recurrence_expression',
        'explicit_all_day_signal',
        'explicit_timed_signal',
      ],
    ];
  }

  /**
   * Builds one validated narrow extraction from model output.
   */
  public static function fromArray(array $data): self {
    $schema = self::structuredJsonSchema();
    $missing = array_diff($schema['required'], array_keys($data));
    $unknown = array_diff(array_keys($data), array_keys($schema['properties']));
    if ($missing !== [] || $unknown !== []) {
      throw new InvalidArgumentException('Activity capture extraction does not match the bounded schema.');
    }

    if (!is_array($data['concerned_person_mentions'])) {
      throw new InvalidArgumentException('Activity capture concerned_person_mentions must be an array.');
    }
    $mentions = [];
    foreach ($data['concerned_person_mentions'] as $mention) {
      $mentions[] = self::requiredString($mention, 'concerned_person_mentions');
    }

    if (!is_bool($data['concerned_person_alternative'])) {
      throw new InvalidArgumentException('Activity capture concerned_person_alternative must be boolean.');
    }
    if (!is_bool($data['explicit_all_day_signal']) || !is_bool($data['explicit_timed_signal'])) {
      throw new InvalidArgumentException('Activity capture time-mode signals must be boolean.');
    }

    foreach (['date_day' => [1, 31], 'date_month' => [1, 12], 'date_year' => [1970, 2200]] as $field => [$min, $max]) {
      $value = $data[$field];
      if ($value !== NULL && (!is_int($value) || $value < $min || $value > $max)) {
        throw new InvalidArgumentException("Activity capture {$field} is outside the bounded range.");
      }
    }

    $offset = $data['relative_day_offset'];
    if ($offset !== NULL && (!is_int($offset) || $offset < -366 || $offset > 366)) {
      throw new InvalidArgumentException('Activity capture relative_day_offset is outside the bounded range.');
    }

    $responsibility = self::nullableString($data['responsibility_candidate'], 'responsibility_candidate');
    if ($responsibility !== NULL && strcasecmp($responsibility, self::RESPONSIBILITY_SELF) === 0) {
      $responsibility = self::RESPONSIBILITY_SELF;
    }

    $extraction = new self(
      labelText: self::nullableString($data['label_text'], 'label_text'),
      locationText: self::nullableString($data['location_text'], 'location_text'),
      concernedPersonMentions: $mentions,
      concernedPersonAlternative: $data['concerned_person_alternative'],
      responsibilityCandidate: $responsibility,
      dateExpression: self::nullableString($data['date_expression'], 'date_expression'),
      dateDay: $data['date_day'],
      dateMonth: $data['date_month'],
      dateYear: $data['date_year'],
      relativeDayOffset: $offset,
      startTimeExpression: self::nullableString($data['start_time_expression'], 'start_time_expression'),
      endTimeExpression: self::nullableString($data['end_time_expression'], 'end_time_expression'),
      recurrenceExpression: self::nullableString($data['recurrence_expression'], 'recurrence_expression'),
      explicitAllDaySignal: $data['explicit_all_day_signal'],
      explicitTimedSignal: $data['explicit_timed_signal'],
    );

    if ($extraction->containsInternalIdentity()) {
      throw new InvalidArgumentException('Activity capture extraction must not contain internal identity.');
    }

    return $extraction;
  }

  /**
   * Returns a serializable representation for tests and future benchmark V2.
   */
  public function toArray(): array {
    return [
      'label_text' => $this->labelText,
      'location_text' => $this->locationText,
      'concerned_person_mentions' => $this->concernedPersonMentions,
      'concerned_person_alternative' => $this->concernedPersonAlternative,
      'responsibility_candidate' => $this->responsibilityCandidate,
      'date_expression' => $this->dateExpression,
      'date_day' => $this->dateDay,
      'date_month' => $this->dateMonth,
      'date_year' => $this->dateYear,
      'relative_day_offset' => $this->relativeDayOffset,
      'start_time_expression' => $this->startTimeExpression,
      'end_time_expression' => $this->endTimeExpression,
      'recurrence_expression' => $this->recurrenceExpression,
      'explicit_all_day_signal' => $this->explicitAllDaySignal,
      'explicit_timed_signal' => $this->explicitTimedSignal,
    ];
  }

  /**
   * Detects prohibited internal identity accidentally emitted as text.
   */
  public function containsInternalIdentity(): bool {
    $serialized = json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($serialized)) {
      return TRUE;
    }

    return preg_match(
      '/\\b(?:person_id|household_id|person_uuid|household_uuid|uuid)\\b|[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i',
      $serialized,
    ) === 1;
  }

  private static function requiredString(mixed $value, string $field): string {
    $value = self::nullableString($value, $field);
    if ($value === NULL) {
      throw new InvalidArgumentException("Activity capture {$field} must be a non-empty string.");
    }
    return $value;
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

}
