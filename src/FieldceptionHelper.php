<?php

namespace Drupal\fieldception;

use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\fieldception\Plugin\Field\FieldceptionFieldStorageDefinition;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\fieldception\Plugin\Field\FieldceptionFieldDefinition;

/**
 * Class FieldceptionHelper.
 */
class FieldceptionHelper {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The field type plugin manager.
   *
   * @var \Drupal\Core\Field\FieldTypePluginManagerInterface
   */
  protected $fieldTypePluginManager;

  /**
   * The field widget plugin manager.
   *
   * @var \Drupal\Core\Field\WidgetPluginManager
   */
  protected $fieldWidgetPluginManager;

  /**
   * The field formatter plugin manager.
   *
   * @var \Drupal\Core\Field\FieldDefinitionInterface
   */
  protected $fieldFormatterPluginManager;

  /**
   * Static cache of definitions.
   *
   * @var array
   */
  protected $subfieldDefinitions = [];

  /**
   * Static cache of storage definitions.
   *
   * @var array
   */
  protected $subfieldStorageDefinitions = [];

  /**
   * Static cache of storage configs.
   *
   * @var array
   */
  protected $subfieldStorageConfigs = [];

  /**
   * Static cache of configs.
   *
   * @var array
   */
  protected $subfieldConfigs = [];

  /**
   * Static cache of storages.
   *
   * @var array
   */
  protected $subfieldStorage = [];

  /**
   * Static cache of widgets.
   *
   * @var array
   */
  protected $subfieldWidgets = [];

  /**
   * Static cache of formatters.
   *
   * @var array
   */
  protected $subfieldFormatters = [];

  /**
   * Static cache of item lists.
   *
   * @var array
   */
  protected $subfieldItemLists = [];

  /**
   * Constructs a new FieldceptionHelper object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, FieldTypePluginManagerInterface $field_type_plugin_manager, WidgetPluginManager $field_widget_plugin_manager, DefaultPluginManager $field_formatter_plugin_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->fieldTypePluginManager = $field_type_plugin_manager;
    $this->fieldWidgetPluginManager = $field_widget_plugin_manager;
    $this->fieldFormatterPluginManager = $field_formatter_plugin_manager;
  }

  /**
   * Create a unique key for caching.
   *
   * @param array $value
   *   An array of items to build cache key.
   *
   * @return string
   *   A cache key.
   */
  protected function toKey(array $value) {
    $key = '';
    ksort($value);
    foreach ($value as $i => $v) {
      if ($v instanceof FieldItemListInterface) {
        $v = $v->first();
        if ($v) {
          $v = $v->getValue();
        }
        else {
          $v = 'e';
        }
      }
      if (is_array($v)) {
        $v = hash('md5', json_encode($v));
        // $v = 1;
        // $v = $this->toKey($v);
      }
      if ($v instanceof FieldceptionFieldStorageDefinition) {
        $v = $v->getKey();
      }
      if ($v instanceof FieldConfigInterface) {
        $v = $v->id();
      }
      if ($v instanceof FieldStorageDefinitionInterface) {
        $v = $v->getName();
      }
      if ($v instanceof ContentEntityInterface) {
        $i = 'entity';
        $v = $v->getEntityTypeId() . '--' . $v->id() . '--' . $v->getRevisionId();
      }
      if (empty($v) && $v !== 0) {
        $v = '0';
      }
      $v = $i . ':' . $v;
      $v = empty($key) ? $v : '--' . $v;
      $key .= strtolower(preg_replace('/[^\da-z\-\:]/i', '', $v));
    }
    return md5($key);
  }

  /**
   * Prepare config.$_COOKIE.
   *
   * Supports preconfigured fields.
   *
   * @param array $config
   *   An array of configuration options with the following keys:
   *   - type: The field type id.
   *   - label: The label of the field.
   *   - settings: An array of storage settings.
   */
  protected function prepareConfig(array &$config) {
    $type = $config['type'];
    // Check if we're dealing with a preconfigured field.
    if (strpos($type, 'field_ui:') !== FALSE) {
      // @see \Drupal\field_ui\Form\FieldStorageAddForm::submitForm
      list(, $type, $option_key) = explode(':', $type, 3);
      $config['type'] = $type;

      $field_type_class = $this->fieldTypePluginManager->getDefinition($type)['class'];
      $field_options = $field_type_class::getPreconfiguredOptions()[$option_key];

      // Merge in preconfigured field storage options.
      if (isset($field_options['field_storage_config'])) {
        foreach (['settings'] as $key) {
          if (isset($field_options['field_storage_config'][$key]) && empty($config[$key])) {
            $config[$key] = $field_options['field_storage_config'][$key];
          }
        }
      }
    }
  }

  /**
   * Get subfield definition.
   *
   * @param \Drupal\Core\Field\FieldConfigInterface $definition
   *   The field definition.
   * @param array $config
   *   An array of configuration options with the following keys:
   *   - type: The field type id.
   *   - label: The label of the field.
   *   - settings: An array of storage settings.
   * @param string $subfield
   *   The subfield name.
   *
   * @return \Drupal\Core\Field\FieldConfigInterface
   *   A subfield definition.
   */
  public function getSubfieldDefinition(FieldConfigInterface $definition, array $config, $subfield) {
    $this->prepareConfig($config);
    $key = $this->toKey([
      $definition,
      $config,
      $subfield,
    ]);
    if (!isset($this->subfieldDefinitions[$key])) {
      $this->subfieldDefinitions[$key] = FieldceptionFieldDefinition::createFromParentFieldDefinition($definition, $config, $subfield)
        ->setKey($key);
    }
    return $this->subfieldDefinitions[$key];
  }

  /**
   * Get subfield storage definition.
   *
   * @param \Drupal\Core\Field\FieldStorageDefinitionInterface $definition
   *   The field definition.
   * @param array $config
   *   An array of configuration options with the following keys:
   *   - type: The field type id.
   *   - label: The label of the field.
   *   - settings: An array of storage settings.
   * @param string $subfield
   *   The subfield name.
   *
   * @return \Drupal\Core\Field\FieldStorageDefinitionInterface
   *   A subfield definition.
   */
  public function getSubfieldStorageDefinition(FieldStorageDefinitionInterface $definition, array $config, $subfield) {
    $this->prepareConfig($config);
    $key = $this->toKey([
      $definition,
      $config,
      $subfield,
      'storage',
    ]);
    if (!isset($this->subfieldDefinitions[$key])) {
      $this->subfieldStorageDefinitions[$key] = FieldceptionFieldStorageDefinition::createFromParentFieldStorageDefinition($definition, $config, $subfield)
        ->setKey($key);
    }
    return $this->subfieldStorageDefinitions[$key];
  }

  /**
   * Get subfield storage config entity.
   *
   * @param Drupal\Core\Field\FieldDefinitionInterface $subfield_definition
   *   The subfield definition.
   *
   * @return \Drupal\field\FieldStorageConfigInterface
   *   The field storage config entity.
   */
  public function getSubfieldStorageConfig(FieldDefinitionInterface $subfield_definition) {
    $key = $this->toKey([
      $subfield_definition,
    ]);
    if (!isset($this->subfieldStorageConfigs[$key])) {
      $this->subfieldStorageConfigs[$key] = $this->entityTypeManager->getStorage('field_storage_config')->create([
        'field_name' => $subfield_definition->getSubfield(),
        'entity_type' => $subfield_definition->getTargetEntityTypeId(),
        'type' => $subfield_definition->getType(),
        'bundle' => $subfield_definition->getTargetBundle(),
      ]);
    }
    return $this->subfieldStorageConfigs[$key];
  }

  /**
   * Get subfield storage.
   *
   * @param Drupal\Core\Field\FieldStorageDefinitionInterface $subfield_definition
   *   The subfield definition.
   * @param \Drupal\Core\Field\FieldItemListInterface|null $subfield_item_list
   *   The field item list.
   *
   * @return \Drupal\Core\Field\WidgetInterface
   *   The storage plugin.
   */
  public function getSubfieldStorage(FieldStorageDefinitionInterface $subfield_definition, $subfield_item_list = NULL) {
    $key = $this->toKey([
      $subfield_definition,
      $subfield_item_list,
    ]);
    if (!isset($this->subfieldStorage[$key])) {
      $type = $subfield_definition->getType();
      $storage = $this->fieldTypePluginManager->createInstance($type, [
        'field_definition' => $subfield_definition,
        'name' => $subfield_definition->getName(),
        'parent' => $subfield_item_list,
      ]);
      if ($subfield_item_list) {
        $item = $subfield_item_list->first();
        if ($item) {
          $storage->setValue($item->getValue());
        }
      }
      $this->subfieldStorage[$key] = $storage;
    }
    return $this->subfieldStorage[$key];
  }

  /**
   * Get subfield widget.
   *
   * @param Drupal\Core\Field\FieldConfigInterface $subfield_definition
   *   The subfield definition.
   * @param string $widget_type
   *   The widget type.
   * @param array $settings
   *   A settings array.
   *
   * @return \Drupal\Core\Field\WidgetInterface
   *   The widget plugin.
   */
  public function getSubfieldWidget(FieldConfigInterface $subfield_definition, $widget_type, array $settings = []) {
    $key = $this->toKey([
      $subfield_definition,
      $widget_type,
      $settings,
    ]);
    if (!isset($this->subfieldWidgets[$key])) {
      $this->subfieldWidgets[$key] = $this->fieldWidgetPluginManager->createInstance($widget_type, [
        'field_definition' => $subfield_definition,
        'settings' => $settings,
        'third_party_settings' => [],
      ]);
    }
    return $this->subfieldWidgets[$key];
  }

  /**
   * Get default widget for a subfield.
   *
   * @param Drupal\Core\Field\FieldDefinitionInterface $subfield_definition
   *   The subfield definition.
   *
   * @return string
   *   The default widget id.
   */
  public function getSubfieldDefaultWidget(FieldDefinitionInterface $subfield_definition) {
    $field_type_definition = $this->fieldTypePluginManager->getDefinition($subfield_definition->getType());
    return $field_type_definition['default_widget'];
  }

  /**
   * Get subfield formatter.
   *
   * @param Drupal\Core\Field\FieldDefinitionInterface $subfield_definition
   *   The subfield definition.
   * @param string $formatter_type
   *   The formatter type.
   * @param array $settings
   *   A settings array.
   * @param string $view_mode
   *   The formatter view mode.
   * @param string $label
   *   The formatter label.
   *
   * @return \Drupal\Core\Field\WidgetInterface
   *   The widget plugin.
   */
  public function getSubfieldFormatter(FieldDefinitionInterface $subfield_definition, $formatter_type, array $settings = [], $view_mode = 'default', $label = '') {
    $key = $this->toKey([
      $subfield_definition,
      $formatter_type,
      $settings,
      $view_mode,
    ]);
    if (!isset($this->subfieldFormatters[$key])) {
      $this->subfieldFormatters[$key] = $this->fieldFormatterPluginManager->createInstance($formatter_type, [
        'field_definition' => $subfield_definition,
        'settings' => $settings,
        'label' => $label,
        'view_mode' => $view_mode,
        'third_party_settings' => [],
      ]);
    }
    return $this->subfieldFormatters[$key];
  }

  /**
   * Get default formatter for a subfield.
   *
   * @param Drupal\Core\Field\FieldDefinitionInterface $subfield_definition
   *   The subfield definition.
   *
   * @return string
   *   The default formatter id.
   */
  public function getSubfieldDefaultFormatter(FieldDefinitionInterface $subfield_definition) {
    $field_type_definition = $this->fieldTypePluginManager->getDefinition($subfield_definition->getType());
    return $field_type_definition['default_formatter'];
  }

  /**
   * Get subfield config entity.
   *
   * @param Drupal\Core\Field\FieldDefinitionInterface $subfield_definition
   *   The subfield definition.
   * @param Drupal\Core\Field\FieldConfigInterface $field_config
   *   The base field config.
   *
   * @return \Drupal\field\FieldConfigInterface
   *   The field config entity.
   */
  public function getSubfieldConfig(FieldDefinitionInterface $subfield_definition, FieldConfigInterface $field_config) {
    $key = $this->toKey([
      $subfield_definition,
      $field_config,
    ]);
    if (!isset($this->subfieldConfigs[$key])) {
      $this->subfieldConfigs[$key] = $this->entityTypeManager->getStorage('field_config')->create([
        'field_name' => $subfield_definition->getParentfield(),
        'entity_type' => $subfield_definition->getTargetEntityTypeId(),
        'label' => $subfield_definition->getLabel(),
        'bundle' => $field_config->getTargetBundle(),
      ]);
      $this->subfieldConfigs[$key]
        ->set('field_name', $subfield_definition->getName())
        ->setLabel($subfield_definition->getLabel())
        ->setSettings($subfield_definition->getSettings())
        ->set('field_type', $subfield_definition->getType());
      foreach ($field_config->getThirdPartyProviders() as $provider) {
        foreach ($field_config->getThirdPartySettings($provider) as $id => $value) {
          $this->subfieldConfigs[$key]->setThirdPartySetting($provider, $key, $value);
        }
      }
    }
    return $this->subfieldConfigs[$key];
  }

  /**
   * Get subfield item list.
   *
   * @param Drupal\Core\Field\FieldConfigInterface $subfield_definition
   *   The subfield definition.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The parent entity.
   * @param int $delta
   *   The parent entity value delta.
   * @param string|array $values
   *   An array of values to set to the item list. If none is supplied the
   *   entity value will be used. If not an array, it can be used to generate
   *   a new cache entry.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface
   *   The field item list.
   */
  public function getSubfieldItemList(FieldConfigInterface $subfield_definition, ContentEntityInterface $entity, $delta = 0, $values = NULL) {
    $key = $this->toKey([
      $subfield_definition,
      $entity,
      !empty($entity->original),
      $delta,
      $values,
    ]);
    if (!isset($this->subfieldItemLists[$key])) {
      $entity = clone $entity;
      $field_name = $subfield_definition->getParentfield();
      $langcode = $entity->language()->getId();

      // Merge in third party settings.
      $field_config = $entity->get($field_name)->getFieldDefinition();
      foreach ($field_config->getThirdPartyProviders() as $provider) {
        foreach ($field_config->getThirdPartySettings($provider) as $id => $value) {
          $subfield_definition->setThirdPartySetting($provider, $id, $value);
        }
      }

      $subfield_list_class = $this->fieldTypePluginManager->getDefinition($subfield_definition->getType())['list_class'];
      $field_item_list = $subfield_list_class::createInstance($subfield_definition, $subfield_definition->getName(), $entity->getTypedData());
      $field_item_list->setLangcode($langcode);

      // Convert values to subvalues.
      if (!is_array($values)) {
        $field = $entity->get($field_name);
        if (!$field->get($delta)) {
          $values = [];
        }
        else {
          $values = $field->get($delta)->toArray();
        }
      }
      $value = $this->convertValueToSubfieldValue($subfield_definition, $values);
      $value = !empty($value) ? $value : '';
      if ($value == '' && $field_item_list instanceof EntityReferenceFieldItemList) {
        $value = ['target_id' => NULL];
      }
      $field_item_list->setValue($value);
      $entity->{$subfield_definition->getName()} = $field_item_list;

      if (!empty($entity->original)) {
        $entity->original = clone $entity->original;
        $entity->original->{$subfield_definition->getName()} = $this->getSubfieldItemList($subfield_definition, $entity->original, $delta, 'original');
      }

      $this->subfieldItemLists[$key] = $field_item_list;
    }

    return $this->subfieldItemLists[$key];
  }

  /**
   * Convert parent value to subfield value.
   *
   * @param Drupal\Core\Field\FieldConfigInterface $subfield_definition
   *   The subfield definition.
   * @param array $value
   *   The array containing the delta value.
   *
   * @return array
   *   An array containing the subfield value.
   */
  public function convertValueToSubfieldValue(FieldConfigInterface $subfield_definition, array $value) {
    $subfield_value = [];
    if (!empty($value)) {
      $subfield = $subfield_definition->getSubfield();
      $subfield_storage = $this->getSubfieldStorage($subfield_definition->getFieldStorageDefinition());
      $schema = $subfield_storage::schema($subfield_definition->getFieldStorageDefinition());
      foreach ($schema['columns'] as $column_name => $column) {
        $parent_column_name = $subfield . '_' . $column_name;
        if (array_key_exists($parent_column_name, $value)) {
          $subfield_value[$column_name] = $value[$parent_column_name];
        }
      }
    }
    return $subfield_value;
  }

  /**
   * Convert subfield value to parent value.
   *
   * @param Drupal\Core\Field\FieldConfigInterface $subfield_definition
   *   The subfield definition.
   * @param array $subfield_value
   *   The array containing the delta value.
   *
   * @return array
   *   An array containing the parent value.
   */
  public function convertSubfieldValueToValue(FieldConfigInterface $subfield_definition, array $subfield_value) {
    $value = [];
    $subfield = $subfield_definition->getSubfield();
    $subfield_storage = $this->getSubfieldStorage($subfield_definition->getFieldStorageDefinition());
    $schema = $subfield_storage::schema($subfield_definition->getFieldStorageDefinition());
    foreach ($schema['columns'] as $column_name => $column) {
      $parent_column_name = $subfield . '_' . $column_name;
      if (isset($subfield_value[$column_name])) {
        $value[$parent_column_name] = $subfield_value[$column_name];
      }
    }
    return $value;
  }

  /**
   * Get a cloned FormState ready for sub plugins.
   *
   * @param Drupal\Core\Field\FieldConfigInterface $subfield_definition
   *   The subfield definition.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the (entire) configuration form.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   A form state ready for use in sub plugins.
   */
  public function getSubfieldFormState(FieldConfigInterface $subfield_definition, FormStateInterface $form_state) {
    $settings = $subfield_definition->getSettings();
    $subform_state = clone $form_state;

    $field_definition = $subform_state->getFormObject()->getEntity();
    $subfield_storage = clone $field_definition->getFieldStorageDefinition();
    $subfield_storage->setSettings($settings);
    $field_array = $field_definition->toArray();
    $field_array['field_storage'] = $subfield_storage;
    $field_array['settings'] = $settings;

    // Create a new temporary field config entity with our modified storage.
    $field = \Drupal::entityTypeManager()->getStorage($field_definition->getEntityTypeId())->create($field_array);

    // Clone field state and set the subfield storage as the form object.
    $subform_object = clone $subform_state->getFormObject();
    $subform_object->setEntity($field);
    $subform_state->setFormObject($subform_object);

    return $subform_state;
  }

  /**
   * Get the field type manager plugin.
   *
   * @return \Drupal\Core\Field\FieldTypePluginManagerInterface
   *   The field type manager plugin.
   */
  public function getFieldTypePluginManager() {
    return $this->fieldTypePluginManager;
  }

  /**
   * Get the field widget manager plugin.
   *
   * @return \Drupal\Core\Field\WidgetPluginManager
   *   The field widget manager plugin.
   */
  public function getFieldWidgetPluginManager() {
    return $this->fieldWidgetPluginManager;
  }

  /**
   * Get the field formatter manager plugin.
   *
   * @return \\Drupal\Core\Field\FieldDefinitionInterface
   *   The field formatter manager plugin.
   */
  public function getFieldFormatterPluginManager() {
    return $this->fieldFormatterPluginManager;
  }

}
