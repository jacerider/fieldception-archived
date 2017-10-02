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
class Fieldception extends FieldItemBase {

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
    }

    if ($this->subfieldSettings && isset($settings['storage'][$this->subfieldSettings])) {
      $settings = $settings['storage'][$this->subfieldSettings]['settings'];
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
    foreach ($fieldception_helper->getfieldTypePluginManager()->getGroupedDefinitions($fieldception_helper->getfieldTypePluginManager()->getUiDefinitions()) as $category => $field_types) {
      foreach ($field_types as $name => $field_type) {
        $field_type_options[$category][$name] = $field_type['label'];
      }
    }

    $element = [];
    $storage = $form_state->get('fieldception_storage');
    if (!count($storage)) {
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
      '#element_validate' => [[get_class($this), 'validateFields']],
    ];

    ksm($settings);

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
        '#disabled' => $has_data,
        '#required' => TRUE,
      ];

      $element['_storage'][$subfield]['settings'] = [];
      $element['_storage'][$subfield]['settings'] = $subfield_storage->storageSettingsForm($element['_storage'][$subfield]['settings'], $form_state, $has_data);
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
  public static function validateFields($element, FormStateInterface $form_state, $form) {
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
      $field_storage_definition = $fieldception_helper->getfieldTypePluginManager()->getDefinition($type);
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
    ];

    foreach ($settings['storage'] as $subfield => $config) {
      $field_config = $settings['fields'][$subfield];
      $subfield_definition = $fieldception_helper->getSubfieldDefinition($field_definition, $config, $subfield, $field_config['settings']);
      $subfield_items = $fieldception_helper->getSubfieldItemList($subfield_definition, $entity);
      $subfield_storage = $fieldception_helper->getSubfieldStorage($subfield_definition, $subfield_items);

      $element['fields'][$subfield] = [
        '#type' => 'details',
        '#title' => $config['label'],
        '#open' => TRUE,
        '#tree' => TRUE,
      ];

      $element['fields'][$subfield]['required'] = [
        '#type' => 'checkbox',
        '#title' => t('Required'),
        '#default_value' => $field_config['required'],
      ];

      $element['fields'][$subfield]['settings'] = [];
      $element['fields'][$subfield]['settings'] = $subfield_storage->fieldSettingsForm($element['fields'][$subfield]['settings'], $form_state);
    }

    return $element;
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

      foreach ($subfield_storage->getConstraints() as $subconstraint) {
        if (!isset($subconstraint->properties)) {
          continue;
        }
        foreach ($subconstraint->properties as $column => $types) {
          foreach ($types as $type_name => $type_constraint) {
            $subfield_constraints[$subfield . '_' . $column][$type_name] = $type_constraint;
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
      $subfield_items = $fieldception_helper->getSubfieldItemList($subfield_definition, $entity);
      $subfield_storage = $fieldception_helper->getSubfieldStorage($subfield_definition, $subfield_items);

      // if ($this->values) {
      //   // Pass in the current delta's values if they are set.
      //   dsm($this->values);
      //   $subfield_storage->setValue($fieldception_helper->convertValueToSubfieldValue($subfield_definition, $this->values));
      // }

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
        $properties[$subfield . '_' . $property_name] = $property;
      }
    }
    return $properties;
  }

}
