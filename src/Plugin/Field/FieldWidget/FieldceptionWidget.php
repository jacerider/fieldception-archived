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
use Drupal\Component\Utility\SortArray;
use Drupal\Core\Render\Markup;
use Drupal\fieldception\Plugin\Field\FieldceptionTableTrait;
use Drupal\Core\Field\FieldDefinitionInterface;

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
  use FieldceptionTableTrait;

  /**
   * The fieldception helper.
   *
   * @var \Drupal\fieldception\FieldceptionHelper
   */
  protected $fieldceptionHelper;

  /**
   * {@inheritdoc}
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->fieldceptionHelper = \Drupal::service('fieldception.helper');
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'display' => 'default',
      'draggable' => FALSE,
      'primary' => FALSE,
      'fields_per_row' => 0,
      'more_label' => 'Add another item',
      'fields' => [],
    ] + parent::defaultSettings();
  }

  /**
   * Get display options.
   */
  public static function displayOptions() {
    return [
      'default' => t('Default'),
      'inline' => t('Inline'),
      'table' => t('Table'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $settings = $this->getSettings();
    $field_settings = $this->getFieldSettings();
    $field_definition = $this->fieldDefinition;
    $field_name = $this->fieldDefinition->getName();
    $cardinality = $this->fieldDefinition->getFieldStorageDefinition()->getCardinality();

    $element = [];
    $element['display'] = [
      '#type' => 'select',
      '#title' => $this->t('Display as'),
      '#default_value' => $settings['display'],
      '#options' => $this->displayOptions(),
      '#required' => TRUE,
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
      $element['primary'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Allow primary selection'),
        '#default_value' => $settings['primary'],
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
      $subfield_settings = (isset($settings['fields'][$subfield]) ? $settings['fields'][$subfield] : []) + [
        'position' => '',
        'size' => '',
      ];
      $subfield_widget_settings = isset($subfield_settings['settings']) ? $subfield_settings['settings'] : [];
      $subfield_definition = $this->fieldceptionHelper->getSubfieldDefinition($field_definition, $config, $subfield);
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
      $subfield_widget = $this->fieldceptionHelper->getSubfieldWidget($subfield_definition, $subfield_widget_type, $subfield_widget_settings);

      $element['fields'][$subfield] = [
        '#type' => 'fieldset',
        '#title' => $config['label'],
        '#tree' => TRUE,
        '#id' => $wrapper_id,
        '#element_validate' => [[get_class($this), 'settingsFormWidgetValidate']],
      ];
      $element['fields'][$subfield]['type'] = [
        '#type' => 'select',
        '#title' => $this->t('Widget'),
        '#options' => $this->fieldceptionHelper->getFieldWidgetPluginManager()->getOptions($subfield_definition->getBaseType()),
        '#default_value' => $subfield_widget_type,
        '#required' => TRUE,
        '#ajax' => [
          'callback' => [get_class($this), 'settingsFormAjax'],
          'wrapper' => $wrapper_id,
        ],
      ];
      $element['fields'][$subfield]['settings'] = [];
      $element['fields'][$subfield]['settings'] = $subfield_widget->settingsForm($element['fields'][$subfield]['settings'], $form_state);

      $element['fields'][$subfield]['position'] = [
        '#type' => 'select',
        '#title' => $this->t('Position'),
        '#options' => self::positionOptions(),
        '#default_value' => $subfield_settings['position'],
      ];
      $element['fields'][$subfield]['size'] = [
        '#type' => 'select',
        '#title' => $this->t('Size'),
        '#options' => self::sizeOptions(),
        '#default_value' => $subfield_settings['size'],
      ];
    }

    return $element;
  }

  /**
   * Validation for subfield fieldset.
   */
  public static function settingsFormWidgetValidate($element, $form_state) {
    $values = $form_state->getValue($element['#parents']);
    $form_state->setValue($element['#parents'], array_filter($values));
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

    $summary[] = $this->t('Display as: %value', ['%value' => $this->displayOptions()[$this->getSetting('display')]]);
    $summary[] = $this->t('Fields per row: %value', ['%value' => empty($settings['fields_per_row']) ? 'All' : $settings['fields_per_row']]);

    if ($cardinality !== 1) {
      $summary[] = $this->t('Allow ordering: %value', ['%value' => $settings['draggable'] ? 'Yes' : 'No']);
      $summary[] = $this->t('Allow primary selection: %value', ['%value' => $settings['primary'] ? 'Yes' : 'No']);
      $summary[] = $this->t('More label: %value', ['%value' => $settings['more_label']]);
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function form(FieldItemListInterface $items, array &$form, FormStateInterface $form_state, $get_delta = NULL) {
    $elements = parent::form($items, $form, $form_state, $get_delta);
    $cardinality = $this->fieldDefinition->getFieldStorageDefinition()->getCardinality();
    $allow_more = $cardinality == FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED && !$form_state->isProgrammed();
    $title = $this->fieldDefinition->getLabel();
    $description = FieldFilteredMarkup::create(\Drupal::token()->replace($this->fieldDefinition->getDescription()));

    // $storage = \Drupal::entityTypeManager()->getStorage('file');
    // $files = $storage->delete($storage->loadMultiple($storage->getQuery()->condition('fid', 335, '>')->execute()));
    // ksm($files);

    $elements['#type'] = 'fieldset';
    $elements['#title'] = $title;
    $elements['#description'] = $description;
    $elements['#attached']['library'][] = 'fieldception/field';
    $elements['#attributes']['class'][] = 'fieldception-field';

    if ($allow_more && is_null($get_delta)) {
      $field_name = $this->fieldDefinition->getName();
      $settings = $this->getSettings();
      $parents = $form['#parents'];
      $id_prefix = $elements['widget']['#id_prefix'];
      $wrapper_id = $elements['widget']['#wrapper_id'];
      $elements['#id'] = $wrapper_id;

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
    }
    $elements['widget']['#process'][] = [get_class($this), 'formWidgetProcess'];

    return $elements;
  }

  /**
   * Process widget element.
   */
  public static function formWidgetProcess(&$element, FormStateInterface $form_state) {
    // We want to unset the values of this pseudo-field so that the subfields
    // can process their valueCallbacks.
    $values = $form_state->getValue($element['#parents']);
    if (!empty($values)) {
      foreach ($values as $key => $value) {
        if (is_int($key)) {
          unset($values[$key]);
        }
      }
      $form_state->setValue($element['#parents'], $values);
    }
    return $element;
  }

  /**
   * Submission handler for the "Add another item" button.
   */
  public static function addMoreSubmit(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();

    // Go one level up in the form, to the widgets container.
    $element = NestedArray::getValue($form, array_merge(array_slice($button['#array_parents'], 0, -1), ['widget']));
    $field_name = $element['#field_name'];
    $parents = $element['#field_parents'];

    // Increment the items count.
    $field_state = static::getWidgetState($parents, $field_name, $form_state);
    $field_state['items_count']++;
    static::setWidgetState($parents, $field_name, $form_state, $field_state);

    $form_state->setRebuild();
  }

  /**
   * Ajax callback for the "Add another item" button.
   *
   * This returns the new page content to replace the page content made obsolete
   * by the form submission.
   */
  public static function addMoreAjax(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();

    // Go one level up in the form, to the widgets container.
    $element = NestedArray::getValue($form, array_merge(array_slice($button['#array_parents'], 0, -1), ['widget']));

    // Ensure the widget allows adding additional items.
    if ($element['#cardinality'] != FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED) {
      return;
    }

    return NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -1));
  }

  /**
   * {@inheritdoc}
   */
  protected function formMultipleElements(FieldItemListInterface $items, array &$form, FormStateInterface $form_state) {
    $cardinality = $this->fieldDefinition->getFieldStorageDefinition()->getCardinality();
    $settings = $this->getSettings();
    $parents = $form['#parents'];
    $field_name = $this->fieldDefinition->getName();
    $field_state = static::getWidgetState($parents, $field_name, $form_state);
    $field_settings = $this->getFieldSettings();
    $allow_more = $cardinality == FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED && !$form_state->isProgrammed();
    $draggable = $allow_more && !empty($settings['draggable']);
    $primary = !$draggable && !empty($settings['primary']);

    if ($cardinality == FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED) {
      $field_state = static::getWidgetState($parents, $field_name, $form_state);
      $max = $field_state['items_count'];
    }
    else {
      $max = $cardinality;
      $is_multiple = ($cardinality > 1);
    }

    $elements = [
      '#type' => 'table',
      '#field_name' => $field_name,
      '#cardinality' => $cardinality,
      '#cardinality_multiple' => $this->fieldDefinition->getFieldStorageDefinition()->isMultiple(),
      '#required' => $this->fieldDefinition->isRequired(),
      '#max_delta' => $max,
    ];

    foreach ($field_settings['storage'] as $subfield => $config) {
      $elements['#header'][$subfield] = $config['label'];
      if (!empty($field_settings['fields'][$subfield]['required'])) {
        $elements['#header'][$subfield] .= '<span class="js-form-required form-required"></span>';
      }
      $elements['#header'][$subfield] = Markup::create($elements['#header'][$subfield]);
    }

    // Remove items that are no longer within delta limit.
    foreach ($items as $delta => $item) {
      if ($delta > $max) {
        $items->removeItem($delta);
      }
    }

    if ($max == 0) {
      if ($items->count()) {
        $items->removeItem(0);
      }
      $max = $field_state['items_count'] = 1;
      static::setWidgetState($parents, $field_name, $form_state, $field_state);
    }

    if ($allow_more) {
      $id_prefix = implode('-', array_merge($parents, [$field_name]));
      $wrapper_id = Html::getUniqueId($id_prefix . '-add-more-wrapper');
      $elements['#id_prefix'] = $id_prefix;
      $elements['#wrapper_id'] = $wrapper_id;
      $elements['#header'][] = '';
    }

    if ($draggable) {
      $elements['#header'][] = $this->t('Weight');
      $elements['#tabledrag'] = [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'table-sort-weight',
        ],
      ];
    }

    if ($primary) {
      array_unshift($elements['#header'], $this->t('Primary'));
      $elements['#element_validate'][] = [get_class($this), 'validatePrimary'];
    }

    for ($delta = 0; $delta <= $max; $delta++) {
      // Add a new empty item if it doesn't exist yet at this delta.
      if (!isset($items[$delta])) {
        $items->appendItem();
      }

      $element = [
        '#title' => '',
        '#descrition' => '',
      ];

      if ($delta == $max) {
        $element['#attributes']['class'][] = 'hidden';
      }

      if ($primary) {
        $element_parents = array_merge($form['#parents'], [$field_name, 'primary']);
        $element_path = array_shift($element_parents) . '[' . implode('][', $element_parents) . ']';
        $element['primary'] = [
          '#type' => 'radio',
          '#name' => $element_path,
          '#return_value' => $delta,
          '#wrapper_attributes' => [
            'class' => [
              'fieldception-primary',
              'fieldception-size-min',
              'fieldception-position-center',
            ],
          ],
        ];
      }

      $element = $this->formSingleElement($items, $delta, $element, $form, $form_state);

      if ($allow_more) {
        $element['actions'] = [
          '#type' => 'actions',
          '#wrapper_attributes' => ['class' => ['fieldception-actions', 'fieldception-size-min']],
          'remove_button' => [
            '#delta' => $delta,
            '#name' => strtr($id_prefix, '-', '_') . '_' . $delta . '_remove_button',
            '#type' => 'submit',
            '#value' => t('Remove'),
            '#validate' => [],
            '#submit' => [[$this, 'removeElementSubmit']],
            '#limit_validation_errors' => [],
            '#attributes' => [
              'class' => ['secondary', 'close'],
            ],
            '#ajax' => [
              'callback' => [$this, 'removeElementAjax'],
              'wrapper' => $wrapper_id,
              'effect' => 'fade',
            ],
          ],
        ];
      }

      if ($draggable) {
        $element['weight'] = [
          '#type' => 'weight',
          '#title' => $this->t('Weight'),
          '#title_display' => 'invisible',
          '#default_value' => $delta,
          '#attributes' => [
            'class' => [
              'table-sort-weight',
            ],
          ],
        ];
        $element['#attributes']['class'][] = 'draggable';
      }

      $elements[$delta] = $element;
    }

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public static function validatePrimary(&$element, FormStateInterface $form_state) {
    $element_values = $form_state->getValue($element['#parents']);
    $primary_delta = $form_state->getValue(array_merge($element['#parents'], ['primary']));
    unset($element_values['primary']);
    $new_values = [];
    foreach ($element_values as $delta => $value) {
      if ($delta == $primary_delta) {
        $delta = -1;
      }
      $new_values[$delta] = $value;
    }
    ksort($new_values);
    $form_state->setValue($element['#parents'], array_values($new_values));
  }

  /**
   * Submission handler for the "remove item" button.
   */
  public static function removeElementSubmit(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();
    $delta = $button['#delta'];
    $array_parents_to_field = array_slice($button['#array_parents'], 0, -4);
    $parents_to_field = array_slice($button['#parents'], 0, -3);
    $parent_element = NestedArray::getValue($form, array_merge($array_parents_to_field, ['widget']));
    $field_name = $parent_element['#field_name'];
    $field_parents = $parent_element['#field_parents'];
    $field_state = static::getWidgetState($field_parents, $field_name, $form_state);
    $user_input = $form_state->getUserInput();
    $user_input_field_values = NestedArray::getValue($user_input, $parents_to_field);
    unset($user_input_field_values['primary']);
    unset($user_input_field_values[$delta]);

    if (!empty($user_input_field_values)) {
      $user_input_field_values = array_map(function ($key, $item) {
        if (is_int($key)) {
          $item['_weight'] = $key;
        }
        return $item;
      }, array_keys(array_values($user_input_field_values)), array_values($user_input_field_values));
    }

    NestedArray::setValue($user_input, $parents_to_field, $user_input_field_values);
    $form_state->setUserInput($user_input);

    $field_state['items_count']--;

    static::setWidgetState($field_parents, $field_name, $form_state, $field_state);
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
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $settings = $this->getSettings();
    $field_settings = $this->getFieldSettings();
    $field_definition = $this->fieldDefinition;
    $field_name = $field_definition->getName();
    $entity = $items->getEntity();
    $subform = $form;
    $subform['#parents'][] = $field_name;
    $subform['#parents'][] = $delta;
    foreach ($field_settings['storage'] as $subfield => $config) {
      $subfield_settings = isset($settings['fields'][$subfield]) ? $settings['fields'][$subfield] : [];
      $subfield_widget_settings = isset($subfield_settings['settings']) ? $subfield_settings['settings'] : [];
      $subfield_definition = $this->fieldceptionHelper->getSubfieldDefinition($field_definition, $config, $subfield);
      $subfield_widget_type = $this->getSubfieldWidgetType($subfield_definition);
      $subfield_widget = $this->fieldceptionHelper->getSubfieldWidget($subfield_definition, $subfield_widget_type, $subfield_widget_settings);
      $subfield_items = $this->fieldceptionHelper->getSubfieldItemList($subfield_definition, $entity, $delta);
      if (!$subfield_items->count()) {
        // We are cleaning up empty form items in extractFormValues so we need
        // to make sure we at least have an empty item.
        $subfield_items->appendItem();
      }

      $element[$subfield] = [
        '#title' => '',
        '#description' => '',
        '#required' => FALSE,
        '#field_name' => $field_definition->getName(),
        '#field_parents' => $subform['#parents'],
        '#delta' => 0,
        '#weight' => 0,
        // Integrations with field_labels module.
        '#title_lock' => TRUE,
        '#element_validate' => [[get_class($this), 'formElementValidate']],
        '#subfield_config' => $config,
      ];
      $element[$subfield] = $subfield_widget->formElement(
        $subfield_items,
        0,
        $element[$subfield],
        $form,
        $form_state
      );

      if (isset($element[$subfield]['#type']) && in_array($element[$subfield]['#type'], ['fieldset', 'details'])) {
        $element[$subfield]['#type'] = 'container';
      }

      if (!empty($subfield_settings['position'])) {
        $element[$subfield]['#wrapper_attributes']['class'][] = 'fieldception-position-' . $subfield_settings['position'];
      }

      if (!empty($subfield_settings['size'])) {
        $element[$subfield]['#wrapper_attributes']['class'][] = 'fieldception-size-' . $subfield_settings['size'];
      }
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function formElementValidate($element, FormStateInterface $form_state) {
    // ksm($form_state->getValues());
  }

  /**
   * {@inheritdoc}
   */
  public function errorElement(array $element, ConstraintViolationInterface $error, array $form, FormStateInterface $form_state) {
    if (!empty($error->arrayPropertyPath)) {
      $subfield_path = $error->arrayPropertyPath[0];
      $matches = [];
      $field_settings = $this->getFieldSettings();
      foreach ($field_settings['storage'] as $subfield => $config) {
        if (substr($subfield_path, 0, strlen($subfield . '_')) === $subfield . '_') {
          $matches[$subfield] = substr($subfield_path, strlen($subfield . '_'));
        }
      }
      foreach (Element::children($element) as $delta) {
        if (substr($delta, 0, 6) == 'group_') {
          foreach ($matches as $subfield => $match) {
            if (isset($element[$delta][$subfield][$match])) {
              return $element[$delta][$subfield][$match];
            }
          }
        }
      }
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function extractFormValues(FieldItemListInterface $items, array $form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();

    // Extract the values from $form_state->getValues().
    $path = array_merge($form['#parents'], [$field_name]);
    $key_exists = NULL;
    $values = NestedArray::getValue($form_state->getValues(), $path, $key_exists);

    $cardinality = $this->fieldDefinition->getFieldStorageDefinition()->getCardinality();
    $allow_more = $cardinality == FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED && !$form_state->isProgrammed();
    if ($allow_more && !empty($values)) {
      // When allow more, we always have a hidden empty element.
      array_pop($values);
    }

    if ($key_exists) {
      // Account for drag-and-drop reordering if needed.
      if (!$this->handlesMultipleValues()) {
        // Remove the 'value' of the 'add more' button.
        unset($values['add_more']);

        // The original delta, before drag-and-drop reordering, is needed to
        // route errors to the correct form element.
        foreach ($values as $delta => &$value) {
          $value['_original_delta'] = $delta;
        }

        usort($values, function ($a, $b) {
          return SortArray::sortByKeyInt($a, $b, '_weight');
        });
      }

      $field_state = static::getWidgetState($form['#parents'], $field_name, $form_state);
      $field_name = $this->fieldDefinition->getName();
      $settings = $this->getSettings();
      $field_settings = $this->getFieldSettings();
      $field_definition = $this->fieldDefinition;
      $entity = $items->getEntity();
      $empty_item_subfields = [];

      foreach ($values as $delta => $val) {
        $subform = $form;
        $subform['#parents'][] = $field_name;
        $subform['#parents'][] = $delta;
        $new_values[$delta] = [];
        foreach ($field_settings['storage'] as $subfield => $config) {
          $subfield_field_settings = isset($field_settings['fields'][$subfield]) ? $field_settings['fields'][$subfield] : [];
          $subfield_value = NULL;
          if (isset($values[$delta][$subfield])) {
            $subfield_value = $values[$delta][$subfield];
          }
          if (isset($values[$delta][$field_name . ':' . $subfield][0])) {
            $subfield_value = $values[$delta][$field_name . ':' . $subfield][0];
          }
          if (is_null($subfield_value)) {
            continue;
          }
          $subfield_settings = isset($settings['fields'][$subfield]) ? $settings['fields'][$subfield] : [];
          $subfield_widget_settings = isset($subfield_settings['settings']) ? $subfield_settings['settings'] : [];
          $subfield_definition = $this->fieldceptionHelper->getSubfieldDefinition($field_definition, $config, $subfield);
          $subfield_name = $subfield_definition->getName();
          $subfield_widget_type = $this->getSubfieldWidgetType($subfield_definition);
          $subfield_widget = $this->fieldceptionHelper->getSubfieldWidget($subfield_definition, $subfield_widget_type, $subfield_widget_settings);
          $subfield_items = $this->fieldceptionHelper->getSubfieldItemList($subfield_definition, $entity, $delta);
          $subfield_path = array_merge($subform['#parents'], [$subfield_name]);
          $subfield_widget_path = array_merge($form['#parents'], [
            $field_name,
            'widget',
            $delta,
            $subfield,
          ]);
          $subfield_widget_array_path = array_merge($form['#array_parents'], [
            $field_name,
            'widget',
            $delta,
            $subfield,
          ]);
          $subform[$subfield_name]['widget'][0] = NestedArray::getValue($form, $subfield_widget_path);
          if (!$subfield_widget->handlesMultipleValues()) {
            $subfield_value = [$subfield_value];
          }
          $form_state->setValue($subfield_path, $subfield_value);
          WidgetBase::setWidgetState($subform['#parents'], $subfield_name, $form_state, $field_state);
          $subfield_widget->extractFormValues($subfield_items, $subform, $form_state);
          if ($subfield_items->isEmpty() && !empty($subfield_field_settings['required'])) {
            $empty_item_subfields[$delta][$subfield] = [
              'label' => $config['label'],
              'path' => $subfield_widget_array_path,
            ];
          }
          $subvalues = $subfield_items->getValue();
          if (!empty($subvalues)) {
            $subvalues = reset($subvalues);
            foreach ($subvalues as $key => $subvalue) {
              if ($subvalue || $subvalue === '0') {
                $new_values[$delta][$subfield . '_' . $key] = $subvalue;
              }
            }
          }
        }
        // Remove empty rows.
        $row_values = NestedArray::filter($new_values[$delta]);
        if (empty($row_values)) {
          unset($new_values[$delta]);
        }
      }

      // Let the widget massage the submitted values.
      $values = $this->massageFormValues($new_values, $form, $form_state);

      // Assign the values and remove the empty ones.
      $items->setValue($values);
      $items->filterEmptyItems();

      // Put delta mapping in $form_state, so that flagErrors() can use it.
      foreach ($items as $delta => $item) {
        if (!empty($empty_item_subfields[$delta])) {
          foreach ($empty_item_subfields[$delta] as $subfield => $data) {
            $error_element = NestedArray::getValue($form_state->getCompleteForm(), $data['path']);
            ksm($data['path']);
            ksm($error_element);
            if ($error_element) {
              $form_state->setError($error_element, $this->t('%label is required.', ['%label' => $data['label']]));
            }
          }
        }
        $field_state['original_deltas'][$delta] = isset($item->_original_delta) ? $item->_original_delta : $delta;
        unset($item->_original_delta, $item->_weight);
      }
      static::setWidgetState($form['#parents'], $field_name, $form_state, $field_state);
    }
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
    return $this->fieldceptionHelper->getSubfieldDefaultWidget($subfield_definition);
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
        $settings['storage'][$subfield]['settings'] = $settings['fields'][$subfield]['settings'] + $settings['storage'][$subfield]['settings'];
      }
    }
    return $settings;
  }

}
