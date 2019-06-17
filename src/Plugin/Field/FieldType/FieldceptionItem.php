<?php

namespace Drupal\fieldception\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\Render\Element;
use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\field_ui\FieldUI;

/**
 * Plugin implementation of the 'fieldception' field type.
 *
 * @FieldType(
 *   id = "fieldception",
 *   label = @Translation("Fieldception"),
 *   description = @Translation("Fields within a field."),
 *   default_widget = "fieldception",
 *   default_formatter = "fieldception_unformatted_list",
 *   list_class = "\Drupal\fieldception\Plugin\Field\FieldceptionItemList",
 * )
 */
class FieldceptionItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultStorageSettings() {
    return parent::defaultStorageSettings() + [
      'storage' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings() {
    return [
      'fields' => [],
    ] + parent::defaultFieldSettings();
  }

  /**
   * Default subfield settings.
   */
  public static function defaultSubfieldSettings() {
    return [
      'required' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getSettings() {
    $fieldception_helper = \Drupal::service('fieldception.helper');
    $settings = parent::getSettings();
    $field_definition = $this->getFieldDefinition()->getFieldStorageDefinition();
    $settings['fields'] = array_intersect_key($settings['fields'], $settings['storage']);
    foreach ($settings['storage'] as $subfield => $config) {
      $subfield_definition = $fieldception_helper->getSubfieldStorageDefinition($field_definition, $config, $subfield);
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
        self::defaultSubfieldSettings(),
        $settings['fields'][$subfield]
      );
      // Merge into storage settings.
      $settings['storage'][$subfield]['settings'] = $settings['fields'][$subfield]['settings'] + $settings['storage'][$subfield]['settings'];
    }
    $settings['poo'] = 'asdf';
    return $settings;
  }

  /**
   * {@inheritdoc}
   */
  public function storageSettingsForm(array &$form, FormStateInterface $form_state, $has_data) {
    $fieldception_helper = \Drupal::service('fieldception.helper');
    $settings = $this->getSettings();
    $entity = $this->getEntity();
    $field_definition = $this->getFieldDefinition()->getFieldStorageDefinition();
    $class_name = get_class($this);

    // Gather valid field types.
    $field_types = [];
    $field_type_options = [];
    foreach ($fieldception_helper->getFieldTypePluginManager()->getGroupedDefinitions($fieldception_helper->getFieldTypePluginManager()->getUiDefinitions()) as $category => $types) {
      foreach ($types as $name => $field_type) {
        $field_types[$name] = $category . ': ' . $field_type['label'];
        $field_type_options[$category][$name] = $field_type['label'];
      }
    }

    $id = 'fieldception-wrapper';
    $form['#id'] = $id;
    $form_state->set('fieldception_has_data', $has_data);

    $element = [];
    $storage = $form_state->get('fieldception_storage', NULL);
    if (is_null($storage)) {
      $storage = $settings['storage'];
      $form_state->set('fieldception_storage', $storage);
    }
    $op = $form_state->get('fieldception_op');
    if (empty($op)) {
      $op = empty($storage) ? 'add' : 'fields';
    }
    if (empty($storage)) {
      $op = 'add';
    }
    $form_state->set('fieldception_op', $op);

    $element['storage'] = [
      '#type' => 'value',
      '#value' => $settings['storage'],
    ];

    $form['_storage'] = [
      '#type' => 'table',
      '#title' => $this->t('Fields'),
      '#header' => [
        $this->t('Label'),
        $this->t('ID'),
        $this->t('Type'),
        $this->t('Operations'),
        $this->t('Weight'),
      ],
      '#prefix' => '<div id="fieldception-fields">',
      '#suffix' => '</div>',
      '#tree' => TRUE,
      '#element_validate' => [[$class_name, 'validateStorage']],
      '#access' => !empty($storage) && $op == 'fields',
      // TableDrag: Each array value is a list of callback arguments for
      // drupal_add_tabledrag(). The #id of the table is automatically
      // prepended; if there is none, an HTML ID is auto-generated.
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'table-sort-weight',
        ],
      ],
    ];
    $delta = 0;
    foreach ($storage as $subfield => $config) {
      $field_id = 'fieldception-fields-' . $subfield;
      $form['_storage'][$subfield] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Field %label', ['%label' => $config['label']]),
        '#id' => $field_id,
      ];

      // TableDrag: Mark the table row as draggable.
      $form['_storage'][$subfield]['#attributes']['class'][] = 'draggable';

      // TableDrag: Sort the table row according to its existing/configured
      // weight.
      $form['_storage'][$subfield]['#weight'] = $delta;

      $form['_storage'][$subfield]['label'] = [
        '#type' => 'item',
        '#value' => $config['label'],
        '#markup' => $config['label'],
      ];
      $form['_storage'][$subfield]['id'] = [
        '#type' => 'item',
        '#value' => $subfield,
        '#markup' => $subfield,
      ];
      $form['_storage'][$subfield]['type'] = [
        '#type' => 'item',
        '#value' => $config['type'],
        '#markup' => $field_types[$config['type']],
      ];
      $form['_storage'][$subfield]['actions'] = [
        '#type' => 'actions',
      ];
      $form['_storage'][$subfield]['actions']['edit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Settings'),
        '#name' => $subfield . '_edit',
        '#submit' => [[$class_name, 'opSwitchEditSubmit']],
        '#subfield' => $subfield,
        '#limit_validation_errors' => [['_storage', $subfield]],
        '#ajax' => [
          'callback' => [$class_name, 'refreshAjax'],
          'wrapper' => $id,
        ],
      ];
      $form['_storage'][$subfield]['actions']['remove'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove'),
        '#name' => $subfield . '_remove',
        '#submit' => [[$class_name, 'removeFieldSubmit']],
        '#subfield' => $subfield,
        '#limit_validation_errors' => [['_storage', $subfield]],
        '#ajax' => [
          'callback' => [$class_name, 'refreshAjax'],
          'wrapper' => $id,
        ],
      ];
      $form['_storage'][$subfield]['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Weight for @title', [
          '@title' => $config['label'],
        ]),
        '#title_display' => 'invisible',
        '#default_value' => $delta,
        '#attributes' => [
          'class' => [
            'table-sort-weight',
          ],
        ],
      ];
      $delta++;
    }

    $form['_edit'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Field Settings'),
      '#access' => $op == 'edit',
      '#tree' => TRUE,
    ];
    if ($op == 'edit' && ($subfield = $form_state->get('fieldception_field'))) {
      $config = $storage[$subfield];
      $subfield_definition = $fieldception_helper->getSubfieldStorageDefinition($field_definition, $config, $subfield);
      $subfield_items = $fieldception_helper->getSubfieldItemList($subfield_definition, $entity);
      $subfield_storage = $fieldception_helper->getSubfieldStorage($subfield_definition, $subfield_items);
      $form['_edit']['label'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Field label'),
        '#default_value' => $config['label'],
        '#required' => TRUE,
      ];
      $form['_edit']['settings'] = [];
      $form['_edit']['settings'] = $subfield_storage->storageSettingsForm($form['_edit']['settings'], $form_state, FALSE);

      if (empty($form['_edit']['settings'])) {
        $form['_edit']['settings']['#markup'] = $this->t('This field has no additional storage settings.');
      }
      $form['_edit']['settings']['#parents'] = ['settings'];
      $form['_edit']['actions'] = [
        '#type' => 'actions',
      ];
      $form['_edit']['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Update'),
        '#submit' => [[$class_name, 'editStorageFieldSubmit']],
        '#ajax' => [
          'callback' => [$class_name, 'refreshAjax'],
          'wrapper' => $id,
        ],
      ];
      if (!empty($storage)) {
        $form['_edit']['actions']['cancel'] = [
          '#type' => 'submit',
          '#value' => $this->t('Cancel'),
          '#submit' => [[$class_name, 'editFieldCancel']],
          '#limit_validation_errors' => [],
          '#ajax' => [
            'callback' => [$class_name, 'refreshAjax'],
            'wrapper' => $id,
          ],
        ];
      }
    }

    $form['_add'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Add Nested Field'),
      '#access' => $op == 'add',
      '#tree' => TRUE,
    ];
    $form['_add']['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
    ];
    $form['_add']['id'] = [
      '#type' => 'machine_name',
      '#machine_name' => [
        'exists' => '\Drupal\fieldception\Plugin\Field\FieldType\FieldceptionItem::exists',
        'source' => ['_add', 'label'],
      ],
    ];
    $form['_add']['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Field type'),
      '#options' => $field_type_options,
    ];
    $form['_add']['actions'] = [
      '#type' => 'actions',
    ];
    $form['_add']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add'),
      '#submit' => [[$class_name, 'addFieldSubmit']],
      '#ajax' => [
        'callback' => [$class_name, 'refreshAjax'],
        'wrapper' => $id,
      ],
    ];
    if (!empty($storage)) {
      $form['_add']['actions']['cancel'] = [
        '#type' => 'submit',
        '#value' => $this->t('Cancel'),
        '#submit' => [[$class_name, 'opSwitchFieldSubmit']],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => [$class_name, 'refreshAjax'],
          'wrapper' => $id,
        ],
      ];
    }

    $form['#process'][] = [$this, 'storageFormAfterBuild'];

    return $element;
  }

  /**
   * After form build.
   */
  public function storageFormAfterBuild($form, FormStateInterface $form_state) {
    $class_name = get_class($this);
    $op = $form_state->get('fieldception_op');
    $form['cardinality_container']['#access'] = $op == 'fields';
    $form['actions']['#access'] = $op == 'fields';
    $form['#prefix'] = '';
    array_unshift($form['actions']['submit']['#submit'], [get_class($this), 'storageBeforeSave']);
    $form['actions']['submit']['#submit'][] = [get_class($this), 'storageAfterSave'];
    $form['actions']['submit']['#weight'] = -1;
    $form['actions']['add'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add another field'),
      '#submit' => [[$class_name, 'opSwitchAddSubmit']],
      '#ajax' => [
        'callback' => [$class_name, 'refreshAjax'],
        'wrapper' => $form['#id'],
      ],
    ];
    return $form;
  }

  /**
   * Form element validation handler for the 'uri' element.
   *
   * Disallows saving inaccessible or untrusted URLs.
   */
  public static function validateStorage($element, FormStateInterface $form_state, $form) {
    $storage = $form_state->get('fieldception_storage');
    if (!empty($storage)) {
      $ordered_storage = [];
      foreach ($form_state->getValue('_storage') as $subfield => $data) {
        $ordered_storage[$subfield] = array_intersect_key($storage[$subfield], array_flip([
          'id',
          'type',
          'label',
          'settings',
        ]));
        $ordered_storage[$subfield] += [
          'settings' => [],
        ];
      }
      $form_state->setValue(['settings', 'storage'], $ordered_storage);
    }
  }

  /**
   * Check if field id is unique.
   */
  public static function exists($value, array $element, FormStateInterface $form_state) {
    $storage = $form_state->get('fieldception_storage');
    return !empty($storage[$value]);
  }

  /**
   * Submit handler for the "add storage" button.
   */
  public static function addFieldSubmit(array $form, FormStateInterface $form_state) {
    $value = $form_state->getValue(['_add']);
    $storage = $form_state->get('fieldception_storage');
    $storage[$value['id']] = [
      'type' => $value['type'],
      'label' => $value['label'],
      'new' => TRUE,
      'settings' => [],
    ];
    $form_state->set('fieldception_storage', $storage);
    self::opSwitchEditSubmit($form, $form_state, $value['id']);
    $user_input = $form_state->getUserInput();
    $user_input['_add'] = [];
    $form_state->setUserINput($user_input);
    $form_state->setRebuild();
  }

  /**
   * Submit handler for the "edit storage" button.
   */
  public static function editStorageFieldSubmit(array $form, FormStateInterface $form_state) {
    $storage = $form_state->get('fieldception_storage');
    $subfield = $form_state->get('fieldception_field');
    $settings = $form_state->getValue(['settings'], []);
    if (!empty($storage[$subfield]['new'])) {
      unset($storage[$subfield]['new']);
      // Check if we're dealing with a preconfigured field.
      $type = $storage[$subfield]['type'];
      if (strpos($type, 'field_ui:') !== FALSE) {
        // @see \Drupal\field_ui\Form\FieldStorageAddForm::submitForm
        list(, $type, $option_key) = explode(':', $type, 3);
      }
      $storage[$subfield]['type'] = $type;
    }
    unset($settings['storage']);
    $storage[$subfield]['label'] = $form_state->getValue(['_edit', 'label']);
    $storage[$subfield]['settings'] = $settings;
    $form_state->set('fieldception_storage', $storage);
    self::opSwitchFieldSubmit($form, $form_state);
    $form_state->setRebuild();
  }

  /**
   * Submit handler for the "edit storage" button.
   */
  public static function editFieldCancel(array $form, FormStateInterface $form_state) {
    $storage = $form_state->get('fieldception_storage');
    $subfield = $form_state->get('fieldception_field');
    if (!empty($storage[$subfield]['new'])) {
      self::removeFieldSubmit($form, $form_state, $subfield);
    }
    else {
      self::opSwitchFieldSubmit($form, $form_state);
    }
    $form_state->setRebuild();
  }

  /**
   * Submit handler for the "remove storage" button.
   */
  public static function removeFieldSubmit(array $form, FormStateInterface $form_state, $subfield = NULL) {
    $button = $form_state->getTriggeringElement();
    $subfield = $subfield ? $subfield : $button['#subfield'];
    $storage = $form_state->get('fieldception_storage');
    unset($storage[$subfield]);
    $form_state->set('fieldception_storage', $storage);
    $form_state->setRebuild();
  }

  /**
   * Submit handler for the "add storage" button.
   */
  public static function opSwitchAddSubmit(array $form, FormStateInterface $form_state) {
    $form_state->set('fieldception_op', 'add');
    $form_state->setRebuild(TRUE);
  }

  /**
   * Submit handler for the "add storage" button.
   */
  public static function opSwitchEditSubmit(array $form, FormStateInterface $form_state, $subfield = NULL) {
    $button = $form_state->getTriggeringElement();
    $form_state->set('fieldception_op', 'edit');
    $subfield = $subfield ? $subfield : $button['#subfield'];
    $form_state->set('fieldception_field', $subfield);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Submit handler for the "add storage" button.
   */
  public static function opSwitchFieldSubmit(array $form, FormStateInterface $form_state) {
    $form_state->set('fieldception_op', 'fields');
    $form_state->setRebuild(TRUE);
  }

  /**
   * Callback for both ajax-enabled buttons.
   */
  public static function refreshAjax(array $form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * Before save callback.
   */
  public static function storageBeforeSave($form, FormStateInterface $form_state) {
    if ($form_state->get('fieldception_has_data', FALSE)) {
      // If field has data, we need to get all data in the table and whipe the
      // data so that Drupal will let us make the change. We will restore this
      // data in ::storageAfterSave().
      $entity = $form_state->getFormObject()->getEntity();
      $database = \Drupal::database();
      $tables = [
        $entity->getTargetEntityTypeId() . '__' . $entity->getName() => [],
      ];
      if ($entity->isRevisionable() && $database->schema()->tableExists($entity->getTargetEntityTypeId() . '_revision__' . $entity->getName())) {
        $tables[$entity->getTargetEntityTypeId() . '_revision__' . $entity->getName()] = [];
      }
      foreach ($tables as $table => $values) {
        $tables[$table] = $database->select($table, 't')
          ->fields('t', [])
          ->execute()
          ->fetchAll(\PDO::FETCH_ASSOC);
        $database->truncate($table)->execute();
      }
      $form_state->set('fieldception_tables', $tables);
    }
  }

  /**
   * After save callback.
   */
  public static function storageAfterSave($form, FormStateInterface $form_state) {
    if ($form_state->get('fieldception_has_data', FALSE)) {
      // Restore data.
      $entity = $form_state->getFormObject()->getEntity();
      $database = \Drupal::database();
      $tables = $form_state->get('fieldception_tables');
      $columns = [
        'bundle',
        'deleted',
        'entity_id',
        'revision_id',
        'langcode',
        'delta',
      ];
      foreach ($entity->getSchema()['columns'] as $key => $value) {
        $name = $entity->getName() . '_' . $key;
        $columns[] = $name;
        foreach ($tables as $table => $values) {
          foreach ($values as $key => $row) {
            if (!isset($tables[$table][$key][$name])) {
              $tables[$table][$key][$name] = NULL;
            }
          }
        }
      }
      // Put the values back in the table.
      foreach ($tables as $table => $values) {
        $query = $database->insert($table)->fields($columns);
        foreach ($values as $row) {
          $query->values($row);
        }
        $query->execute();
      }
    }

    $entity = $form_state->getFormObject()->getEntity();
    $entity_type = \Drupal::entityManager()->getDefinition($form_state->get('entity_type_id'));
    $bundle = $form_state->get('bundle');
    $route_name = str_replace($entity_type->id() . '.', $entity_type->id() . '.' . $bundle . '.', $entity->id());
    $route_parameters = [
      'field_config' => $route_name,
    ] + FieldUI::getRouteBundleParameter($entity_type, $bundle);
    $form_state->setRedirect("entity.field_config.{$entity_type->id()}_field_edit_form", $route_parameters);

  }

  /**
   * {@inheritdoc}
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state) {
    $fieldception_helper = \Drupal::service('fieldception.helper');
    $settings = $this->getSettings();
    $entity = $this->getEntity();
    $field_definition = $this->getFieldDefinition()->getFieldStorageDefinition();
    $class_name = get_class($this);
    $id = 'fieldception-wrapper';
    $form_state->set('fieldception_entity', $entity);

    $storage = $form_state->get('fieldception_storage', NULL);
    if (is_null($storage)) {
      $storage = $settings['storage'];
      $form_state->set('fieldception_storage', $storage);
    }

    $fields = $form_state->get('fieldception_fields', NULL);
    if (is_null($fields)) {
      $fields = $settings['fields'];
      $form_state->set('fieldception_fields', $fields);
    }

    $op = $form_state->get('fieldception_op') ?: 'fields';
    $form_state->set('fieldception_op', $op);

    // Gather valid field types.
    $field_types = [];
    foreach ($fieldception_helper->getFieldTypePluginManager()->getGroupedDefinitions($fieldception_helper->getFieldTypePluginManager()->getUiDefinitions()) as $category => $types) {
      foreach ($types as $name => $field_type) {
        $field_types[$name] = $category . ': ' . $field_type['label'];
      }
    }

    $element = [];

    $element['fields'] = [
      '#type' => 'table',
      '#title' => $this->t('Fields'),
      '#header' => [
        $this->t('Label'),
        $this->t('ID'),
        $this->t('Type'),
        $this->t('Required'),
        $this->t('Operations'),
      ],
      '#prefix' => '<div id="fieldception-fields">',
      '#suffix' => '</div>',
      '#tree' => TRUE,
      '#access' => $op == 'fields',
      '#element_validate' => [[$class_name, 'validateFields']],
    ];

    foreach ($storage as $subfield => $config) {
      $config = NestedArray::mergeDeep($storage[$subfield], $fields[$subfield]);
      $element['fields'][$subfield]['label'] = [
        '#type' => 'item',
        '#value' => $config['label'],
        '#markup' => $config['label'],
      ];
      $element['fields'][$subfield]['id'] = [
        '#type' => 'item',
        '#value' => $subfield,
        '#markup' => $subfield,
      ];
      $element['fields'][$subfield]['type'] = [
        '#type' => 'item',
        '#value' => $config['type'],
        '#markup' => $field_types[$config['type']],
      ];
      $element['fields'][$subfield]['required'] = [
        '#type' => 'item',
        '#value' => $config['required'],
        '#markup' => $config['required'] ? $this->t('Yes') : $this->t('No'),
      ];
      $element['fields'][$subfield]['actions'] = [
        '#type' => 'actions',
      ];
      $element['fields'][$subfield]['actions']['edit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Instance Settings'),
        '#name' => $subfield . '_edit',
        '#submit' => [[$class_name, 'opSwitchEditSubmit']],
        '#subfield' => $subfield,
        '#limit_validation_errors' => [['settings', 'fields', $subfield]],
        '#ajax' => [
          'callback' => [$class_name, 'refreshAjax'],
          'wrapper' => $id,
        ],
      ];
    }

    $element['_edit'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Field Settings'),
      '#access' => $op == 'edit',
      '#tree' => TRUE,
    ];
    if ($op == 'edit' && ($subfield = $form_state->get('fieldception_field'))) {
      // Merge any active field changes.
      $config = NestedArray::mergeDeep($storage[$subfield], $fields[$subfield], $form_state->getValue([
        'settings',
        '_edit',
      ], []));
      $subfield_definition = $fieldception_helper->getSubfieldStorageDefinition($field_definition, $config, $subfield);
      $subfield_items = $fieldception_helper->getSubfieldItemList($subfield_definition, $entity);
      $subfield_storage = $fieldception_helper->getSubfieldStorage($subfield_definition, $subfield_items);
      $subfield_form_state = $fieldception_helper->getSubfieldFormState($subfield_definition, $form_state);

      $element['_edit']['required'] = [
        '#type' => 'checkbox',
        '#title' => t('Required'),
        '#default_value' => $config['required'],
      ];

      $element['_edit']['settings'] = [];
      $element['_edit']['settings'] = $subfield_storage->fieldSettingsForm($element['_edit']['settings'], $subfield_form_state);

      $element['_edit']['actions'] = [
        '#type' => 'actions',
      ];
      $element['_edit']['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Update'),
        '#submit' => [[$class_name, 'editFieldSubmit']],
        '#limit_validation_errors' => [
          ['settings', '_edit'],
          ['third_party_settings'],
        ],
        '#ajax' => [
          'callback' => [$class_name, 'refreshAjax'],
          'wrapper' => $id,
        ],
      ];
      $element['_edit']['actions']['cancel'] = [
        '#type' => 'submit',
        '#value' => $this->t('Cancel'),
        '#submit' => [[$class_name, 'opSwitchFieldSubmit']],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => [$class_name, 'refreshAjax'],
          'wrapper' => $id,
        ],
      ];
    }

    return $element;
  }

  /**
   * After form build.
   */
  public static function fieldFormAfterBuild($form, FormStateInterface $form_state) {
    $fieldception_helper = \Drupal::service('fieldception.helper');
    $storage = $form_state->get('fieldception_storage');
    $fields = $form_state->get('fieldception_fields');
    $entity = $form_state->getFormObject()->getEntity();
    $form_object = $form_state->getFormObject();
    $field_definition = $form_object->getEntity()->getFieldStorageDefinition();
    $op = $form_state->get('fieldception_op');

    if ($op == 'edit' && ($subfield = $form_state->get('fieldception_field'))) {
      $config = NestedArray::mergeDeep($storage[$subfield], $fields[$subfield]);
      $subfield_definition = $fieldception_helper->getSubfieldStorageDefinition($field_definition, $config, $subfield);
      $subfield_entity = $fieldception_helper->getSubfieldConfig($subfield_definition, $form_object->getEntity());
      $subfield_entity->set('third_party_settings', $entity->get('third_party_settings'));
      $form_object = clone $form_state->getFormObject();
      $form_object->setEntity($subfield_entity);
      $temp_form_state = clone $form_state;
      $temp_form_state->setFormObject($form_object);
      $form_id = 'form_field_config_edit_form';
      $temp_form = $form;
      $temp_form['settings'] = $form['settings']['_edit']['settings'];
      \Drupal::moduleHandler()->alter(['form', $form_id], $temp_form, $temp_form_state, $form_id);
      $form['settings']['_edit']['settings'] = $temp_form['settings'];
      foreach ($temp_form['actions']['submit']['#submit'] as $key => $value) {
        if ($key > count($form['actions']['submit']['#submit'])) {
          $form['actions']['submit']['#submit'][] = $value;
        }
      }
      $temp_form = array_diff_key($temp_form, $form);
      unset($temp_form['form_label'], $temp_form['display_label'], $temp_form['actions'], $temp_form['form_label']);
      if (!empty($temp_form)) {
        $temp_form['#parents'] = [];
        $form += $temp_form;
      }
    }

    if ($op != 'fields') {
      foreach (Element::children($form) as $key) {
        if (!in_array($key, [
          'form_id',
          'form_build_id',
          'form_token',
          'settings',
          'default_value',
        ])) {
          $form[$key]['#access'] = FALSE;
        }
      }
      $form['default_value']['#attributes']['class'][] = 'hidden';
    }
    $form['settings']['#weight'] = -5;
    return $form;
  }

  /**
   * Submit handler for the "edit form" button.
   */
  public static function editFieldSubmit(array $form, FormStateInterface $form_state) {
    $entity = $form_state->getFormObject()->getEntity();
    $fields = $form_state->get('fieldception_fields');
    $subfield = $form_state->get('fieldception_field');
    $settings = $form_state->getValue(['settings', '_edit'], []);
    $fields[$subfield] = array_intersect_key($settings, $fields[$subfield]);
    if (!empty($settings['settings'])) {
      $fields[$subfield]['settings'] = array_intersect_key($settings['settings'], $fields[$subfield]['settings']);
    }
    $form_state->set('fieldception_fields', $fields);
    foreach ($form_state->getValue('third_party_settings', []) as $module => $values) {
      foreach ($values as $key => $value) {
        $entity->setThirdPartySetting($module, $key, $value);
      }
    }
    self::opSwitchFieldSubmit($form, $form_state);
    $form_state->setRebuild();
  }

  /**
   * Make sure each field has a settings array value.
   */
  public static function validateFields($element, FormStateInterface $form_state, $form) {
    $fields = $form_state->get('fieldception_fields');
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
      $subfield_definition = $fieldception_helper->getSubfieldStorageDefinition($field_definition, $config, $subfield);
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

      if (!empty($field_constraints)) {
        $constraints[] = $constraint_manager->create('Fieldception', [
          'subfieldItems' => $subfield_items,
          'subfieldConstraints' => $field_constraints,
        ]);
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
      $subfield_definition = $fieldception_helper->getSubfieldStorageDefinition($field_definition, $config, $subfield);
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
  public function getSubfieldValue($subfield) {
    $subfield_values = [];
    foreach ($this->getValue() as $key => $value) {
      if (substr($key, 0, strlen($subfield . '_')) == $subfield . '_') {
        $subfield_values[substr($key, strlen($subfield . '_'))] = $value;
      }
    }
    return $subfield_values;
  }

  /**
   * {@inheritdoc}
   */
  public function referencedEntities() {
    $fieldception_helper = \Drupal::service('fieldception.helper');
    $settings = $this->getSettings();
    $entity = $this->getEntity();
    $field_definition = $this->getFieldDefinition()->getFieldStorageDefinition();

    $entities = [];
    foreach ($settings['storage'] as $subfield => $config) {
      $subfield_definition = $fieldception_helper->getSubfieldStorageDefinition($field_definition, $config, $subfield);
      $subfield_items = $fieldception_helper->getSubfieldItemList($subfield_definition, $entity, 0, $this->getValue());
      $subfield_storage = $fieldception_helper->getSubfieldStorage($subfield_definition, $subfield_items);
      if ($subfield_items instanceof EntityReferenceFieldItemList) {
        $entities += $subfield_items->referencedEntities();
      }
    }

    return $entities;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $fieldception_helper = \Drupal::service('fieldception.helper');
    $settings = $field_definition->getSettings();
    $columns = [];
    foreach ($settings['storage'] as $subfield => $config) {
      $subfield_definition = $fieldception_helper->getSubfieldStorageDefinition($field_definition, $config, $subfield);
      $subfield_storage = $fieldception_helper->getSubfieldStorage($subfield_definition);
      $schema = $subfield_storage::schema($subfield_definition);
      if (isset($schema['columns'])) {
        foreach ($schema['columns'] as $column_name => $column) {
          $column['allow null'] = TRUE;
          $columns[$subfield . '_' . $column_name] = $column;
        }
      }
    }
    if (empty($columns)) {
      $columns = [
        'value' => [
          'type' => 'int',
          'size' => 'tiny',
        ],
      ];
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
      $subfield_definition = $fieldception_helper->getSubfieldStorageDefinition($field_definition, $config, $subfield);
      $subfield_storage = $fieldception_helper->getSubfieldStorage($subfield_definition);
      foreach ($subfield_storage::propertyDefinitions($subfield_definition) as $property_name => $property) {
        $property->setRequired(FALSE);
        $properties[$subfield . '_' . $property_name] = $property;
      }
    }
    if (empty($properties)) {
      $properties['value'] = DataDefinition::create('boolean')
        ->setLabel(t('Temporary value'))
        ->setRequired(TRUE);
    }
    return $properties;
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
      $field_storage_definition = $fieldception_helper->getFieldTypePluginManager()->getDefinition($type);
      $dependencies['module'][] = $field_storage_definition['provider'];
    }
    return $dependencies;
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($values, $notify = TRUE) {
    $fieldception_helper = \Drupal::service('fieldception.helper');
    $settings = $this->getSettings();
    $field_definition = $this->getFieldDefinition()->getFieldStorageDefinition();
    if (is_array($values)) {
      foreach ($settings['storage'] as $subfield => $config) {
        $subfield_definition = $fieldception_helper->getSubfieldStorageDefinition($field_definition, $config, $subfield);
        $subfield_storage = $fieldception_helper->getSubfieldStorage($subfield_definition);
        $subfield_values = $fieldception_helper->convertValueToSubfieldValue($subfield_definition, $values);
        $subfield_storage->setValue($subfield_values);
      }
    }
    parent::setValue($values, $notify);
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
