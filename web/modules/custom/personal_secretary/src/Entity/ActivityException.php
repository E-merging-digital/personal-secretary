<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\personal_secretary\Access\DomainEntityAccessControlHandler;

/**
 * An explicit cancel/reschedule exception targeting one audited occurrence.
 */
#[ContentEntityType(
  id: 'personal_sec_activity_exception',
  label: new TranslatableMarkup('Activity exception'),
  label_singular: new TranslatableMarkup('activity exception'),
  label_plural: new TranslatableMarkup('activity exceptions'),
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'uuid' => 'uuid',
  ],
  handlers: [
    'access' => DomainEntityAccessControlHandler::class,
  ],
  admin_permission: 'administer personal secretary domain',
  base_table: 'personal_secretary_activity_exception',
  revision_table: 'personal_secretary_activity_exception_revision',
)]
final class ActivityException extends ContentEntityBase {

  public const ACTION_CANCEL = 'cancel';
  public const ACTION_RESCHEDULE = 'reschedule';

  public const STATUS_ACTIVE = 'active';
  public const STATUS_ORPHANED = 'orphaned';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['series'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Activity series'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'personal_sec_activity_series');

    $fields['target_revision_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Target series revision ID'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE);

    $fields['original_occurrence_key'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Original occurrence key'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 64);

    $fields['original_utc_start'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Original UTC start'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('datetime_type', 'datetime');

    $fields['original_utc_end'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Original UTC end'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('datetime_type', 'datetime');

    $fields['original_source_local_start'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Original source-local start'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 64);

    $fields['original_source_local_end'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Original source-local end'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 64);

    $fields['source_timezone'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Source timezone audit snapshot'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 64);

    $fields['action'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Action'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 16);

    $fields['status'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 16);

    $fields['lifecycle_persisted_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Lifecycle revision persisted at'))
      ->setDescription(new TranslatableMarkup('System time when this ActivityException lifecycle revision was persisted.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE);

    $fields['rescheduled_utc_start'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Rescheduled UTC start'))
      ->setRevisionable(TRUE)
      ->setSetting('datetime_type', 'datetime');

    $fields['rescheduled_utc_end'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Rescheduled UTC end'))
      ->setRevisionable(TRUE)
      ->setSetting('datetime_type', 'datetime');

    return $fields;
  }

}
