<?php

namespace Drupal\fieldception\Plugin\Field\FieldWidget;

use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;

/**
 * Plugin implementation of the 'double_field' widget.
 *
 * @FieldWidget(
 *   id = "fieldception",
 *   label = @Translation("Fieldception"),
 *   field_types = {"fieldception"}
 * )
 */
class Fieldception extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'inline' => FALSE,
      'fields' => [],
    ] + parent::defaultSettings();
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
    $element['inline'] = [
      '#type' => 'checkbox',
      '#title' => t('Display as inline element'),
      '#default_value' => $settings['inline'],
    ];

    $element['fields'] = [
      '#tree' => TRUE,
    ];

    foreach ($field_settings['storage'] as $subfield => $config) {
      $subfield_field_settings = isset($field_settings['fields'][$subfield]['settings']) ? $field_settings['fields'][$subfield]['settings'] : [];
      $subfield_settings = isset($settings['fields'][$subfield]['settings']) ? $settings['fields'][$subfield]['settings'] : [];
      $subfield_definition = $fieldception_helper->getSubfieldDefinition($field_definition, $config, $subfield, $subfield_field_settings);
      $subfield_widget = $fieldception_helper->getSubfieldWidget($subfield_definition, $subfield_settings);

      $element['fields'][$subfield] = [
        '#type' => 'fieldset',
        '#title' => $config['label'],
        '#tree' => TRUE,
      ];
      $element['fields'][$subfield]['settings'] = [];
      $element['fields'][$subfield]['settings'] = $subfield_widget->settingsForm($element['fields'][$subfield]['settings'], $form_state);
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $settings = $this->getSettings();
    $field_settings = $this->getFieldSettings();
    $summary = [];

    if ($settings['inline']) {
      $summary[] = t('Display as inline element');
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  protected function formMultipleElements(FieldItemListInterface $items, array &$form, FormStateInterface $form_state) {
    $elements = parent::formMultipleElements($items, $form, $form_state);
    foreach (Element::children($elements) as $delta) {
      $elements[$delta] = $this->formElementRemoveTitle($elements[$delta], $delta);
    }
    return $elements;
  }

  /**
   * Hide titles and perform other operations on each subfield element.
   */
  protected function formElementRemoveTitle($element, $delta = 0) {
    if (is_array($element)) {
      foreach (Element::children($element) as $i) {
        $item = &$element[$i];
        $item = $this->formElementRemoveTitle($item, $delta);
        if (isset($item['#type']) && in_array($item['#type'], ['fieldset', 'details'])) {
          $item['#type'] = 'container';
        }
      }
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $fieldception_helper = \Drupal::service('fieldception.helper');
    $settings = $this->getSettings();
    $field_settings = $this->getFieldSettings();
    $field_definition = $this->fieldDefinition->getFieldStorageDefinition();
    $entity = $items->getEntity();
    $cardinality = $this->fieldDefinition->getFieldStorageDefinition()->getCardinality();

    $element['#type'] = $cardinality === 1 ? 'fieldset' : 'container';
    if ($settings['inline']) {
      $element['#attributes']['class'][] = 'container-inline';
    }

    foreach ($field_settings['storage'] as $subfield => $config) {
      $subfield_field_settings = isset($field_settings['fields'][$subfield]['settings']) ? $field_settings['fields'][$subfield]['settings'] : [];
      $subfield_settings = isset($settings['fields'][$subfield]['settings']) ? $settings['fields'][$subfield]['settings'] : [];
      $subfield_definition = $fieldception_helper->getSubfieldDefinition($field_definition, $config, $subfield, $subfield_field_settings);
      $subfield_widget = $fieldception_helper->getSubfieldWidget($subfield_definition, $subfield_settings);
      $subfield_items = $fieldception_helper->getSubfieldItemList($subfield_definition, $entity, $delta);

      $element[$subfield] = [
        '#type' => 'container',
        '#tree' => TRUE,
      ];
      $element[$subfield]['value'] = [
        '#title' => $config['label'],
        '#required' => FALSE,
      ];

      $element[$subfield]['value'] = $subfield_widget->formElement($subfield_items, 0, $element[$subfield]['value'], $form, $form_state);
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $fieldception_helper = \Drupal::service('fieldception.helper');
    $field_settings = $this->getFieldSettings();
    $field_definition = $this->fieldDefinition->getFieldStorageDefinition();

    $new_values = [];
    foreach ($values as $delta => $value) {
      foreach ($field_settings['storage'] as $subfield => $config) {
        $subfield_field_settings = isset($field_settings['fields'][$subfield]['settings']) ? $field_settings['fields'][$subfield]['settings'] : [];
        $subfield_definition = $fieldception_helper->getSubfieldDefinition($field_definition, $config, $subfield, $subfield_field_settings);
        $subfield_widget = $fieldception_helper->getSubfieldWidget($subfield_definition);

        $subvalues = $subfield_widget->massageFormValues([$value[$subfield]['value']], $form, $form_state);
        $subvalues = reset($subvalues);
        if (isset($subvalues[0])) {
          $subvalues = reset($subvalues);
        }
        foreach ($subvalues as $key => $subvalue) {
          $column = str_replace($subfield . '_', '', $key);
          if (!empty($subvalue)) {
            $new_values[$delta][$subfield . '_' . $column] = $subvalue;
          }
        }
      }
    }
    return $new_values;
  }

}
