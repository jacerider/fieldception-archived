<?php

namespace Drupal\fieldception\Plugin\Field;

use Drupal\Core\Field\FieldItemList;

/**
 * Represents a configurable entity file field.
 */
class FieldceptionItemList extends FieldItemList {

  /**
   * {@inheritdoc}
   */
  public function getSubfieldValue($subfield) {
    foreach ($this->list as $delta => $item) {
      $values[$delta] = $item->getSubfieldValue($subfield);
    }
    return $values;
  }

  /**
   * Get subfield items lists for all subfields.
   */
  protected function getSubfieldItemLists() {
    $subfield_item_lists = [];
    $settings = $this->getSettings();
    $entity = $this->getEntity();
    $fieldception_helper = \Drupal::service('fieldception.helper');
    $field_name = $this->getFieldDefinition()->getName();
    $field_definition = $this->getFieldDefinition()->getFieldStorageDefinition();

    $items = $this->list;
    if (isset($entity->original) && count($items) < count($entity->original->{$field_name})) {
      foreach ($entity->original->{$field_name} as $delta => $item) {
        if (empty($items[$delta])) {
          $items[$delta] = [];
        }
      }
    }

    foreach ($items as $delta => $item) {
      foreach ($settings['storage'] as $subfield => $config) {
        $subfield_definition = $fieldception_helper->getSubfieldStorageDefinition($field_definition, $config, $subfield);
        $subfield_item_lists[$delta][$subfield] = $fieldception_helper->getSubfieldItemList($subfield_definition, $entity, $delta);
      }
    }
    return $subfield_item_lists;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave() {
    parent::preSave();
    foreach ($this->getSubfieldItemLists() as $delta => $subfield_item_lists) {
      foreach ($subfield_item_lists as $subfield => $subfield_items) {
        $subfield_items->preSave();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postSave($update) {
    foreach ($this->getSubfieldItemLists() as $delta => $subfield_item_lists) {
      foreach ($subfield_item_lists as $subfield => $subfield_items) {
        $subfield_items->postSave($update);
      }
    }
    return parent::postSave($update);
  }

  /**
   * {@inheritdoc}
   */
  public function delete() {
    parent::delete();
    foreach ($this->getSubfieldItemLists() as $delta => $subfield_item_lists) {
      foreach ($subfield_item_lists as $subfield => $subfield_items) {
        $subfield_items->delete();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteRevision() {
    parent::deleteRevision();
    foreach ($this->getSubfieldItemLists() as $delta => $subfield_item_lists) {
      foreach ($subfield_item_lists as $subfield => $subfield_items) {
        $subfield_items->deleteRevision();
      }
    }

  }

  /**
   * {@inheritdoc}
   */
  public function referencedEntities() {
    if ($this->isEmpty()) {
      return [];
    }
    $settings = $this->getSettings();
    $entities = [];
    foreach ($this->list as $item) {
      if (!$item->isEmpty()) {
        $entities += $item->referencedEntities();
      }
    }
    return $entities;
  }

}
