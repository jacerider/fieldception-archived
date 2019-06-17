<?php

namespace Drupal\fieldception\Plugin\Field;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\TypedData\OptionsProviderInterface;
use Drupal\Core\Config\Entity\ThirdPartySettingsInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldConfigBase;
use Drupal\Core\Field\FieldException;

/**
 * A class for defining entity fields.
 */
class FieldceptionFieldDefinition extends FieldConfigBase implements ThirdPartySettingsInterface {

  /**
   * Third party entity settings.
   *
   * An array of key/value pairs keyed by provider.
   *
   * @var array
   */
  protected $thirdPartySettings = [];

  /**
   * Sets the definition key.
   *
   * This is used for caching.
   *
   * @param string $key
   *   The subfield key to set.
   *
   * @return static
   *   The object itself for chaining.
   */
  public function setKey($key) {
    $this->id = $key;
    $this->uuid = $key;
    return $this;
  }

  /**
   * Gets the subfield key.
   *
   * @return string
   *   The subfield key.
   */
  public function getKey() {
    return $this->uuid;
  }

  /**
   * Gets the subfield name.
   *
   * @return string
   *   The subfield name.
   */
  public function getName() {
    return $this->name;
  }

  /**
   * Sets the subfield name.
   *
   * @param string $name
   *   The subfield name.
   *
   * @return $this
   */
  public function setName($name) {
    $this->name = $name;
    return $this;
  }

  /**
   * Gets the subfield.
   *
   * @return string
   *   The subfield name.
   */
  public function getSubfield() {
    $parts = explode(':', $this->getName());
    return isset($parts[1]) ? $parts[1] : $parts[0];
  }

  /**
   * Gets the parent field.
   *
   * @return string
   *   The parent field name.
   */
  public function getParentfield() {
    $parts = explode(':', $this->getName());
    return $parts[0];
  }

  /**
   * {@inheritdoc}
   */
  public function getType() {
    $field_type_plugin_manager = \Drupal::service('plugin.manager.field.field_type');
    $definitions = $field_type_plugin_manager->getDefinitions();
    $type = $this->getBaseType();
    if (isset($definitions['fieldception_' . $type])) {
      $type = 'fieldception_' . $type;
    }
    return $type;
  }

  /**
   * {@inheritdoc}
   */
  public function getBaseType() {
    return $this->type;
  }

  /**
   * {@inheritdoc}
   */
  public function setThirdPartySetting($module, $key, $value) {
    $this->thirdPartySettings[$module][$key] = $value;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getThirdPartySetting($module, $key, $default = NULL) {
    if (isset($this->thirdPartySettings[$module][$key])) {
      return $this->thirdPartySettings[$module][$key];
    }
    else {
      return $default;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getThirdPartySettings($module) {
    return isset($this->thirdPartySettings[$module]) ? $this->thirdPartySettings[$module] : [];
  }

  /**
   * {@inheritdoc}
   */
  public function unsetThirdPartySetting($module, $key) {
    unset($this->thirdPartySettings[$module][$key]);
    // If the third party is no longer storing any information, completely
    // remove the array holding the settings for this module.
    if (empty($this->thirdPartySettings[$module])) {
      unset($this->thirdPartySettings[$module]);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getThirdPartyProviders() {
    return array_keys($this->thirdPartySettings);
  }

  /**
   * Creates a new field definition.
   *
   * @param array $values
   *   The values of the field definition.
   *
   * @return static
   *   A new field definition object.
   */
  public static function create(array $values = []) {
    if (isset($values['field_storage'])) {
      $field_storage = $values['field_storage'];
      $values['field_name'] = $field_storage->getName();
      $values['field_type'] = $field_storage->getType();
      $values['entity_type'] = $field_storage->getTargetEntityTypeId();
      // The internal property is fieldStorage, not field_storage.
      unset($values['field_storage']);
      $values['fieldStorage'] = $field_storage;
    }
    return new static($values, 'field_config');
  }

  /**
   * Creates a new field definition based upon a field definition.
   *
   * In cases where one needs a field definitions to act like full
   * field definitions, this creates a new field definition based upon the
   * (limited) information available. That way it is possible to use the field
   * definition in places where a full field definition is required; e.g., with
   * widgets or formatters.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field storage definition to base the new field definition upon.
   * @param array $config
   *   An array of configuration options with the following keys:
   *   - type: The field type id.
   *   - label: The label of the field.
   *   - settings: An array of storage settings.
   * @param string $subfield
   *   The subfield name.
   *
   * @return $this
   */
  public static function createFromParentFieldDefinition(FieldDefinitionInterface $definition, array $config, $subfield) {
    $name = $definition->getName() . ':' . $subfield;
    $settings = $definition->getSettings();
    $field_settings = isset($settings['fields'][$subfield]['settings']) ? $settings['fields'][$subfield]['settings'] : [];
    $storage_definition = $definition->getFieldStorageDefinition();
    return static::create([
      'field_storage' => \Drupal::service('fieldception.helper')->getSubfieldStorageDefinition($storage_definition, $config, $subfield),
      'bundle' => $definition->getTargetBundle(),
      'settings' => $field_settings,
      'name' => $name,
      'type' => $config['type'],
      'label' => $config['label'],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function isDisplayConfigurable($context) {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getDisplayOptions($display_context) {
    // Hide configurable fields by default.
    return ['region' => 'hidden'];
  }

  /**
   * {@inheritdoc}
   */
  public function getUniqueIdentifier() {
    return $this->getKey();
  }

  /**
   * {@inheritdoc}
   */
  public function isReadOnly() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function isComputed() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldStorageDefinition() {
    if (!$this->fieldStorage) {
      $field_storage_definitions = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions($this->entity_type);
      if (isset($field_storage_definitions[$this->field_name])) {
        $field_storage_definition = \Drupal::service('fieldception.helper')->getSubfieldStorageDefinition($field_storage_definitions[$this->field_name], $field_storage_definitions[$this->field_name]->getSettings()['storage'][$this->getSubfield()], $this->getSubfield());
      }
      if (!$field_storage_definition) {
        throw new FieldException("Attempt to create a field {$this->field_name} that does not exist on entity type {$this->entity_type}.");
      }
      $this->fieldStorage = $field_storage_definition;
    }
    return $this->fieldStorage;
  }

}
