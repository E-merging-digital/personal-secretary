<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\personal_secretary\Access\DomainEntityAccessControlHandler;
use Drupal\user\UserInterface;

/**
 * Sparse durable done-state for one exact derived preparation.
 */
#[ContentEntityType(
  id: 'personal_sec_prep_completion',
  label: new TranslatableMarkup('Preparation completion'),
  label_singular: new TranslatableMarkup('preparation completion'),
  label_plural: new TranslatableMarkup('preparation completions'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
  handlers: [
    'access' => DomainEntityAccessControlHandler::class,
  ],
  admin_permission: 'administer personal secretary domain',
  base_table: 'personal_secretary_preparation_completion',
)]
final class PreparationCompletion extends ContentEntityBase {

  public const ENTITY_TYPE_ID = 'personal_sec_prep_completion';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['series'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Activity series'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'personal_sec_activity_series');

    $fields['target_revision_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Target series revision ID'))
      ->setRequired(TRUE);

    $fields['original_occurrence_key'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Original occurrence key'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64);

    $fields['preparation_requirement'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Preparation requirement'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'personal_sec_prep_req');

    $fields['responsible_person'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Responsible person'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'personal_secretary_person');

    $fields['prepared_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Prepared at'))
      ->setRequired(TRUE);

    $fields['prepared_by_user'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Prepared by User'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'user');

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    $series = $this->get('series')->entity;
    $requirement = $this->get('preparation_requirement')->entity;
    $person = $this->get('responsible_person')->entity;
    $user = $this->get('prepared_by_user')->entity;
    if (
      !$series instanceof ActivitySeries
      || !$requirement instanceof PreparationRequirement
      || !$person instanceof Person
      || !$user instanceof UserInterface
      || $series->id() === NULL
      || $requirement->id() === NULL
      || $person->id() === NULL
      || $user->id() === NULL
    ) {
      throw new EntityStorageException('PreparationCompletion requires persisted domain references.');
    }

    if ((int) $requirement->get('series')->target_id !== (int) $series->id()) {
      throw new EntityStorageException('PreparationCompletion requirement must belong to the referenced ActivitySeries.');
    }

    $targetRevisionId = (int) $this->get('target_revision_id')->value;
    if ($targetRevisionId <= 0) {
      throw new EntityStorageException('PreparationCompletion requires a positive target series revision ID.');
    }

    $originalOccurrenceKey = trim((string) $this->get('original_occurrence_key')->value);
    if ($originalOccurrenceKey === '' || strlen($originalOccurrenceKey) > 64) {
      throw new EntityStorageException('PreparationCompletion requires a bounded original occurrence key.');
    }
    $this->set('original_occurrence_key', $originalOccurrenceKey);

    if ((int) $this->get('prepared_at')->value <= 0) {
      throw new EntityStorageException('PreparationCompletion requires a positive prepared timestamp.');
    }
  }

}
