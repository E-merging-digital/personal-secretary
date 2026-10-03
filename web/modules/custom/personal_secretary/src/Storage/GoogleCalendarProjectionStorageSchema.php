<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Storage;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Enforces occurrence mapping uniqueness, including concurrent inserts.
 */
final class GoogleCalendarProjectionStorageSchema extends SqlContentEntityStorageSchema {

  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $schema[$entity_type->getBaseTable()]['unique keys']['google_occurrence_owner'] = ['identity_hash'];
    return $schema;
  }

}
