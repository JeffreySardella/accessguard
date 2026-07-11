<?php

namespace Drupal\accessguard\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the AccessGuard Waiver entity.
 *
 * A decision to accept or dismiss a recurring violation on a node, matched
 * across scans by rule + selector.
 */
#[ContentEntityType(
  id: 'accessguard_waiver',
  label: new TranslatableMarkup('AccessGuard Waiver'),
  base_table: 'accessguard_waiver',
  admin_permission: 'triage accessguard violations',
  handlers: [
    'views_data' => 'Drupal\views\EntityViewsData',
    'storage_schema' => 'Drupal\accessguard\Entity\Storage\TargetIndexStorageSchema',
  ],
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
)]
class AccessguardWaiver extends ContentEntityBase {

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
    $fields['rule_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Rule ID'))
      ->setRequired(TRUE);
    $fields['selector'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Selector'))
      ->setSetting('max_length', 2048);
    $fields['status'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setDescription(new TranslatableMarkup('accepted_risk or false_positive'))
      ->setDefaultValue('accepted_risk');
    $fields['reason'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Reason'));
    $fields['reviewer'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Reviewer'))
      ->setSetting('target_type', 'user');
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    return $fields;
  }

}
