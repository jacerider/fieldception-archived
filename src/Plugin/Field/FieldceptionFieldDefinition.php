<?php

namespace Drupal\fieldception\Plugin\Field;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\TypedData\FieldItemDataDefinition;
use Drupal\Core\TypedData\OptionsProviderInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * A class for defining entity fields.
 */
class FieldceptionFieldDefinition extends BaseFieldDefinition {

  /**
   * The subfield key.
   *
   * @var string
   */
  protected $key;

  /**
   * The subfield.
   *
   * @var string
   */
  protected $subfield;

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
   * Sets the subfield.
   *
   * @param string $subfield
   *   The subfield to set.
   *
   * @return static
   *   The object itself for chaining.
   */
  public function setSubfield($subfield) {
    $this->subfield = $subfield;
    return $this;
  }

  /**
   * Gets the subfield.
   *
   * @return string
   *   The subfield.
   */
  public function getSubfield() {
    return $this->subfield;
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
   * @param string $type
   *   The type of the field.
   * @param \Drupal\Core\Field\FieldStorageDefinitionInterface $definition
   *   The field storage definition to base the new field definition upon.
   *
   * @return $this
   */
  public static function createFromParentFieldStorageDefinition($type, FieldStorageDefinitionInterface $definition) {
    return static::create($type)
      ->setCardinality($definition->getCardinality())
      ->setConstraints($definition->getConstraints())
      ->setCustomStorage($definition->hasCustomStorage())
      ->setDescription($definition->getDescription())
      ->setLabel($definition->getLabel())
      ->setName($definition->getName())
      ->setProvider($definition->getProvider())
      ->setQueryable($definition->isQueryable())
      ->setRevisionable($definition->isRevisionable())
      ->setSettings($definition->getSettings())
      ->setTargetEntityTypeId($definition->getTargetEntityTypeId())
      ->setTranslatable($definition->isTranslatable());
  }

  /**
   * {@inheritdoc}
   */
  public function getOptionsProvider($property_name, FieldableEntityInterface $entity) {
    if (is_subclass_of($this->getFieldItemClass(), OptionsProviderInterface::class)) {
      // $fieldception_helper = \Drupal::service('fieldception.helper');
      // $items = $fieldception_helper->getSubfieldItemList($this->getFieldStorageDefinition(), $entity);
      $items = FieldItemList::createInstance($this->getFieldStorageDefinition(), $name = NULL, $entity->getTypedData());
      return \Drupal::service('plugin.manager.field.field_type')->createFieldItem($items, 0);
    }
    // @todo: Allow setting custom options provider, see
    // https://www.drupal.org/node/2002138.
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions() {
    if (!isset($this->propertyDefinitions) || TRUE) {
      $class = $this->getFieldItemClass();
      $this->propertyDefinitions = $class::propertyDefinitions($this);
      // if ($this->subfield) {
      //   $subfield_property_definitions = [];
      //   foreach ($this->propertyDefinitions as $key => $value) {
      //     $subfield_property_definitions[$this->subfield . '_' . $key] = $value;
      //   }
      //   $this->propertyDefinitions = $subfield_property_definitions;
      // }
    }
    return $this->propertyDefinitions;
  }

  /**
   * {@inheritdoc}
   */
  // public function getPropertyNames() {
  //   $names = [];
  //   foreach ($this->getPropertyDefinitions() as $property_name => $property) {
  //     $names[] = $this->subfield . '_' . $property_name;
  //   }
  //   return $names;
  // }

}
