<?php

namespace Drupal\accessguard\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the AccessGuard Violation entity — one axe finding within a scan.
 */
#[ContentEntityType(
  id: 'accessguard_violation',
  label: new TranslatableMarkup('AccessGuard Violation'),
  base_table: 'accessguard_violation',
  handlers: [
    'views_data' => 'Drupal\views\EntityViewsData',
  ],
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
)]
class AccessguardViolation extends ContentEntityBase {

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['scan_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Scan'))
      ->setSetting('target_type', 'accessguard_scan')
      ->setRequired(TRUE);

    $fields['rule_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Rule ID'));

    $fields['impact'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Impact'));

    $fields['wcag_criterion'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('WCAG criterion'));

    $fields['selector'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Selector'))
      ->setSetting('max_length', 2048);

    $fields['html_snippet'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('HTML snippet'));

    $fields['help_url'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Help URL'))
      ->setSetting('max_length', 2048);

    return $fields;
  }

}
