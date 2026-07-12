<?php

namespace Drupal\accessguard\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the AccessGuard Scan entity — one accessibility scan run of a target.
 */
#[ContentEntityType(
  id: 'accessguard_scan',
  label: new TranslatableMarkup('AccessGuard Scan'),
  base_table: 'accessguard_scan',
  handlers: [
    'views_data' => 'Drupal\views\EntityViewsData',
    'storage_schema' => 'Drupal\accessguard\Entity\Storage\TargetIndexStorageSchema',
  ],
  admin_permission: 'administer accessguard',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
)]
class AccessguardScan extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['target_entity_type'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Target entity type'))
      ->setRequired(TRUE);

    $fields['target_entity_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Target entity ID'))
      ->setRequired(TRUE);

    $fields['url'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Scanned URL'))
      ->setSetting('max_length', 2048);

    $fields['triggered_by'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Triggered by'));

    $fields['content_author'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Content author'))
      ->setSetting('target_type', 'user');

    $fields['status'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setDefaultValue('queued');

    $fields['engine_version'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Engine version'))
      ->setDescription(new TranslatableMarkup('The axe-core version that produced this scan.'));

    foreach (['critical', 'serious', 'moderate', 'minor'] as $sev) {
      $fields['count_' . $sev] = BaseFieldDefinition::create('integer')
        ->setLabel(new TranslatableMarkup('Count @sev', ['@sev' => $sev]))
        ->setDefaultValue(0);
    }

    $fields['count_needs_review'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Count needs-review'))
      ->setDefaultValue(0);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    return $fields;
  }

}
