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
use Drupal\personal_secretary\Storage\CalendarSyncStateStorageSchema;

/**
 * Sanitized cursor and health state for one provider calendar.
 */
#[ContentEntityType(
  id: self::ENTITY_TYPE_ID,
  label: new TranslatableMarkup('Calendar sync state'),
  label_singular: new TranslatableMarkup('calendar sync state'),
  label_plural: new TranslatableMarkup('calendar sync states'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
  handlers: [
    'access' => DomainEntityAccessControlHandler::class,
    'storage_schema' => CalendarSyncStateStorageSchema::class,
  ],
  admin_permission: 'administer personal secretary domain',
  base_table: 'personal_sec_calendar_sync_state',
)]
final class CalendarSyncState extends ContentEntityBase {

  public const ENTITY_TYPE_ID = 'personal_sec_calendar_sync_state';

  public const NOT_SYNCED = 'NOT_SYNCED';
  public const SYNCING = 'SYNCING';
  public const CURRENT = 'CURRENT';
  public const STALE = 'STALE';
  public const ERROR = 'ERROR';
  public const TOKEN_INVALID = 'TOKEN_INVALID';
  public const REBASE_REQUIRED = 'REBASE_REQUIRED';

  /**
   * Returns supported synchronization health states.
   */
  public static function statuses(): array {
    return [
      self::NOT_SYNCED,
      self::SYNCING,
      self::CURRENT,
      self::STALE,
      self::ERROR,
      self::TOKEN_INVALID,
      self::REBASE_REQUIRED,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['owner_user'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Owner User'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'user');

    $fields['account_connection'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Calendar account connection'))
      ->setRequired(TRUE)
      ->setSetting('target_type', CalendarAccountConnection::ENTITY_TYPE_ID);

    foreach ([
      'provider' => 32,
      'calendar_id' => 255,
      'identity_hash' => 64,
      'sync_token' => 2048,
      'status' => 32,
      'last_mode' => 16,
      'last_error_class' => 64,
      'last_rebase_date' => 10,
    ] as $name => $length) {
      $fields[$name] = BaseFieldDefinition::create('string')
        ->setLabel(new TranslatableMarkup('@name', ['@name' => $name]))
        ->setSetting('max_length', $length);
    }

    foreach ([
      'cursor_generation',
      'provider_item_count',
      'shadow_create_count',
      'shadow_update_count',
      'shadow_delete_count',
      'linked_deduplicated_count',
    ] as $name) {
      $fields[$name] = BaseFieldDefinition::create('integer')
        ->setLabel(new TranslatableMarkup('@name', ['@name' => $name]))
        ->setDefaultValue(0);
    }

    foreach (['last_attempt_at', 'last_success_at'] as $name) {
      $fields[$name] = BaseFieldDefinition::create('timestamp')
        ->setLabel(new TranslatableMarkup('@name', ['@name' => $name]));
    }

    $fields['created_at'] = BaseFieldDefinition::create('created')->setRequired(TRUE);
    $fields['changed_at'] = BaseFieldDefinition::create('changed')->setRequired(TRUE);

    return $fields;
  }

  /**
   * Builds the deterministic connection/calendar synchronization identity.
   */
  public static function identityHash(int $connectionId, string $provider, string $calendarId): string {
    return hash('sha256', json_encode([
      $provider,
      $connectionId,
      $calendarId,
    ], JSON_THROW_ON_ERROR));
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    $owner = (int) ($this->get('owner_user')->target_id ?? 0);
    $connection = (int) ($this->get('account_connection')->target_id ?? 0);
    $provider = trim((string) $this->get('provider')->value);
    $calendar = trim((string) $this->get('calendar_id')->value);
    $status = trim((string) $this->get('status')->value);

    if ($owner <= 0 || $connection <= 0 || !preg_match('/^[a-z0-9_]{1,32}$/D', $provider) || $calendar === '' || strlen($calendar) > 255
      || !in_array($status, self::statuses(), TRUE)) {
      throw new EntityStorageException('Calendar sync state is invalid.');
    }

    $identity = self::identityHash($connection, $provider, $calendar);
    $this->set('identity_hash', $identity);

    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('identity_hash', $identity)
      ->range(0, 1);
    if (!$this->isNew()) {
      $query->condition('id', (int) $this->id(), '<>');
    }
    if ($query->execute() !== []) {
      throw new EntityStorageException('Calendar sync state identity must be unique.');
    }
  }

}
