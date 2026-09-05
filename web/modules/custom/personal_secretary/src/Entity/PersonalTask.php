<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\personal_secretary\Access\DomainEntityAccessControlHandler;

/**
 * One standalone Household-scoped actionable task.
 */
#[ContentEntityType(
  id: 'personal_sec_task',
  label: new TranslatableMarkup('Personal task'),
  label_singular: new TranslatableMarkup('personal task'),
  label_plural: new TranslatableMarkup('personal tasks'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'title',
  ],
  handlers: [
    'access' => DomainEntityAccessControlHandler::class,
  ],
  admin_permission: 'administer personal secretary domain',
  base_table: 'personal_secretary_task',
)]
final class PersonalTask extends ContentEntityBase {

  public const ENTITY_TYPE_ID = 'personal_sec_task';

  public const DUE_NONE = 'NONE';
  public const DUE_DATE = 'DATE';
  public const DUE_DATE_TIME = 'DATE_TIME';

  public const STATUS_OPEN = 'OPEN';
  public const STATUS_COMPLETED = 'COMPLETED';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Task'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);

    $fields['household'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Household'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'personal_secretary_household');

    $fields['assigned_person'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Assigned person'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'personal_secretary_person');

    $fields['due_mode'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Due mode'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::DUE_NONE)
      ->setSetting('max_length', 16);

    $fields['due_date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Due date'))
      ->setDescription(new TranslatableMarkup('Optional civil due date without an implied time.'))
      ->setSetting('datetime_type', 'date');

    $fields['due_at'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Due at'))
      ->setDescription(new TranslatableMarkup('Optional canonical UTC due instant.'))
      ->setSetting('datetime_type', 'datetime');

    $fields['status'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::STATUS_OPEN)
      ->setSetting('max_length', 16);

    $fields['completed_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Completed at'));

    $fields['completed_by_user'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Completed by User'))
      ->setSetting('target_type', 'user');

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    $title = trim((string) $this->get('title')->value);
    if ($title === '') {
      throw new EntityStorageException('PersonalTask title is required.');
    }
    $this->set('title', $title);

    $dueMode = (string) $this->get('due_mode')->value;
    $dueDate = (string) ($this->get('due_date')->value ?? '');
    $dueAt = (string) ($this->get('due_at')->value ?? '');
    if (!in_array($dueMode, [self::DUE_NONE, self::DUE_DATE, self::DUE_DATE_TIME], TRUE)) {
      throw new EntityStorageException('PersonalTask due mode is invalid.');
    }
    if ($dueMode === self::DUE_NONE && ($dueDate !== '' || $dueAt !== '')) {
      throw new EntityStorageException('Undated PersonalTask cannot persist a due date or due instant.');
    }
    if ($dueMode === self::DUE_DATE && ($dueDate === '' || $dueAt !== '')) {
      throw new EntityStorageException('Date-only PersonalTask requires exactly one civil due date.');
    }
    if ($dueMode === self::DUE_DATE_TIME && ($dueDate !== '' || $dueAt === '')) {
      throw new EntityStorageException('Date-time PersonalTask requires exactly one due instant.');
    }

    $status = (string) $this->get('status')->value;
    $completedAt = $this->get('completed_at')->value;
    $completedBy = $this->get('completed_by_user')->target_id;
    if (!in_array($status, [self::STATUS_OPEN, self::STATUS_COMPLETED], TRUE)) {
      throw new EntityStorageException('PersonalTask status is invalid.');
    }
    if ($status === self::STATUS_OPEN && ($completedAt !== NULL || $completedBy !== NULL)) {
      throw new EntityStorageException('Open PersonalTask cannot persist completion metadata.');
    }
    if ($status === self::STATUS_COMPLETED && ($completedAt === NULL || $completedBy === NULL)) {
      throw new EntityStorageException('Completed PersonalTask requires completion actor and time.');
    }

    $household = $this->get('household')->entity;
    $person = $this->get('assigned_person')->entity;
    if (!$household instanceof Household || !$person instanceof Person || $household->id() === NULL || $person->id() === NULL) {
      throw new EntityStorageException('PersonalTask requires persisted Household and Person references.');
    }

    $personId = (int) $person->id();
    $memberIds = [];
    foreach ($household->get('members') as $member) {
      $memberId = (int) ($member->target_id ?? 0);
      if ($memberId > 0) {
        $memberIds[$memberId] = TRUE;
      }
    }
    if (!isset($memberIds[$personId])) {
      throw new EntityStorageException('PersonalTask assigned Person must remain a member of its Household.');
    }
  }

}
