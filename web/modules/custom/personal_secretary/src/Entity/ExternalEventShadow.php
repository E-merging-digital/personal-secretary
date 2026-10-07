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
use Drupal\personal_secretary\Storage\ExternalEventShadowStorageSchema;

/**
 * Provider-agnostic, read-only derived planning state.
 */
#[ContentEntityType(
  id: self::ENTITY_TYPE_ID,
  label: new TranslatableMarkup('External event shadow'),
  label_singular: new TranslatableMarkup('external event shadow'),
  label_plural: new TranslatableMarkup('external event shadows'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'title',
  ],
  handlers: [
    'access' => DomainEntityAccessControlHandler::class,
    'storage_schema' => ExternalEventShadowStorageSchema::class,
  ],
  admin_permission: 'administer personal secretary domain',
  base_table: 'personal_sec_ext_event_shadow',
)]
final class ExternalEventShadow extends ContentEntityBase {

  public const ENTITY_TYPE_ID = 'personal_sec_ext_event_shadow';

  public const PROVIDER_GOOGLE = 'google';
  public const TIME_MODE_TIMED = 'TIMED';
  public const TIME_MODE_ALL_DAY = 'ALL_DAY';

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
      'provider_event_id' => 1024,
      'identity_hash' => 64,
      'etag' => 255,
      'provider_status' => 32,
      'title' => 255,
      'time_mode' => 16,
      'source_timezone' => 64,
      'location' => 255,
      'transparency' => 16,
      'recurring_event_id' => 1024,
      'original_start_time' => 255,
      'original_start_timezone' => 64,
    ] as $name => $length) {
      $fields[$name] = BaseFieldDefinition::create('string')
        ->setLabel(new TranslatableMarkup('@name', ['@name' => $name]))
        ->setSetting('max_length', $length);
    }

    foreach (['timed_start', 'timed_end', 'provider_updated'] as $name) {
      $fields[$name] = BaseFieldDefinition::create('datetime')
        ->setLabel(new TranslatableMarkup('@name', ['@name' => $name]))
        ->setSetting('datetime_type', 'datetime');
    }

    foreach (['all_day_start', 'all_day_end'] as $name) {
      $fields[$name] = BaseFieldDefinition::create('datetime')
        ->setLabel(new TranslatableMarkup('@name', ['@name' => $name]))
        ->setSetting('datetime_type', 'date');
    }

    $fields['active'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Active planning item'))
      ->setRequired(TRUE)
      ->setDefaultValue(TRUE);

    $fields['last_seen_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Last seen at'))
      ->setRequired(TRUE);

    $fields['created_at'] = BaseFieldDefinition::create('created')->setRequired(TRUE);
    $fields['changed_at'] = BaseFieldDefinition::create('changed')->setRequired(TRUE);

    return $fields;
  }

  /**
   * Builds the deterministic provider event identity.
   */
  public static function identityHash(int $connectionId, string $provider, string $calendarId, string $eventId): string {
    return hash('sha256', json_encode([
      $provider,
      $connectionId,
      $calendarId,
      $eventId,
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
    $calendarId = trim((string) $this->get('calendar_id')->value);
    $eventId = trim((string) $this->get('provider_event_id')->value);
    $status = trim((string) $this->get('provider_status')->value);
    $mode = trim((string) $this->get('time_mode')->value);

    if ($owner <= 0 || $connection <= 0 || !preg_match('/^[a-z0-9_]{1,32}$/D', $provider)
      || $calendarId === '' || strlen($calendarId) > 255 || $eventId === '' || strlen($eventId) > 1024
      || !in_array($status, ['confirmed', 'tentative', 'cancelled'], TRUE)) {
      throw new EntityStorageException('External event shadow identity is invalid.');
    }

    if (!in_array($mode, [self::TIME_MODE_TIMED, self::TIME_MODE_ALL_DAY], TRUE)) {
      throw new EntityStorageException('External event shadow time mode is invalid.');
    }

    $active = (bool) $this->get('active')->value;
    if ($status === 'cancelled' && $active) {
      throw new EntityStorageException('Cancelled external events cannot remain active.');
    }

    if ($mode === self::TIME_MODE_TIMED && $active) {
      if ($this->get('timed_start')->isEmpty() || $this->get('timed_end')->isEmpty()) {
        throw new EntityStorageException('Active TIMED external event requires start and end.');
      }
    }
    elseif ($mode === self::TIME_MODE_ALL_DAY && $active) {
      if ($this->get('all_day_start')->isEmpty() || $this->get('all_day_end')->isEmpty()) {
        throw new EntityStorageException('Active ALL_DAY external event requires civil dates.');
      }
    }

    if ((int) ($this->get('last_seen_at')->value ?? 0) <= 0) {
      throw new EntityStorageException('External event shadow requires last_seen_at.');
    }

    $identity = self::identityHash($connection, $provider, $calendarId, $eventId);
    $this->set('identity_hash', $identity);

    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('identity_hash', $identity)
      ->range(0, 1);
    if (!$this->isNew()) {
      $query->condition('id', (int) $this->id(), '<>');
    }
    if ($query->execute() !== []) {
      throw new EntityStorageException('External event shadow identity must be unique.');
    }
  }

}
