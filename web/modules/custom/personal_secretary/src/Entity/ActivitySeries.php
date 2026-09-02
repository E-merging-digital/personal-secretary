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

    $fields['recurrence'] = BaseFieldDefinition::create('date_recur')
      ->setLabel(new TranslatableMarkup('Recurrence'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE);

    return $fields;
  }

}
