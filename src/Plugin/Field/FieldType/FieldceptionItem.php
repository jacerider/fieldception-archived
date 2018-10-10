<?php

namespace Drupal\fieldception\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Component\Utility\NestedArray;

/**
 * Plugin implementation of the 'fieldception' field type.
 *
 * @FieldType(
 *   id = "fieldception",
 *   label = @Translation("Fieldception"),
 *   description = @Translation("Fields within a field."),
 *   default_widget = "fieldception",
 *   default_formatter = NULL,
 * )
 */
class FieldceptionItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  protected function getSettings() {
    $fieldception_helper = \Drupal::service('fieldception.helper');
    $settings = parent::getSettings();
    $field_definition = $this->getFieldDefinition()->getFieldStorageDefinition();

    foreach ($settings['storage'] as $subfield => $config) {
      $subfield_definition = $fieldception_helper->getSubfieldDefinition($field_definition, $config, $subfield);
      $subfield_storage = $fieldception_helper->getSubfieldStorage($subfield_definition);
      $settings['fields'][$subfield] = isset($settings['fields'][$subfield]) ? $settings['fields'][$subfield] : [];
      // Merge in the subfield field settings.
      $settings['fields'][$subfield] += ['settings' => []];
      $settings['fields'][$subfield]['settings'] = NestedArray::mergeDeep(
        $subfield_storage->defaultFieldSettings(),
        $settings['fields'][$subfield]['settings']
      );
      // For each storage field we want to merge in the default field settings.
      $settings['fields'][$subfield] = NestedArray::mergeDeep(
        $settings['fields_default'],
        $settings['fields'][$subfield]
      );
      // Merge into storage settings.
      $settings['storage'][$subfield] = NestedArray::mergeDeep(
        $settings['fields'][$subfield],
        $settings['storage'][$subfield]
      );
    }

    return $settings;
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultStorageSettings() {
    $default = [
      'type' => 'string',
      'label' => '',
      'settings' => [
        'maxlength' => 255,
      ],
    ];
    return parent::defaultStorageSettings() + [
      'storage_default' => $default,
      'storage' => [
        'value_0' => $default,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings() {
    return [
      'fields' => [],
      'fields_default' => [
        'required' => TRUE,
      ],
    ] + parent::defaultFieldSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function storageSettingsForm(array &$form, FormStateInterface $form_state, $has_data) {
    $fieldception_helper = \Drupal::service('fieldception.helper');
    $settings = $this->getSettings();
    $entity = $this->getEntity();
    $field_definition = $this->getFieldDefinition()->getFieldStorageDefinition();

    // Gather valid field types.
    $field_type_options = [];
    foreach ($fieldception_helper->getFieldTypePluginManager()->getGroupedDefinitions($fieldception_helper->getFieldTypePluginManager()->getUiDefinitions()) as $category => $field_types) {
      foreach ($field_types as $name => $field_type) {
        $field_type_options[$category][$name] = $field_type['label'];
      }
    }

    $element = [];
    $storage = $form_state->get('fieldception_storage');
    if (!count((array) $storage)) {
      $storage = $settings['storage'];
      $form_state->set('fieldception_storage_current', $settings['storage']);
      $form_state->set('fieldception_storage_default', $settings['storage_default']);
      $form_state->set('fieldception_storage', $settings['storage']);
    }

    $element['storage'] = [
      '#type' => 'value',
      '#value' => $settings['storage'],
    ];

    $element['_storage'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Fields'),
      '#prefix' => '<div id="fieldception-fields">',
      '#suffix' => '</div>',
      '#tree' => TRUE,
      '#element_validate' => [[get_class($this), 'validateStorage']],
    ];

    $count = 1;
    $user_input = $form_state->getUserInput();
    foreach ($storage as $subfield => $config) {
      $config['type'] = isset($user_input['settings']['_storage'][$subfield]['type']) ? $user_input['settings']['_storage'][$subfield]['type'] : $config['type'];

      $subfield_definition = $fieldception_helper->getSubfieldDefinition($field_definition, $config, $subfield);
      $subfield_items = $fieldception_helper->getSubfieldItemList($subfield_definition, $entity);
      $subfield_storage = $fieldception_helper->getSubfieldStorage($subfield_definition, $subfield_items);

      $id = 'fieldception-fields-' . $subfield;
      $element['_storage'][$subfield] = [
        '#type' => 'details',
        '#title' => $this->t('Field %count', ['%count' => $count]),
        '#open' => TRUE,
        '#id' => $id,
      ];
      $element['_storage'][$subfield]['type'] = [
        '#type' => 'select',
        '#title' => $this->t('Field type'),
        '#default_value' => $config['type'],
        '#disabled' => $has_data,
        '#required' => TRUE,
        '#options' => $field_type_options,
        '#ajax' => [
          'callback' => [get_class($this), 'refreshFieldAjax'],
          'wrapper' => $id,
        ],
      ];
      $element['_storage'][$subfield]['label'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Field label'),
        '#default_value' => $config['label'],
        '#required' => TRUE,
      ];

      $element['_storage'][$subfield]['settings'] = [];
      $element['_storage'][$subfield]['settings'] = $subfield_storage->storageSettingsForm($element['_storage'][$subfield]['settings'], $form_state, $has_data);

      // List validation has hardcoded database column names so we need to
      // override the validation. This is only an issue when list fields
      // check for changes in existing value lists.
      if ($has_data && in_array($config['type'], ['list_string']) && isset($element['_storage'][$subfield]['settings']['allowed_values']['#element_validate'])) {
        $has_validation = !empty(array_filter($element['_storage'][$subfield]['settings']['allowed_values']['#element_validate'], function ($callback) {
          return isset($callback[1]) && $callback[1] === 'validateAllowedValues';
        }));
        if ($has_validation) {
          $element['_storage'][$subfield]['settings']['allowed_values']['#field_has_data'] = FALSE;
          $element['_storage'][$subfield]['settings']['allowed_values']['#element_validate'][] = [
            get_class($this),
            'validateAllowedValuesWithData',
          ];
        }
      }
      $count++;
    }

    $element['add'] = [
      '#type' => 'submit',
      '#value' => t('Add field'),
      '#name' => 'add_storage',
      '#submit' => [[get_class($this), 'addFieldSubmit']],
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => [get_class($this), 'refreshFieldsAjax'],
        'wrapper' => 'fieldception-fields',
      ],
    ];
    $element['remove'] = [
      '#type' => 'submit',
      '#value' => t('Remove field'),
      '#name' => 'remove_storage',
      '#submit' => [[get_class($this), 'removeFieldSubmit']],
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => [get_class($this), 'refreshFieldsAjax'],
        'wrapper' => 'fieldception-fields',
      ],
    ];

    return $element;
  }

  /**
   * Form element validation handler for the 'uri' element.
   *
   * Disallows saving inaccessible or untrusted URLs.
   */
  public static function validateStorage($element, FormStateInterface $form_state, $form) {
    $fields = $form_state->getValue(['settings', '_storage']);
    foreach ($fields as $subfield => $config) {
      $fields[$subfield] += [
        'settings' => [],
      ];
    }
    $form_state->setValue(['settings', 'storage'], $fields);
  }

  /**
   * Callback for both ajax-enabled buttons.
   */
  public static function refreshFieldAjax(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();
    // Go one level up in the form, to the widgets container.
    $element = NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -1));
    return $element;
  }

  /**
   * Callback for both ajax-enabled buttons.
   */
  public static function refreshFieldsAjax(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();
    // Go one level up in the form, to the widgets container.
    $element = NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -1));
    return $element['_storage'];
  }

  /**
   * Submit handler for the "add storage" button.
   */
  public static function addFieldSubmit(array $form, FormStateInterface $form_state) {
    $storage_defaults = $form_state->get('fieldception_storage_default');
    $storage = $form_state->get('fieldception_storage');
    $count = count($storage);
    $storage['value_' . $count] = $storage_defaults;
    $form_state->set('fieldception_storage', $storage);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Submit handler for the "remove storage" button.
   */
  public static function removeFieldSubmit(array $form, FormStateInterface $form_state) {
    $storage = $form_state->get('fieldception_storage');
    array_pop($storage);
    $form_state->set('fieldception_storage', $storage);
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public static function calculateStorageDependencies(FieldStorageDefinitionInterface $field_definition) {
    $fieldception_helper = \Drupal::service('fieldception.helper');
    $settings = $field_definition->getSettings();
    $dependencies = parent::calculateStorageDependencies($field_definition);
    foreach ($settings['storage'] as $subfield => $config) {
      $type = $config['type'];
      // Check if we're dealing with a preconfigured field.
      if (strpos($type, 'field_ui:') !== FALSE) {
        // @see \Drupal\field_ui\Form\FieldStorageAddForm::submitForm
        list(, $type, $option_key) = explode(':', $type, 3);
      }
      $field_storage_definition = $fieldception_helper->getFieldTypePluginManager()->getDefinition($type);
      $dependencies['module'][] = $field_storage_definition['provider'];
    }
    return $dependencies;
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state) {
    $fieldception_helper = \Drupal::service('fieldception.helper');
    $settings = $this->getSettings();
    $entity = $this->getEntity();
    $field_definition = $this->getFieldDefinition()->getFieldStorageDefinition();

    $element = [];

    $element['fields'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Fields'),
      '#prefix' => '<div id="fieldception-fields">',
      '#suffix' => '</div>',
      '#tree' => TRUE,
      '#element_validate' => [[get_class($this), 'validateFields']],
    ];

    foreach ($settings['storage'] as $subfield => $config) {
      $subfield_definition = $fieldception_helper->getSubfieldDefinition($field_definition, $config, $subfield);
      $subfield_items = $fieldception_helper->getSubfieldItemList($subfield_definition, $entity);
      $subfield_storage = $fieldception_helper->getSubfieldStorage($subfield_definition, $subfield_items);
      $subfield_form_state = $fieldception_helper->getSubfieldFormState($subfield_definition, $form_state);

      $element['fields'][$subfield] = [
        '#type' => 'details',
        '#title' => $config['label'],
        '#open' => TRUE,
        '#tree' => TRUE,
      ];

      $element['fields'][$subfield]['required'] = [
        '#type' => 'checkbox',
        '#title' => t('Required'),
        '#default_value' => $config['required'],
      ];

      $element['fields'][$subfield]['settings'] = [];
      $element['fields'][$subfield]['settings'] = $subfield_storage->fieldSettingsForm($element['fields'][$subfield]['settings'], $subfield_form_state);
    }

    return $element;
  }

  /**
   * Make sure each field has a settings array value.
   */
  public static function validateFields($element, FormStateInterface $form_state, $form) {
    $fields = $form_state->getValue(['settings', 'fields']);
    foreach ($fields as $subfield => $config) {
      $fields[$subfield] += [
        'settings' => [],
      ];
    }
    $form_state->setValue(['settings', 'fields'], $fields);
  }

  /**
   * {@inheritdoc}
   */
  public function getConstraints() {
    $fieldception_helper = \Drupal::service('fieldception.helper');
    $settings = $this->getSettings();
    $entity = $this->getEntity();
    $field_definition = $this->getFieldDefinition()->getFieldStorageDefinition();

    $constraint_manager = \Drupal::typedDataManager()->getValidationConstraintManager();
    $constraints = parent::getConstraints();

    $subfield_constraints = [];
    foreach ($settings['storage'] as $subfield => $config) {
      $subfield_definition = $fieldception_helper->getSubfieldDefinition($field_definition, $config, $subfield);
      $subfield_items = $fieldception_helper->getSubfieldItemList($subfield_definition, $entity);
      $subfield_storage = $fieldception_helper->getSubfieldStorage($subfield_definition, $subfield_items);

      $field_constraints = $subfield_storage->getConstraints();
      if ($subfield_storage->getPluginId() == 'field_item:integer' && !$subfield_storage->getSetting('unsigned')) {
        // Amazingly, Drupal 8 does not check max size on signed integer fields.
        // @see https://www.drupal.org/project/drupal/issues/2722781
        $label = $subfield_definition->getLabel();
        list($min, $max) = $this->getRange($subfield_storage->getSetting('unsigned'), $subfield_storage->getSetting('size'));
        $field_constraints[] = $constraint_manager->create('ComplexData', [
          'value' => [
            'Range' => [
              'min' => $min,
              'minMessage' => t('%name: the value may be no less than %min.', [
                '%name' => $label,
                '%min' => $min,
              ]),
              'max' => $max,
              'maxMessage' => t('%name: the value may be no greater than %max.', [
                '%name' => $label,
                '%max' => $max,
              ]),
            ],
          ],
        ]);
      }

      foreach ($field_constraints as $subconstraint) {
        if (!isset($subconstraint->properties)) {
          continue;
        }
        foreach ($subconstraint->properties as $column => $types) {
          $subfield_column = $subfield . '_' . $column;
          foreach ($types as $type_name => $type_constraint) {
            $subfield_constraints[$subfield_column][$type_name] = $type_constraint;
          }
        }
      }
      foreach ($subfield_storage::propertyDefinitions($subfield_definition) as $column => $property) {
        if ($property->isComputed()) {
          continue;
        }
        $subfield_column = $subfield . '_' . $column;
        if (!empty($settings['fields'][$subfield]['required'])) {
          // NotBlank validator is not suitable for booleans because it does not
          // recognize '0' as an empty value.
          if ($subfield_definition->getType() == 'boolean') {
            $subfield_constraints[$subfield_column]['NotEqualTo']['value'] = 0;
            $subfield_constraints[$subfield_column]['NotEqualTo']['message'] = t('This value should not be blank.');
          }
          else {
            $subfield_constraints[$subfield_column]['NotBlank'] = [];
          }
        }
      }
    }

    $constraints[] = $constraint_manager->create('ComplexData', $subfield_constraints);
    return $constraints;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $fieldception_helper = \Drupal::service('fieldception.helper');
    $settings = $this->getSettings();
    $entity = $this->getEntity();
    $field_definition = $this->getFieldDefinition()->getFieldStorageDefinition();

    foreach ($settings['storage'] as $subfield => $config) {
      $subfield_definition = $fieldception_helper->getSubfieldDefinition($field_definition, $config, $subfield);
      $subfield_items = $fieldception_helper->getSubfieldItemList($subfield_definition, $entity, 0, $this->getValue());
      $subfield_storage = $fieldception_helper->getSubfieldStorage($subfield_definition, $subfield_items);

      if (!$subfield_storage->isEmpty()) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $fieldception_helper = \Drupal::service('fieldception.helper');
    $settings = $field_definition->getSettings();

    $columns = [];
    foreach ($settings['storage'] as $subfield => $config) {
      $subfield_definition = $fieldception_helper->getSubfieldDefinition($field_definition, $config, $subfield);
      $subfield_storage = $fieldception_helper->getSubfieldStorage($subfield_definition);
      $schema = $subfield_storage::schema($subfield_definition);
      if (isset($schema['columns'])) {
        foreach ($schema['columns'] as $column_name => $column) {
          $column['allow null'] = TRUE;
          $columns[$subfield . '_' . $column_name] = $column;
        }
      }
    }
    return ['columns' => $columns];
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $fieldception_helper = \Drupal::service('fieldception.helper');
    $settings = $field_definition->getSettings();

    $properties = [];
    foreach ($settings['storage'] as $subfield => $config) {
      $subfield_definition = $fieldception_helper->getSubfieldDefinition($field_definition, $config, $subfield);
      $subfield_storage = $fieldception_helper->getSubfieldStorage($subfield_definition);
      foreach ($subfield_storage::propertyDefinitions($subfield_definition) as $property_name => $property) {
        $property->setRequired(FALSE);
        $properties[$subfield . '_' . $property_name] = $property;
      }
    }
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($values, $notify = TRUE) {
    $fieldception_helper = \Drupal::service('fieldception.helper');
    $settings = $this->getSettings();
    $entity = $this->getEntity();
    $field_definition = $this->getFieldDefinition()->getFieldStorageDefinition();
    foreach ($settings['storage'] as $subfield => $config) {
      $subfield_definition = $fieldception_helper->getSubfieldDefinition($field_definition, $config, $subfield);
      $subfield_storage = $fieldception_helper->getSubfieldStorage($subfield_definition);
      $subfield_values = $fieldception_helper->convertValueToSubfieldValue($subfield_definition, $values);
      $subfield_storage->setValue($subfield_values);
    }
    parent::setValue($values, $notify);
  }

  /**
   * The #element_validate callback for options field allowed values.
   *
   * @param array $element
   *   An associative array containing the properties and children of the
   *   generic form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form for the form this element belongs to.
   *
   * @see \Drupal\Core\Render\Element\FormElement::processPattern()
   */
  public static function validateAllowedValuesWithData(array $element, FormStateInterface $form_state) {
    $values = $form_state->getValue($element['#parents']);

    // Prevent removing values currently in use.
    $lost_keys = array_keys(array_diff_key($element['#allowed_values'], $values));
    if (self::optionsValuesInUse($element['#entity_type'], $element['#field_name'], $lost_keys)) {
      $form_state->setError($element, t('Allowed values list: some values are being removed while currently in use.'));
    }
  }

  /**
   * Checks if a list of values are being used in actual field values.
   *
   * This is a clone of Drupal core function _options_values_in_use that takes
   * into account subfield keys.
   */
  protected static function optionsValuesInUse($entity_type, $field_name, $values) {
    if ($values) {
      $factory = \Drupal::service('entity.query');
      $parts = explode(':', $field_name);
      $result = $factory->get($entity_type)
        ->condition($parts[0] . '.' . $parts[1] . '_value', $values, 'IN')
        ->count()
        ->accessCheck(FALSE)
        ->range(0, 1)
        ->execute();
      if ($result) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Calculate the range of numbers allowed for the given size and sign.
   *
   * @param bool $unsigned
   *   TRUE if unsigned numbers expected.
   * @param string $size
   *   One of tiny, small, medium, normal or big.
   *
   * @return array
   *   Array containing minimum and maximum that will fit the given size and
   *   sign.
   */
  protected function getRange($unsigned, $size) {
    $bytes = [
      'tiny' => 1,
      'small' => 2,
      'medium' => 3,
      'normal' => 4,
      'big' => 8,
    ];
    $range = pow(2, $bytes[$size] * 8);
    if ($unsigned) {
      return [0, $range - 1];
    }
    return [-1 * ($range / 2), ($range / 2) - 1];
  }

}
