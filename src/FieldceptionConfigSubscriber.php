<?php

namespace Drupal\fieldception;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Config\ConfigImporterEvent;

/**
 * Act on config events.
 */
class FieldceptionConfigSubscriber implements EventSubscriberInterface {

  /**
   * Store temporary table content.
   *
   * @var array
   */
  protected $tables = [];

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[ConfigEvents::IMPORT_VALIDATE] = 'onConfigImportValidate';
    $events[ConfigEvents::SAVE] = 'onConfigSave';
    return $events;
  }

  /**
   * Act on config import.
   */
  public function onConfigImportValidate(ConfigImporterEvent $event) {
    $storage = [];
    $importer = $event->getConfigImporter();
    $core_extension = $importer->getStorageComparer()->getSourceStorage();
    $pending = $core_extension->readMultiple($event->getChangelist('update'));
    foreach ($pending as $key => $config) {
      if (substr($key, 0, 14) == 'field.storage.') {
        if ($config['type'] == 'fieldception') {
          $entity = \Drupal::entityTypeManager()->getStorage('field_storage_config')->load($config['id']);
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
          $storage[$config['id']] = $tables;
        }
      }
    }
    if (!empty($storage)) {
      $tempstore = \Drupal::service('tempstore.private');
      $store = $tempstore->get('fieldception_config_import');
      $store->set('tables', $storage);
    }
  }

  /**
   * Act on config save.
   */
  public function onConfigSave(ConfigCrudEvent $event) {
    $tempstore = \Drupal::service('tempstore.private');
    $store = $tempstore->get('fieldception_config_import');
    $tables = $store->get('tables');
    if (!empty($tables)) {
      $store->delete('tables');
      foreach ($tables as $key => $tables) {
        $entity = \Drupal::entityTypeManager()->getStorage('field_storage_config')->load($key);
        $database = \Drupal::database();
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
    }
  }

}
