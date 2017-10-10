<?php

namespace Drupal\fieldception;

use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\fieldception\Plugin\Field\FieldceptionFieldDefinition;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * Class FieldceptionHelper.
 */
class FieldceptionHelper {

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
   * Static cache of storage.
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
  public function __construct(FieldTypePluginManagerInterface $field_type_plugin_manager, WidgetPluginManager $field_widget_plugin_manager, DefaultPluginManager $field_formatter_plugin_manager) {
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
        if (!$v->isEmpty()) {
          $v = $v->first()->getValue();
        }
        else {
          $v = 'e';
        }
      }
      if (is_array($v)) {
        $v = $this->toKey($v);
      }
      if ($v instanceof FieldceptionFieldDefinition) {
        $v = $v->getKey();
      }
      if ($v instanceof FieldStorageDefinitionInterface) {
        $v = $v->getName();
      }
      if ($v instanceof ContentEntityInterface) {
        $i = 'entity';
        $v = $v->id();
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
   * Prepare config.$_COOKIE
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
   * @return Drupal\Core\Field\FieldDefinitionInterface
   *   A subfield definition.
   */
  public function getSubfieldDefinition(FieldStorageDefinitionInterface $definition, array $config, $subfield) {
    $this->prepareConfig($config);
    $key = $this->toKey([
      $definition,
      $config,
      $subfield,
    ]);
    if (!isset($this->subfieldDefinitions[$key])) {
      $this->subfieldDefinitions[$key] = FieldceptionFieldDefinition::createFromParentFieldStorageDefinition($definition, $config, $subfield)
        ->setKey($key);
    }
    return $this->subfieldDefinitions[$key];
  }

  /**
   * Get subfield storage.
   *
   * @param Drupal\Core\Field\FieldDefinitionInterface $subfield_definition
   *   The subfield definition.
   * @param \Drupal\Core\Field\FieldItemListInterface|null $subfield_item_list
   *   The field item list.
   *
   * @return \Drupal\Core\Field\WidgetInterface
   *   The storage plugin.
   */
  public function getSubfieldStorage(FieldDefinitionInterface $subfield_definition, $subfield_item_list = NULL) {
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

      if ($subfield_item_list && !$subfield_item_list->isEmpty()) {
        $storage->setValue($subfield_item_list->first()->getValue());
      }
      $this->subfieldStorage[$key] = $storage;
    }
    return $this->subfieldStorage[$key];
  }

  /**
   * Get subfield widget.
   *
   * @param Drupal\Core\Field\FieldDefinitionInterface $subfield_definition
   *   The subfield definition.
   * @param string $widget_type
   *   The widget type.
   * @param array $settings
   *   A settings array.
   *
   * @return \Drupal\Core\Field\WidgetInterface
   *   The widget plugin.
   */
  public function getSubfieldWidget(FieldDefinitionInterface $subfield_definition, $widget_type, array $settings = []) {
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
      $field_type_definition = $this->fieldTypePluginManager->getDefinition($subfield_definition->getType());
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
   * Get subfield item list.
   *
   * @param Drupal\Core\Field\FieldDefinitionInterface $subfield_definition
   *   The subfield definition.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The parent entity.
   * @param int $delta
   *   The parent entity value delta.
   * @param array $values
   *   An array of values to set to the item list. If none is supplied the
   *   entity value will be used.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface
   *   The field item list.
   */
  public function getSubfieldItemList(FieldDefinitionInterface $subfield_definition, ContentEntityInterface $entity, $delta = 0, array $values = NULL) {
    $key = $this->toKey([
      $subfield_definition,
      $entity,
      $delta,
      $values,
    ]);
    if (!isset($this->subfieldItemLists[$key])) {
      $field_name = $subfield_definition->getParentfield();
      $subfield = $subfield_definition->getSubfield();

      $subfield_list_class = $this->fieldTypePluginManager->getDefinition($subfield_definition->getType())['list_class'];
      $field_item_list = $subfield_list_class::createInstance($subfield_definition->getFieldStorageDefinition(), $subfield_definition->getName(), $entity->getTypedData());

      // Convert values to subvalues.
      $values = is_array($values) ? $values : $entity->get($field_name)->get($delta)->toArray();
      $value = $this->convertValueToSubfieldValue($subfield_definition, $values);
      $value = !empty($value) ? $value : '';
      $field_item_list->setValue($value);
      $this->subfieldItemLists[$key] = $field_item_list;
    }

    return $this->subfieldItemLists[$key];
  }

  /**
   * Convert parent value to subfield value.
   *
   * @param Drupal\Core\Field\FieldDefinitionInterface $subfield_definition
   *   The subfield definition.
   * @param array $value
   *   The array containing the delta value.
   *
   * @return array
   *   An array containing the subfield value.
   */
  public function convertValueToSubfieldValue(FieldDefinitionInterface $subfield_definition, array $value) {
    $subfield = $subfield_definition->getSubfield();
    $subfield_storage = $this->getSubfieldStorage($subfield_definition);
    $subfield_value = [];
    $schema = $subfield_storage::schema($subfield_definition);
    foreach ($schema['columns'] as $column_name => $column) {
      $parent_column_name = $subfield . '_' . $column_name;
      if (isset($value[$parent_column_name])) {
        $subfield_value[$column_name] = $value[$parent_column_name];
      }
    }
    return $subfield_value;
  }

  /**
   * Convert subfield value to parent value.
   *
   * @param Drupal\Core\Field\FieldDefinitionInterface $subfield_definition
   *   The subfield definition.
   * @param array $subfield_value
   *   The array containing the delta value.
   *
   * @return array
   *   An array containing the parent value.
   */
  public function convertSubfieldValueToValue(FieldDefinitionInterface $subfield_definition, array $subfield_value) {
    $subfield = $subfield_definition->getSubfield();
    $subfield_storage = $this->getSubfieldStorage($subfield_definition);
    $value = [];
    $schema = $subfield_storage::schema($subfield_definition);
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
   * @param Drupal\Core\Field\FieldDefinitionInterface $subfield_definition
   *   The subfield definition.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the (entire) configuration form.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   A form state ready for use in sub plugins.
   */
  public function getSubfieldFormState(FieldDefinitionInterface $subfield_definition, FormStateInterface $form_state) {
    $settings = $subfield_definition->getSettings();
    $subform_state = clone $form_state;

    $parent_field = $subform_state->getFormObject()->getEntity();
    $subfield_storage = clone $parent_field->getFieldStorageDefinition();
    $subfield_storage->setSettings($settings);
    $field_array = $parent_field->toArray();
    $field_array['field_storage'] = $subfield_storage;
    $field_array['settings'] = $settings;

    // Create a new temporary field config entity with our modified storage.
    $field = \Drupal::entityTypeManager()->getStorage($parent_field->getEntityTypeId())->create($field_array);

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
