<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Storage;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Enforces exact provider-event identity uniqueness.
 */
final class ExternalEventShadowStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $schema[$entity_type->getBaseTable()]['unique keys']['external_event_identity'] = ['identity_hash'];
    $schema[$entity_type->getBaseTable()]['indexes']['external_event_owner_active'] = ['owner_user', 'active'];
    return $schema;
  }

}
