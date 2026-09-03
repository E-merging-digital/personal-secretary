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
 * A reusable preparation requirement for one ActivitySeries.
 */
#[ContentEntityType(
  id: 'personal_sec_prep_req',
  label: new TranslatableMarkup('Preparation requirement'),
  label_singular: new TranslatableMarkup('preparation requirement'),
  label_plural: new TranslatableMarkup('preparation requirements'),
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'uuid' => 'uuid',
    'label' => 'label',
  ],
  handlers: [
    'access' => DomainEntityAccessControlHandler::class,
  ],
  admin_permission: 'administer personal secretary domain',
  base_table: 'personal_secretary_preparation_requirement',
  revision_table: 'personal_secretary_preparation_requirement_revision',
)]
final class PreparationRequirement extends ContentEntityBase {

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

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Preparation instruction'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255);

    $fields['lead_time_seconds'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Lead time in seconds'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE);

    $fields['effective_from'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Effective from'))
      ->setDescription(new TranslatableMarkup('Required canonical UTC applicability start.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('datetime_type', 'datetime');

    $fields['effective_until'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Effective until'))
      ->setDescription(new TranslatableMarkup('Optional canonical UTC applicability cutoff.'))
      ->setRevisionable(TRUE)
      ->setSetting('datetime_type', 'datetime');

    $fields['lifecycle_persisted_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Lifecycle revision persisted at'))
      ->setDescription(new TranslatableMarkup('System time when this PreparationRequirement lifecycle revision was persisted.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE);

    return $fields;
  }

}
