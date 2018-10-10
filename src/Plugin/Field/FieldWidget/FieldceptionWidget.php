<?php

namespace Drupal\fieldception\Plugin\Field\FieldWidget;

use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\FieldFilteredMarkup;
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
    $field_name = $this->fieldDefinition->getName();
    $settings = $this->getSettings();
    $cardinality = $this->fieldDefinition->getFieldStorageDefinition()->getCardinality();
    $parents = $form['#parents'];

    // Determine the number of widgets to display.
    switch ($cardinality) {
      case FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED:
        $field_state = static::getWidgetState($parents, $field_name, $form_state);
        $max = $field_state['items_count'];
        $is_multiple = TRUE;
        break;

      default:
        $max = $cardinality - 1;
        $is_multiple = ($cardinality > 1);
        break;
    }

    $title = $this->fieldDefinition->getLabel();
    $description = FieldFilteredMarkup::create(\Drupal::token()->replace($this->fieldDefinition->getDescription()));

    $elements = [];

    for ($delta = 0; $delta <= $max; $delta++) {
      // Add a new empty item if it doesn't exist yet at this delta.
      if (!isset($items[$delta])) {
        $items->appendItem();
      }

      // For multiple fields, title and description are handled by the wrapping
      // table.
      if ($is_multiple) {
        $element = [
          '#title' => $this->t('@title (value @number)', ['@title' => $title, '@number' => $delta + 1]),
          '#title_display' => 'invisible',
          '#description' => '',
        ];
      }
      else {
        $element = [
          '#title' => $title,
          '#title_display' => 'before',
          '#description' => $description,
        ];
      }

      $element = $this->formSingleElement($items, $delta, $element, $form, $form_state);

      if ($element) {
        // Input field for the delta (drag-n-drop reordering).
        if ($is_multiple) {
          $elements['#fieldception_drag'] = $settings['draggable'];
          if ($settings['draggable']) {
            // We name the element '_weight' to avoid clashing with elements
            // defined by widget.
            $element['_weight'] = [
              '#type' => 'weight',
              '#title' => $this->t('Weight for row @number', ['@number' => $delta + 1]),
              '#title_display' => 'invisible',
              '#delta' => $max,
              '#default_value' => $items[$delta]->_weight ?: $delta,
              '#weight' => 100,
            ];
          }
        }

        $elements[$delta] = $element;
      }
    }

    if ($elements) {
      $elements += [
        '#theme' => 'field_multiple_value_form',
        '#field_name' => $field_name,
        '#cardinality' => $cardinality,
        '#cardinality_multiple' => $this->fieldDefinition->getFieldStorageDefinition()->isMultiple(),
        '#required' => $this->fieldDefinition->isRequired(),
        '#title' => $title,
        '#description' => $description,
        '#max_delta' => $max,
      ];

      // Add 'add more' button, if not working with a programmed form.
      if ($cardinality == FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED && !$form_state->isProgrammed()) {
        $id_prefix = implode('-', array_merge($parents, [$field_name]));
        $wrapper_id = Html::getUniqueId($id_prefix . '-add-more-wrapper');
        $elements['#prefix'] = '<div id="' . $wrapper_id . '">';
        $elements['#suffix'] = '</div>';

        $elements['add_more'] = [
          '#type' => 'submit',
          '#name' => strtr($id_prefix, '-', '_') . '_add_more',
          '#value' => $settings['more_label'],
          '#attributes' => ['class' => ['field-add-more-submit']],
          '#limit_validation_errors' => [array_merge($parents, [$field_name])],
          '#submit' => [[get_class($this), 'addMoreSubmit']],
          '#ajax' => [
            'callback' => [get_class($this), 'addMoreAjax'],
            'wrapper' => $wrapper_id,
            'effect' => 'fade',
          ],
        ];

        for ($delta = 0; $delta <= $max; $delta++) {
          $elements[$delta] = $this->formElementRemoveTitle($elements[$delta], $delta);
          $elements[$delta]['actions'] = [
            '#type' => 'actions',
            'remove_button' => [
              '#delta' => $delta,
              '#name' => strtr($id_prefix, '-', '_') . '_' . $delta . '_remove_button',
              '#type' => 'submit',
              '#value' => t('Remove item'),
              '#validate' => [],
              '#submit' => [[$this, 'removeElementSubmit']],
              '#limit_validation_errors' => [],
              '#attributes' => [
                'class' => ['secondary', 'close'],
              ],
              '#ajax' => [
                'callback' => [$this, 'removeElementAjax'],
                'wrapper' => $elements['add_more']['#ajax']['wrapper'],
                'effect' => 'fade',
              ],
            ],
          ];
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
   * Submission handler for the "remove item" button.
   */
  public static function removeElementSubmit(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();
    $delta = $button['#delta'];
    $array_parents = array_slice($button['#array_parents'], 0, -4);
    $old_parents = array_slice($button['#parents'], 0, -3);
    $parent_element = NestedArray::getValue($form, array_merge($array_parents, ['widget']));
    $field_name = $parent_element['#field_name'];
    $parents = $parent_element['#field_parents'];
    $field_state = static::getWidgetState($parents, $field_name, $form_state);
    for ($i = $delta; $i < $field_state['items_count']; $i++) {
      $old_element_widget_parents = array_merge($array_parents, ['widget', $i + 1]);
      $old_element_parents = array_merge($old_parents, [$i + 1]);
      $new_element_parents = array_merge($old_parents, [$i]);
      $moving_element = NestedArray::getValue($form, $old_element_widget_parents);
      $moving_element_input = NestedArray::getValue($form_state->getUserInput(), $old_element_parents);

      // Tell the element where it's being moved to.
      $moving_element['#parents'] = $new_element_parents;

      // Move the element around.
      $user_input = $form_state->getUserInput();
      NestedArray::setValue($user_input, $moving_element['#parents'], $moving_element_input);
      $user_input[$field_name] = array_filter($user_input[$field_name]);
      $form_state->setUserInput($user_input);
    }
    unset($parent_element[$delta]);
    NestedArray::setValue($form, $array_parents, $parent_element);

    if ($field_state['items_count'] > 0) {
      $field_state['items_count']--;
    }
    $input = NestedArray::getValue($form_state->getUserInput(), $array_parents);
    $weight = -1 * $field_state['items_count'];
    foreach ($input as $key => $item) {
      if ($item) {
        $input[$key]['_weight'] = $weight++;
      }
    }
    $user_input = $form_state->getUserInput();
    NestedArray::setValue($user_input, $array_parents, $input);
    $form_state->setUserInput($user_input);
    static::setWidgetState($parents, $field_name, $form_state, $field_state);
    $form_state->setRebuild();
  }

  /**
   * Ajax callback for the "remove item" button.
   *
   * This returns the new page content to replace the page content made obsolete
   * by the form submission.
   */
  public static function removeElementAjax(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -4));
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
    $field_name = $this->fieldDefinition->getName();
    $cardinality = $this->fieldDefinition->getFieldStorageDefinition()->getCardinality();
    $parents = $form['#parents'];

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

      $element['group_' . $group][$subfield]['value'] = $subfield_widget->formElement(
        $subfield_items,
        0,
        $element['group_' . $group][$subfield]['value'],
        $form,
        $form_state
      );

      $element['#group_count'] = $group;
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
        if (!isset($value[$subfield]['value'])) {
          // When form values are extracted from an entity they need to be
          // adapted to our expected value.
          $new_values[$delta] = $value;
          continue;
        }

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
