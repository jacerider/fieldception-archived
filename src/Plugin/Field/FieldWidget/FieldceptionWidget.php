<?php

namespace Drupal\fieldception\Plugin\Field\FieldWidget;

use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Plugin implementation of the 'double_field' widget.
 *
 * @FieldWidget(
 *   id = "fieldception",
 *   label = @Translation("Fieldception"),
 *   field_types = {"fieldception"}
 * )
 */
class FieldceptionWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'inline' => TRUE,
      'draggable' => FALSE,
      'fields_per_row' => 0,
      'more_label' => 'Add another item',
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
    $field_name = $this->fieldDefinition->getName();
    $cardinality = $this->fieldDefinition->getFieldStorageDefinition()->getCardinality();

    $element = [];
    $element['inline'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display as inline element'),
      '#default_value' => $settings['inline'],
    ];

    $options = [0 => 'All fields in same row'];
    for ($i = 1; $i <= count($field_settings['storage']); $i++) {
      $options[$i] = $i;
    }
    $element['fields_per_row'] = [
      '#type' => 'select',
      '#options' => $options,
      '#required' => TRUE,
      '#title' => $this->t('Fields per row'),
      '#default_value' => $settings['fields_per_row'],
    ];

    if ($cardinality !== 1) {
      $element['draggable'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Allow values to be ordered'),
        '#default_value' => $settings['draggable'],
      ];
      $element['more_label'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Label for add more button'),
        '#required' => TRUE,
        '#default_value' => $settings['more_label'],
      ];
    }

    $element['fields'] = [
      '#tree' => TRUE,
    ];

    foreach ($field_settings['storage'] as $subfield => $config) {
      $wrapper_id = Html::getId('fieldception-' . $field_name . '-' . $subfield);

      $subfield_settings = isset($settings['fields'][$subfield]['settings']) ? $settings['fields'][$subfield]['settings'] : [];
      $subfield_definition = $fieldception_helper->getSubfieldDefinition($field_definition, $config, $subfield);
      // Get type. First use submitted value, Then current settings. Then
      // default formatter if nothing has been set yet.
      $subfield_widget_type = $form_state->getValue([
        'fields',
        $field_name,
        'settings_edit_form',
        'settings',
        'fields',
        $subfield,
        'type',
      ]) ?: $this->getSubfieldWidgetType($subfield_definition);
      $subfield_widget = $fieldception_helper->getSubfieldWidget($subfield_definition, $subfield_widget_type, $subfield_settings);

      $element['fields'][$subfield] = [
        '#type' => 'fieldset',
        '#title' => $config['label'],
        '#tree' => TRUE,
        '#id' => $wrapper_id,
      ];
      $element['fields'][$subfield]['type'] = [
        '#type' => 'select',
        '#title' => $this->t('Widget'),
        '#options' => $fieldception_helper->getFieldWidgetPluginManager()->getOptions($subfield_definition->getBaseType()),
        '#default_value' => $subfield_widget_type,
        '#required' => TRUE,
        '#ajax' => [
          'callback' => [get_class($this), 'settingsFormAjax'],
          'wrapper' => $wrapper_id,
        ],
      ];
      $element['fields'][$subfield]['settings'] = [];
      $element['fields'][$subfield]['settings'] = $subfield_widget->settingsForm($element['fields'][$subfield]['settings'], $form_state);
    }

    return $element;
  }

  /**
   * Ajax callback for the handler settings form.
   *
   * @see static::fieldSettingsForm()
   */
  public static function settingsFormAjax($form, FormStateInterface $form_state) {
    $element = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($element['#array_parents'], 0, -1));
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $settings = $this->getSettings();
    $field_settings = $this->getFieldSettings();
    $cardinality = $this->fieldDefinition->getFieldStorageDefinition()->getCardinality();

    $summary = [];

    $summary[] = $this->t('Display as inline element: %value', ['%value' => $settings['inline'] ? 'Yes' : 'No']);
    $summary[] = $this->t('Fields per row: %value', ['%value' => empty($settings['fields_per_row']) ? 'All' : $settings['fields_per_row']]);

    if ($cardinality !== 1) {
      $summary[] = $this->t('Allow ordering: %value', ['%value' => $settings['draggable'] ? 'Yes' : 'No']);
      $summary[] = $this->t('More label: %value', ['%value' => $settings['more_label']]);
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  protected function formMultipleElements(FieldItemListInterface $items, array &$form, FormStateInterface $form_state) {
    $elements = parent::formMultipleElements($items, $form, $form_state);
    $settings = $this->getSettings();
    $cardinality = $this->fieldDefinition->getFieldStorageDefinition()->getCardinality();
    $is_multiple = $cardinality !== 1;

    foreach (Element::children($elements) as $delta) {
      $elements[$delta] = $this->formElementRemoveTitle($elements[$delta], $delta);
    }

    if ($elements) {
      if (isset($elements['add_more'])) {
        $elements['add_more']['#value'] = $settings['more_label'];
      }

      if ($is_multiple) {
        $elements['#fieldception_drag'] = $settings['draggable'];
        if (empty($settings['draggable'])) {
          foreach (Element::children($elements) as $delta) {
            $element = &$elements[$delta];
            unset($element['_weight']);
          }
        }
      }

      // Allow modules to alter the full field widget form element.
      $context = [
        'form' => $form,
        'widget' => $this,
        'items' => $items,
        'delta' => $delta,
        'default' => $this->isDefaultValueWidget($form_state),
      ];
      \Drupal::moduleHandler()->alter(['field_widget_form', 'field_widget_' . $this->getPluginId() . '_form'], $elements, $form_state, $context);
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
    $element['#attributes']['class'][] = $cardinality === 1 ? 'fieldception-single' : 'fieldception-multiple';

    $count = $group = 1;
    $fields_per_row = $settings['fields_per_row'];
    $element['#attributes']['class'][] = 'fieldception-groups-' . $fields_per_row;

    foreach ($field_settings['storage'] as $subfield => $config) {
      $subfield_field_settings = isset($field_settings['fields'][$subfield]['settings']) ? $field_settings['fields'][$subfield]['settings'] : [];
      $subfield_settings = isset($settings['fields'][$subfield]['settings']) ? $settings['fields'][$subfield]['settings'] : [];
      $subfield_definition = $fieldception_helper->getSubfieldDefinition($field_definition, $config, $subfield);
      $subfield_widget_type = $this->getSubfieldWidgetType($subfield_definition);
      $subfield_widget = $fieldception_helper->getSubfieldWidget($subfield_definition, $subfield_widget_type, $subfield_settings);
      $subfield_items = $fieldception_helper->getSubfieldItemList($subfield_definition, $entity, $delta);

      if (!isset($element['group_' . $group])) {
        $element['group_' . $group] = [
          '#type' => 'container',
          '#process' => [[get_class(), 'processParents']],
          '#attributes' => ['class' => ['fieldception-group']],
        ];
        if ($settings['inline']) {
          $element['group_' . $group]['#attributes']['class'][] = 'container-inline';
        }
      }

      $element['group_' . $group][$subfield] = [
        '#type' => 'container',
      ];
      $element['group_' . $group][$subfield]['value'] = [
        '#title' => $config['label'],
        '#required' => FALSE,
        '#field_parents' => $element['#field_parents'],
        '#delta' => 0,
        '#weight' => 0,
        // Integrations with field_labels module.
        '#title_lock' => TRUE,
      ];

      $element['group_' . $group][$subfield]['value'] = $subfield_widget->formElement($subfield_items, 0, $element['group_' . $group][$subfield]['value'], $form, $form_state);

      if ($fields_per_row && $count >= $fields_per_row) {
        $count = 1;
        $group++;
      }
      else {
        $count++;
      }
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function processParents(&$element, FormStateInterface $form_state, &$complete_form) {
    array_pop($element['#parents']);
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function errorElement(array $element, ConstraintViolationInterface $error, array $form, FormStateInterface $form_state) {
    $subfield_delta = preg_replace('/\D/', '', $error->arrayPropertyPath[0]);
    foreach (Element::children($element) as $delta) {
      $group = $element[$delta];
      if (isset($group['value_' . $subfield_delta]['value'])) {
        return $element[$delta]['value_' . $subfield_delta]['value'];
      }
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $fieldception_helper = \Drupal::service('fieldception.helper');
    $field_settings = $this->getFieldSettings();
    $field_definition = $this->fieldDefinition->getFieldStorageDefinition();
    $schema = $field_definition->getSchema();

    $new_values = [];
    foreach ($values as $delta => $value) {
      $new_values[$delta] = [];
      foreach ($field_settings['storage'] as $subfield => $config) {
        $subfield_definition = $fieldception_helper->getSubfieldDefinition($field_definition, $config, $subfield);
        $subfield_widget_type = $this->getSubfieldWidgetType($subfield_definition);
        $subfield_widget = $fieldception_helper->getSubfieldWidget($subfield_definition, $subfield_widget_type);

        $subvalues = $subfield_widget->massageFormValues([$value[$subfield]['value']], $form, $form_state);
        $subvalues = reset($subvalues);
        if (isset($subvalues[0])) {
          $subvalues = reset($subvalues);
        }
        foreach ($subvalues as $key => $subvalue) {
          if ($subvalue || $subvalue === '0') {
            $column = str_replace($subfield . '_', '', $key);
            $new_values[$delta][$subfield . '_' . $column] = $subvalue;
          }
        }
      }
      // Remove empty rows.
      $row_values = NestedArray::filter($new_values[$delta]);
      if (empty($row_values)) {
        unset($new_values[$delta]);
      }
    }

    // Make sure each column at least has a blank value.
    foreach ($new_values as $delta => $values) {
      foreach ($schema['columns'] as $column_name => $column) {
        if (!isset($new_values[$delta][$column_name])) {
          $new_values[$delta][$column_name] = NULL;
        }
      }
    }

    return $new_values;
  }

  /**
   * Get subfield widget type.
   */
  protected function getSubfieldWidgetType($subfield_definition) {
    $subfield = $subfield_definition->getSubfield();
    $settings = $this->getSettings();
    if (!empty($settings['fields'][$subfield]['type'])) {
      return $settings['fields'][$subfield]['type'];
    }
    return \Drupal::service('fieldception.helper')->getSubfieldDefaultWidget($subfield_definition);
  }

  /**
   * {@inheritdoc}
   *
   * Merge field settings into storage settings to simplify configuration.
   */
  protected function getFieldSettings() {
    $settings = $this->fieldDefinition->getSettings();
    foreach ($settings['storage'] as $subfield => $config) {
      if (isset($settings['fields'][$subfield]['settings'])) {
        $settings['storage'][$subfield]['settings'] += $settings['fields'][$subfield]['settings'];
      }
    }
    return $settings;
  }

}
