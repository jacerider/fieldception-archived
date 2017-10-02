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
    foreach ($value as $i => $v) {
      if ($v instanceof FieldItemListInterface) {
        $v = $v->first()->getValue();
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
    return $key;
  }

  /**
   * Get subfield definition.
   *
   * @param array $config
   *   An array of configuration options with the following keys:
   *   - type: The field type id.
   *   - label: The label of the field.
   *   - settings: An array of storage settings.
   * @param string $subfield
   *   The subfield name.
   * @param string $field_name
   *   The parent entity field name.
   * @param array $settings
   *   A settings array.
   *
   * @return Drupal\Core\Field\FieldDefinitionInterface
   *   A subfield definition.
   */
  public function getSubfieldDefinition(FieldStorageDefinitionInterface $definition, array $config, $subfield, array $settings = []) {
    $key = $this->toKey([
      $definition,
      $config,
      $subfield,
      $settings,
    ]);
    if (!isset($this->subfieldDefinitions[$key])) {
      $type = $config['type'];
      // Check if we're dealing with a preconfigured field.
      if (strpos($type, 'field_ui:') !== FALSE) {
        // @see \Drupal\field_ui\Form\FieldStorageAddForm::submitForm
        list(, $type, $option_key) = explode(':', $type, 3);

        $field_type_class = $this->fieldTypePluginManager->getDefinition($type)['class'];
        $field_options = $field_type_class::getPreconfiguredOptions()[$option_key];

        // Merge in preconfigured field storage options.
        if (isset($field_options['field_storage_config'])) {
          foreach (['settings'] as $key) {
            if (isset($field_options['field_storage_config'][$key])) {
              $config[$key] = $field_options['field_storage_config'][$key];
            }
          }
        }
      }
      $this->subfieldDefinitions[$key] = FieldceptionFieldDefinition::createFromParentFieldStorageDefinition($type, $definition)
        ->setKey($key)
        ->setSubfield($subfield)
        ->setSettings($settings);
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
      if ($subfield_item_list) {
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
   * @param array $settings
   *   A settings array.
   *
   * @return \Drupal\Core\Field\WidgetInterface
   *   The widget plugin.
   */
  public function getSubfieldWidget(FieldDefinitionInterface $subfield_definition, array $settings = []) {
    $key = $this->toKey([
      $subfield_definition,
      $settings,
    ]);
    if (!isset($this->subfieldWidgets[$key])) {
      $field_type_definition = $this->fieldTypePluginManager->getDefinition($subfield_definition->getType());
      $this->subfieldWidgets[$key] = $this->fieldWidgetPluginManager->createInstance($field_type_definition['default_widget'], [
        'field_definition' => $subfield_definition,
        'settings' => $settings,
        'third_party_settings' => [],
      ]);
    }
    return $this->subfieldWidgets[$key];
  }

  /**
   * Get subfield formatter.
   *
   * @param Drupal\Core\Field\FieldDefinitionInterface $subfield_definition
   *   The subfield definition.
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
  public function getSubfieldFormatter(FieldDefinitionInterface $subfield_definition, array $settings = [], $view_mode = 'default', $label = '') {
    $key = $this->toKey([
      $subfield_definition,
      $settings,
      $view_mode,
    ]);
    if (!isset($this->subfieldFormatters[$key])) {
      $field_type_definition = $this->fieldTypePluginManager->getDefinition($subfield_definition->getType());
      $this->subfieldFormatters[$key] = $this->fieldFormatterPluginManager->createInstance($field_type_definition['default_formatter'], [
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
   * Get subfield item list.
   *
   * @param Drupal\Core\Field\FieldDefinitionInterface $subfield_definition
   *   The subfield definition.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The parent entity.
   * @param int $delta
   *   The parent entity value delta.
   *
   * @return \Drupal\Core\Field\FieldItemListInterface
   *   The field item list.
   */
  public function getSubfieldItemList(FieldDefinitionInterface $subfield_definition, ContentEntityInterface $entity, $delta = 0) {
    $key = $this->toKey([
      $subfield_definition,
      $entity,
      $delta,
    ]);
    if (!isset($this->subfieldItemLists[$key])) {
      $field_name = $subfield_definition->getName();
      $subfield = $subfield_definition->getSubfield();
      $subfield_storage = $this->getSubfieldStorage($subfield_definition);

      // Convert values to subvalues.
      $value = $this->convertValueToSubfieldValue($subfield_definition, $entity->get($field_name)->get($delta)->toArray());

      $subfield_list_class = $this->fieldTypePluginManager->getDefinition($subfield_definition->getType())['list_class'];
      $field_item_list = $subfield_list_class::createInstance($subfield_definition->getFieldStorageDefinition(), $subfield, $entity->getTypedData());

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
   * Get a cloned FormState ready for sub plugins.
   *
   * @param array $config
   *   An array of configuration options with the following keys:
   *   - type: The field type id.
   *   - label: The label of the field.
   *   - settings: An array of storage settings.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the (entire) configuration form.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   A form state ready for use in sub plugins.
   */
  public function getSubfieldFormState(array $config, FormStateInterface $form_state) {
    $subform_state = clone $form_state;
    $field = clone $subform_state->getFormObject()->getEntity();
    $field->setSettings($config['settings']);
    $field_storage_definition = $field->getFieldStorageDefinition();
    $field_storage_definition->setSettings($config['settings']);
    $subform_state->getFormObject()->setEntity($field);
    return $subform_state;
  }

  /**
   * Get the field type manager plugin.
   *
   * @return \Drupal\Core\Field\FieldTypePluginManagerInterface
   *   The field type manager plugin.
   */
  public function getfieldTypePluginManager() {
    return $this->fieldTypePluginManager;
  }

}
