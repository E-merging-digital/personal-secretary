<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\personal_secretary\Access\DomainEntityAccessControlHandler;
use RuntimeException;

/**
 * A revisionable recurring activity series.
 */
#[ContentEntityType(
  id: 'personal_sec_activity_series',
  label: new TranslatableMarkup('Activity series'),
  label_singular: new TranslatableMarkup('activity series'),
  label_plural: new TranslatableMarkup('activity series'),
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'uuid' => 'uuid',
    'label' => 'name',
  ],
  handlers: [
    'access' => DomainEntityAccessControlHandler::class,
  ],
  admin_permission: 'administer personal secretary domain',
  base_table: 'personal_secretary_activity_series',
  revision_table: 'personal_secretary_activity_series_revision',
)]
final class ActivitySeries extends ContentEntityBase {

  public const TIME_MODE_TIMED = 'timed';

  public const TIME_MODE_ALL_DAY = 'all_day';

  private const UTC_STORAGE_FORMAT = 'Y-m-d\\TH:i:s';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255);

    $fields['household'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Household'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'personal_secretary_household');

    $fields['concerned_persons'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Concerned Persons'))
      ->setRequired(FALSE)
      ->setRevisionable(TRUE)
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setSetting('target_type', 'personal_secretary_person');

    $fields['recurrence'] = BaseFieldDefinition::create('date_recur')
      ->setLabel(new TranslatableMarkup('Recurrence'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE);

    $fields['effective_from'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Effective from'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('datetime_type', 'datetime');

    $fields['location'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Location'))
      ->setRequired(FALSE)
      ->setRevisionable(FALSE)
      ->setSetting('max_length', 255);

    $fields['time_mode'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Time mode'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setDefaultValue(self::TIME_MODE_TIMED)
      ->setSetting('max_length', 16);

    return $fields;
  }

  public static function supportsTimeMode(string $timeMode): bool {
    return in_array($timeMode, [self::TIME_MODE_TIMED, self::TIME_MODE_ALL_DAY], TRUE);
  }

  public function timeMode(): string {
    $timeMode = trim((string) ($this->get('time_mode')->value ?? ''));
    if ($timeMode === '') {
      // Legacy series created before update 11006 deterministically resolve as
      // timed. Never infer temporal semantics from stored timestamps.
      return self::TIME_MODE_TIMED;
    }
    if (!self::supportsTimeMode($timeMode)) {
      throw new RuntimeException('ActivitySeries contains an unsupported time mode.');
    }
    return $timeMode;
  }

  public function allDayCivilDaySpan(): int {
    if ($this->timeMode() !== self::TIME_MODE_ALL_DAY) {
      throw new RuntimeException('Civil-day span is defined only for ALL_DAY ActivitySeries.');
    }

    $item = $this->get('recurrence')->first();
    if ($item === NULL || $item->isEmpty()) {
      throw new RuntimeException('ALL_DAY ActivitySeries has no recurrence value.');
    }
    $raw = $item->getValue();
    $timezoneName = trim((string) ($raw['timezone'] ?? ''));
    if ($timezoneName === '') {
      throw new RuntimeException('ALL_DAY ActivitySeries has no canonical source timezone.');
    }
    $timezone = new DateTimeZone($timezoneName);
    $start = self::fromRecurrenceStorage((string) ($raw['value'] ?? ''))->setTimezone($timezone);
    $end = self::fromRecurrenceStorage((string) ($raw['end_value'] ?? ''))->setTimezone($timezone);

    if ($start->format('H:i:s') !== '00:00:00' || $end->format('H:i:s') !== '00:00:00') {
      throw new RuntimeException('ALL_DAY ActivitySeries boundaries must be source-local midnights.');
    }

    $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start->format('Y-m-d'), $timezone);
    $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $end->format('Y-m-d'), $timezone);
    if (!$startDate instanceof DateTimeImmutable || !$endDate instanceof DateTimeImmutable) {
      throw new RuntimeException('ALL_DAY ActivitySeries civil dates are invalid.');
    }

    $span = (int) $startDate->diff($endDate)->format('%r%a');
    if ($span <= 0) {
      throw new RuntimeException('ALL_DAY ActivitySeries must span at least one civil day.');
    }
    return $span;
  }

  private static function fromRecurrenceStorage(string $value): DateTimeImmutable {
    $parsed = DateTimeImmutable::createFromFormat(
      '!' . self::UTC_STORAGE_FORMAT,
      $value,
      new DateTimeZone('UTC'),
    );
    if (!$parsed instanceof DateTimeImmutable || $parsed->format(self::UTC_STORAGE_FORMAT) !== $value) {
      throw new RuntimeException('Stored ActivitySeries recurrence datetime is invalid.');
    }
    return $parsed;
  }

}
