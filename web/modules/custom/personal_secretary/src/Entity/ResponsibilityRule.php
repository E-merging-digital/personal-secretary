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
 * A recurring responsibility applicability rule for one ActivitySeries.
 */
#[ContentEntityType(
  id: 'personal_sec_resp_rule',
  label: new TranslatableMarkup('Responsibility rule'),
  label_singular: new TranslatableMarkup('responsibility rule'),
  label_plural: new TranslatableMarkup('responsibility rules'),
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'uuid' => 'uuid',
  ],
  handlers: [
    'access' => DomainEntityAccessControlHandler::class,
  ],
  admin_permission: 'administer personal secretary domain',
  base_table: 'personal_secretary_responsibility_rule',
  revision_table: 'personal_secretary_responsibility_rule_revision',
)]
final class ResponsibilityRule extends ContentEntityBase {

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

    $fields['responsible_person'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Responsible person'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'personal_secretary_person');

    $fields['recurrence'] = BaseFieldDefinition::create('date_recur')
      ->setLabel(new TranslatableMarkup('Responsibility applicability windows'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE);

    $fields['effective_until'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Effective until'))
      ->setDescription(new TranslatableMarkup('Optional canonical UTC cutoff for this rule.'))
      ->setRevisionable(TRUE)
      ->setSetting('datetime_type', 'datetime');

    $fields['lifecycle_persisted_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Lifecycle revision persisted at'))
      ->setDescription(new TranslatableMarkup('System time when this ResponsibilityRule lifecycle revision was persisted.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE);

    return $fields;
  }

}
