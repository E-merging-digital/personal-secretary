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
use Drupal\personal_secretary\Storage\GoogleCalendarProjectionStorageSchema;

/**
 * Integration metadata only; never an occurrence or a source of write authority.
 */
#[ContentEntityType(
  id: self::ENTITY_TYPE_ID,
  label: new TranslatableMarkup('Google Calendar projection'),
  entity_keys: ['id' => 'id', 'uuid' => 'uuid'],
  handlers: [
    'access' => DomainEntityAccessControlHandler::class,
    'storage_schema' => GoogleCalendarProjectionStorageSchema::class,
  ],
  admin_permission: 'administer personal secretary domain',
  base_table: 'personal_sec_google_projection',
)]
final class GoogleCalendarProjection extends ContentEntityBase {

  public const ENTITY_TYPE_ID = 'personal_sec_google_projection';
  public const ACTIVE = 'ACTIVE';
  public const CONFLICT = 'CONFLICT';
  public const REMOTE_MISSING = 'REMOTE_MISSING';

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['owner_user'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Owner User'))
      ->setRequired(TRUE)->setSetting('target_type', 'user');
    foreach ([
      'provider' => 16,
      'target' => 16,
      'series_uuid' => 128,
      'original_occurrence_key' => 32,
      'identity_hash' => 64,
      'provider_subject_id' => 255,
      'event_id' => 66,
      'etag' => 255,
      'payload_fingerprint' => 64,
      'state' => 16,
    ] as $name => $length) {
      $fields[$name] = BaseFieldDefinition::create('string')
        ->setLabel(new TranslatableMarkup('@name', ['@name' => $name]))
        ->setRequired(TRUE)->setSetting('max_length', $length);
    }
    $fields['created_at'] = BaseFieldDefinition::create('created')->setRequired(TRUE);
    $fields['updated_at'] = BaseFieldDefinition::create('changed')->setRequired(TRUE);
    return $fields;
  }

  public static function identityHash(int $owner, string $seriesUuid, string $key): string {
    return hash('sha256', json_encode([$owner, 'google', $seriesUuid, $key], JSON_THROW_ON_ERROR));
  }

  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    $owner = (int) $this->get('owner_user')->target_id;
    $uuid = (string) $this->get('series_uuid')->value;
    $key = (string) $this->get('original_occurrence_key')->value;
    if ($owner <= 0 || $uuid === '' || strlen($uuid) > 128
      || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $key)
      || $this->get('provider')->value !== 'google'
      || $this->get('target')->value !== 'primary'
      || !in_array($this->get('state')->value, [self::ACTIVE, self::CONFLICT, self::REMOTE_MISSING], TRUE)) {
      throw new EntityStorageException('Invalid Google projection identity or state.');
    }
    foreach (['payload_fingerprint'] as $field) {
      if (!preg_match('/^[0-9a-f]{64}$/D', (string) $this->get($field)->value)) {
        throw new EntityStorageException('Invalid Google projection metadata.');
      }
    }
    $etag = (string) $this->get('etag')->value;
    if ($etag === '' || $etag === '*' || strlen($etag) > 255 || preg_match('/[\r\n]/', $etag)
      || !preg_match('/^ps[0-9a-f]{64}$/D', (string) $this->get('event_id')->value)
      || trim((string) $this->get('provider_subject_id')->value) === ''
      || strlen((string) $this->get('provider_subject_id')->value) > 255) {
      throw new EntityStorageException('Invalid Google projection version.');
    }
    $identity = self::identityHash($owner, $uuid, $key);
    $this->set('identity_hash', $identity);
    $query = $storage->getQuery()->accessCheck(FALSE)->condition('identity_hash', $identity)->range(0, 1);
    if (!$this->isNew()) {
      $query->condition('id', $this->id(), '<>');
    }
    if ($query->execute() !== []) {
      throw new EntityStorageException('Only one Google projection is allowed per User and occurrence.');
    }
  }

}
