<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\personal_secretary\Access\DomainEntityAccessControlHandler;

/**
 * Non-secret metadata for one User-owned Google Calendar connection.
 */
#[ContentEntityType(
  id: self::ENTITY_TYPE_ID,
  label: new TranslatableMarkup('Calendar account connection'),
  label_singular: new TranslatableMarkup('calendar account connection'),
  label_plural: new TranslatableMarkup('calendar account connections'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
  handlers: [
    'access' => DomainEntityAccessControlHandler::class,
  ],
  admin_permission: 'administer personal secretary domain',
  base_table: 'personal_sec_calendar_connection',
)]
final class CalendarAccountConnection extends ContentEntityBase {

  public const ENTITY_TYPE_ID = 'personal_sec_calendar_connection';

  public const PROVIDER_GOOGLE = 'google';

  public const STATUS_CONNECTED = 'CONNECTED';

  public const STATUS_INVALID = 'INVALID';

  public const SCOPE_OPENID = 'openid';

  public const SCOPE_CALENDARS_READONLY =
    'https://www.googleapis.com/auth/calendar.calendars.readonly';

  /**
   * Returns the exact connection scopes in deterministic order.
   *
   * @return string[]
   *   Exact Google connection scopes.
   */
  public static function connectionScopes(): array {
    $scopes = [
      self::SCOPE_OPENID,
      self::SCOPE_CALENDARS_READONLY,
    ];
    sort($scopes, SORT_STRING);
    return $scopes;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(
    EntityTypeInterface $entity_type,
  ): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['owner_user'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Owner User'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'user');

    $fields['provider_key'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Provider'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::PROVIDER_GOOGLE)
      ->setSetting('max_length', 32);

    $fields['provider_subject_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Provider subject'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);

    $fields['scopes'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Granted scopes'))
      ->setRequired(TRUE)
      ->setCardinality(
        FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
      )
      ->setSetting('max_length', 255);

    $fields['status'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Connection status'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 16);

    $fields['connected_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Connected at'))
      ->setRequired(TRUE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    $ownerId = (int) ($this->get('owner_user')->target_id ?? 0);
    if ($ownerId <= 0) {
      throw new EntityStorageException(
        'CalendarAccountConnection requires an owner User.',
      );
    }

    $provider = trim((string) $this->get('provider_key')->value);
    if ($provider !== self::PROVIDER_GOOGLE) {
      throw new EntityStorageException(
        'CalendarAccountConnection supports only Google.',
      );
    }

    $subject = trim((string) $this->get('provider_subject_id')->value);
    if ($subject === '') {
      throw new EntityStorageException(
        'CalendarAccountConnection requires a Google OIDC subject.',
      );
    }
    $this->set('provider_subject_id', $subject);

    $status = (string) $this->get('status')->value;
    if (!in_array(
      $status,
      [self::STATUS_CONNECTED, self::STATUS_INVALID],
      TRUE,
    )) {
      throw new EntityStorageException(
        'CalendarAccountConnection status is invalid.',
      );
    }

    $scopes = [];
    foreach ($this->get('scopes') as $item) {
      $scope = trim((string) $item->value);
      if ($scope !== '') {
        $scopes[$scope] = TRUE;
      }
    }
    $scopes = array_keys($scopes);
    sort($scopes, SORT_STRING);

    if ($scopes !== self::connectionScopes()) {
      throw new EntityStorageException(
        'CalendarAccountConnection requires exact connection scopes.',
      );
    }

    $this->set(
      'scopes',
      array_map(
        static fn(string $scope): array => ['value' => $scope],
        $scopes,
      ),
    );

    if ((int) ($this->get('connected_at')->value ?? 0) <= 0) {
      throw new EntityStorageException(
        'CalendarAccountConnection requires connected_at.',
      );
    }

    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('owner_user', $ownerId)
      ->condition('provider_key', self::PROVIDER_GOOGLE)
      ->range(0, 1);

    if (!$this->isNew() && $this->id() !== NULL) {
      $query->condition('id', (int) $this->id(), '<>');
    }

    if ($query->execute() !== []) {
      throw new EntityStorageException(
        'Only one Google Calendar connection is allowed per User.',
      );
    }
  }

}
