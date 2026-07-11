<?php

namespace Drupal\accessguard\Entity\Storage;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * Adds indexes on the target-node column of scan and waiver tables.
 *
 * Every gate validation, dashboard request, regression diff, waiver lookup,
 * and node-delete cleanup filters on target_entity_id; without an index those
 * are full-table scans on tables that grow with every cron re-scan. The scan
 * table additionally gets a composite (target_entity_id, created, id) index so
 * the "latest scan per node" lookups (created DESC, id DESC) are index-ranged
 * rather than filesorts over millions of rows.
 */
class TargetIndexStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getSharedTableFieldSchema(FieldStorageDefinitionInterface $storage_definition, $table_name, array $column_mapping) {
    $schema = parent::getSharedTableFieldSchema($storage_definition, $table_name, $column_mapping);
    if ($storage_definition->getName() === 'target_entity_id') {
      $this->addSharedTableFieldIndex($storage_definition, $schema, TRUE);
    }
    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    // Only the scan table has a created/id "latest" ordering to accelerate.
    $base_table = $entity_type->getBaseTable();
    if ($entity_type->id() === 'accessguard_scan' && isset($schema[$base_table])) {
      $schema[$base_table]['indexes']['accessguard_scan__latest'] = [
        'target_entity_id',
        'created',
        'id',
      ];
    }
    return $schema;
  }

}
