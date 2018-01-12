<?php

namespace Drupal\fieldception\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;

/**
 * Plugin implementations for 'fieldception' formatter.
 *
 * @FieldFormatter(
 *   id = "fieldception_unformatted_list",
 *   label = @Translation("Unformatted list"),
 *   field_types = {"fieldception"}
 * )
 */
class UnformattedList extends FieldceptionBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $fieldception_helper = \Drupal::service('fieldception.helper');
    $settings = $this->getSettings();
    $field_settings = $this->getFieldSettings();
    $field_definition = $this->fieldDefinition->getFieldStorageDefinition();
    $entity = $items->getEntity();

    $element = [];
    foreach ($items as $delta => $item) {
      $element[$delta] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['subfield-unformatted-list']],
      ];
      foreach ($field_settings['storage'] as $subfield => $config) {
        $subfield_settings = isset($settings['fields'][$subfield]['settings']) ? $settings['fields'][$subfield]['settings'] : [];
        $subfield_definition = $fieldception_helper->getSubfieldDefinition($field_definition, $config, $subfield);
        $subfield_formatter_type = $this->getSubfieldFormatterType($subfield_definition);
        if ($subfield_formatter_type === '_hidden') {
          continue;
        }
        $subfield_formatter = $fieldception_helper->getSubfieldFormatter($subfield_definition, $subfield_formatter_type, $subfield_settings, $this->viewMode, $this->label);
        $subfield_items = $fieldception_helper->getSubfieldItemList($subfield_definition, $entity, $delta);
        if (!$subfield_items->isEmpty()) {
          $element[$delta][$subfield] = [
            '#theme' => 'fieldception_subfield',
            '#definition' => $subfield_definition,
            '#label' => $config['label'],
            '#label_display' => !empty($settings['fields'][$subfield]['label_display']) ? $settings['fields'][$subfield]['label_display'] : 'above',
            '#content' => $subfield_formatter->viewElements($subfield_items, $langcode),
          ];
          if (!empty($subfield_settings['link_to_field']) && isset($field_settings['storage'][$subfield_settings['link_to_field']])) {
            $subfield_link = $subfield_settings['link_to_field'];
            $subfield_link_config = $field_settings['storage'][$subfield_link];
            $subfield_link_definition = $fieldception_helper->getSubfieldDefinition($field_definition, $subfield_link_config, $subfield_link);
            $subfield_link_items = $fieldception_helper->getSubfieldItemList($subfield_link_definition, $entity, $delta);
            $url = $this->buildUrl($subfield_link_items->first());
            if ($url) {
              $element[$delta][$subfield]['#tag'] = 'a';
              $element[$delta][$subfield]['#attributes']['href'] = $url->toString();
            }
          }
        }
      }
    }

    return $element;
  }

}
