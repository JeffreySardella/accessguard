<?php

namespace Drupal\accessguard\Entity\Storage;

use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * Adds an index on the target-node column of scan and waiver tables.
 *
 * Every gate validation, dashboard request, regression diff, waiver lookup,
 * and node-delete cleanup filters on target_entity_id; without an index those
 * are full-table scans on tables that grow with every cron re-scan.
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

}
