<?php

namespace Drupal\fieldception\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;

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
  public static function defaultSettings() {
    return parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $fieldception_helper = \Drupal::service('fieldception.helper');
    $settings = $this->getSettings();
    $field_settings = $this->getFieldSettings();
    $field_definition = $this->fieldDefinition->getFieldStorageDefinition();

    $element = [];

    $element['fields'] = [
      '#tree' => TRUE,
    ];

    foreach ($field_settings['storage'] as $subfield => $config) {
      $subfield_field_settings = isset($field_settings['fields'][$subfield]['settings']) ? $field_settings['fields'][$subfield]['settings'] : [];
      $subfield_settings = isset($settings['fields'][$subfield]['settings']) ? $settings['fields'][$subfield]['settings'] : [];
      $subfield_definition = $fieldception_helper->getSubfieldDefinition($field_definition, $config, $subfield, $subfield_field_settings);
      $subfield_formatter = $fieldception_helper->getSubfieldFormatter($subfield_definition, $subfield_settings, $this->viewMode, $this->label);

      $element['fields'][$subfield] = [
        '#type' => 'fieldset',
        '#title' => $config['label'],
        '#tree' => TRUE,
      ];
      $element['fields'][$subfield]['settings'] = [];
      $element['fields'][$subfield]['settings'] = $subfield_formatter->settingsForm($element['fields'][$subfield]['settings'], $form_state);
    }

    return $element + parent::settingsForm($form, $form_state);
  }

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
      foreach ($field_settings['storage'] as $subfield => $config) {
        $subfield_field_settings = isset($field_settings['fields'][$subfield]['settings']) ? $field_settings['fields'][$subfield]['settings'] : [];
        $subfield_settings = isset($settings['fields'][$subfield]['settings']) ? $settings['fields'][$subfield]['settings'] : [];
        $subfield_definition = $fieldception_helper->getSubfieldDefinition($field_definition, $config, $subfield, $subfield_field_settings);
        $subfield_formatter = $fieldception_helper->getSubfieldFormatter($subfield_definition, $subfield_settings, $this->viewMode, $this->label);
        $subfield_items = $fieldception_helper->getSubfieldItemList($subfield_definition, $entity, $delta);
        $element[$delta][$subfield] = $subfield_formatter->viewElements($subfield_items, $langcode);
      }
    }

    return $element;
  }

}