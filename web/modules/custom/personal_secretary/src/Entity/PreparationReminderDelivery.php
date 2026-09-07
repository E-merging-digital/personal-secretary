<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\personal_secretary\Access\DomainEntityAccessControlHandler;

#[ContentEntityType(
  id: self::ENTITY_TYPE_ID,
  label: new TranslatableMarkup('Preparation reminder delivery'),
  label_singular: new TranslatableMarkup('preparation reminder delivery'),
  label_plural: new TranslatableMarkup('preparation reminder deliveries'),
  entity_keys: ['id' => 'id', 'uuid' => 'uuid'],
  handlers: ['access' => DomainEntityAccessControlHandler::class],
  admin_permission: 'administer personal secretary domain',
  base_table: 'personal_sec_prep_rem_delivery',
)]
final class PreparationReminderDelivery extends ContentEntityBase {

  public const ENTITY_TYPE_ID = 'personal_sec_prep_rem_delivery';
  public const STATE_ATTEMPTING = 'ATTEMPTING';
  public const STATE_SUBMITTED = 'SUBMITTED';
  public const STATE_UNKNOWN = 'UNKNOWN';
  public const STATE_KNOWN_NOT_SUBMITTED = 'KNOWN_NOT_SUBMITTED';
  public const CHANNEL_EMAIL = 'EMAIL';

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['recipient_user'] = BaseFieldDefinition::create('entity_reference')->setRequired(TRUE)->setSetting('target_type', 'user');
    $fields['series'] = BaseFieldDefinition::create('entity_reference')->setRequired(TRUE)->setSetting('target_type', 'personal_sec_activity_series');
    $fields['target_revision_id'] = BaseFieldDefinition::create('integer')->setRequired(TRUE);
    $fields['original_occurrence_key'] = BaseFieldDefinition::create('string')->setRequired(TRUE)->setSetting('max_length', 64);
    $fields['preparation_requirement'] = BaseFieldDefinition::create('entity_reference')->setRequired(TRUE)->setSetting('target_type', 'personal_sec_prep_req');
    $fields['intended_due_at'] = BaseFieldDefinition::create('datetime')->setRequired(TRUE)->setSetting('datetime_type', 'datetime');
    $fields['channel'] = BaseFieldDefinition::create('string')->setRequired(TRUE)->setDefaultValue(self::CHANNEL_EMAIL)->setSetting('max_length', 16);
    $fields['state'] = BaseFieldDefinition::create('string')->setRequired(TRUE)->setSetting('max_length', 32);
    $fields['attempt_count'] = BaseFieldDefinition::create('integer')->setRequired(TRUE)->setDefaultValue(0);
    $fields['last_attempt_at'] = BaseFieldDefinition::create('timestamp')->setRequired(TRUE);
    $fields['submitted_at'] = BaseFieldDefinition::create('timestamp');
    $fields['sanitized_error_category'] = BaseFieldDefinition::create('string')->setSetting('max_length', 64);
    return $fields;
  }
}
