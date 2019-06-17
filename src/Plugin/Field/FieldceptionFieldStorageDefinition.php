<?php

namespace Drupal\fieldception\Plugin\Field;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\TypedData\OptionsProviderInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Config\Entity\ThirdPartySettingsInterface;

/**
 * A class for defining entity fields.
 */
class FieldceptionFieldStorageDefinition extends BaseFieldDefinition implements ThirdPartySettingsInterface {

  /**
   * The subfield key.
   *
   * @var string
   */
  protected $key;

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
    $this->key = $key;
    return $this;
  }

  /**
   * Gets the subfield key.
   *
   * @return string
   *   The subfield key.
   */
  public function getKey() {
    return $this->key;
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
   * Creates a new field definition based upon a field storage definition.
   *
   * In cases where one needs a field storage definitions to act like full
   * field definitions, this creates a new field definition based upon the
   * (limited) information available. That way it is possible to use the field
   * definition in places where a full field definition is required; e.g., with
   * widgets or formatters.
   *
   * @param \Drupal\Core\Field\FieldStorageDefinitionInterface $definition
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
  public static function createFromParentFieldStorageDefinition(FieldStorageDefinitionInterface $definition, array $config, $subfield) {
    // FieldceptionHelper->getSubfieldItemList() will convert this back to
    // the actual field name.
    $name = $definition->getName() . ':' . $subfield;
    $storage = static::create($config['type'])
      // Subfields only support single values.
      ->setCardinality(1)
      ->setConstraints($definition->getConstraints())
      ->setCustomStorage($definition->hasCustomStorage())
      ->setDescription($definition->getDescription())
      ->setLabel($config['label'])
      ->setName($name)
      ->setProvider($definition->getProvider())
      ->setQueryable($definition->isQueryable())
      ->setRevisionable($definition->isRevisionable())
      ->setSettings($config['settings'])
      ->setTargetEntityTypeId($definition->getTargetEntityTypeId())
      ->setTranslatable($definition->isTranslatable());
    return $storage;
  }

  /**
   * {@inheritdoc}
   */
  public function getOptionsProvider($property_name, FieldableEntityInterface $entity) {
    if (is_subclass_of($this->getFieldItemClass(), OptionsProviderInterface::class)) {
      $field_type_plugin_manager = \Drupal::service('plugin.manager.field.field_type');

      $item_list_class = $field_type_plugin_manager->getDefinition($this->getFieldStorageDefinition()->getType())['list_class'];
      $items_list = $item_list_class::createInstance($this->getFieldStorageDefinition(), $this->getName(), $entity->getTypedData());
      $item = $field_type_plugin_manager->createFieldItem($items_list, 0);
      return $item;
    }
  }

}
