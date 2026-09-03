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
 * An explicit responsibility decision for one audited effective occurrence.
 */
#[ContentEntityType(
  id: 'personal_sec_resp_override',
  label: new TranslatableMarkup('Responsibility override'),
  label_singular: new TranslatableMarkup('responsibility override'),
  label_plural: new TranslatableMarkup('responsibility overrides'),
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'uuid' => 'uuid',
  ],
  handlers: [
    'access' => DomainEntityAccessControlHandler::class,
  ],
  admin_permission: 'administer personal secretary domain',
  base_table: 'personal_secretary_responsibility_override',
  revision_table: 'personal_secretary_responsibility_override_revision',
)]
final class ResponsibilityOverride extends ContentEntityBase {

  public const ACTION_ASSIGN_PERSON = 'assign_person';
  public const ACTION_CLEAR_RESPONSIBILITY = 'clear_responsibility';

  public const STATUS_ACTIVE = 'active';
  public const STATUS_WITHDRAWN = 'withdrawn';

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

    $fields['effective_utc_start'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Effective UTC start audit snapshot'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('datetime_type', 'datetime');

    $fields['effective_utc_end'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Effective UTC end audit snapshot'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('datetime_type', 'datetime');

    $fields['effective_source_local_start'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Effective source-local start audit snapshot'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 64);

    $fields['effective_source_local_end'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Effective source-local end audit snapshot'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 64);

    $fields['activity_exception_uuid'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('ActivityException UUID audit snapshot'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 128);

    $fields['activity_exception_revision_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('ActivityException revision ID audit snapshot'))
      ->setRevisionable(TRUE);

    $fields['action'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Action'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 32);

    $fields['responsible_person'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Responsible person'))
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'personal_secretary_person');

    $fields['status'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 16);

    $fields['lifecycle_persisted_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Lifecycle revision persisted at'))
      ->setDescription(new TranslatableMarkup('System time when this ResponsibilityOverride lifecycle revision was persisted.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE);

    return $fields;
  }

}
