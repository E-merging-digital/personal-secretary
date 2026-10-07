<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Storage;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Enforces one cursor/health row per connection and calendar.
 */
final class CalendarSyncStateStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $schema[$entity_type->getBaseTable()]['unique keys']['calendar_sync_identity'] = ['identity_hash'];
    return $schema;
  }

}
