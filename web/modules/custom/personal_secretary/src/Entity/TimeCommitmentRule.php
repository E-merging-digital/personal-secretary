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
 * An explicit time-commitment interval for one ActivitySeries.
 */
#[ContentEntityType(
  id: 'personal_sec_time_commit',
  label: new TranslatableMarkup('Time commitment rule'),
  label_singular: new TranslatableMarkup('time commitment rule'),
  label_plural: new TranslatableMarkup('time commitment rules'),
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'uuid' => 'uuid',
  ],
  handlers: [
    'access' => DomainEntityAccessControlHandler::class,
  ],
  admin_permission: 'administer personal secretary domain',
  base_table: 'personal_secretary_time_commitment',
  revision_table: 'personal_secretary_time_commitment_revision',
)]
final class TimeCommitmentRule extends ContentEntityBase {

  public const ENTITY_TYPE_ID = 'personal_sec_time_commit';

  public const MODE_FULL_OCCURRENCE = 'full_occurrence';

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

    $fields['mode'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Time commitment mode'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 32);

    $fields['effective_from'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Effective from'))
      ->setDescription(new TranslatableMarkup('Canonical UTC start of this time-commitment interval.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('datetime_type', 'datetime');

    $fields['effective_until'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Effective until'))
      ->setDescription(new TranslatableMarkup('Optional exclusive canonical UTC end of this time-commitment interval.'))
      ->setRevisionable(TRUE)
      ->setSetting('datetime_type', 'datetime');

    $fields['lifecycle_persisted_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Lifecycle revision persisted at'))
      ->setDescription(new TranslatableMarkup('System time when this TimeCommitmentRule lifecycle revision was persisted.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE);

    return $fields;
  }

}
