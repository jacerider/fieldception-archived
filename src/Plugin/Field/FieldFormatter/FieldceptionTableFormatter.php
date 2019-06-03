<?php

namespace Drupal\fieldception\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\fieldception\Plugin\Field\FieldceptionTableTrait;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementations for 'fieldception' formatter.
 *
 * @FieldFormatter(
 *   id = "fieldception_table",
 *   label = @Translation("Table"),
 *   field_types = {"fieldception"}
 * )
 */
class FieldceptionTableFormatter extends FieldceptionBase {
  use FieldceptionTableTrait;

  /**
   * {@inheritdoc}
   */
  public static function defaultWidgetSettings() {
    return [
      'position' => '',
      'size' => '',
    ] + parent::defaultWidgetSettings();
  }

  /**
   * {@inheritdoc}
   */
  protected function settingsFormField($subfield, $config, $settings, FormStateInterface $form_state) {
    $elements = parent::settingsFormField($subfield, $config, $settings, $form_state);

    $elements['position'] = [
      '#type' => 'select',
      '#title' => $this->t('Position'),
      '#options' => self::positionOptions(),
      '#default_value' => $settings['position'],
      '#access' => $elements['settings']['#access'],
    ];
    $elements['size'] = [
      '#type' => 'select',
      '#title' => $this->t('Size'),
      '#options' => self::sizeOptions(),
      '#default_value' => $settings['size'],
      '#access' => $elements['settings']['#access'],
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  protected function settingsFieldSummary($subfield, $config, $settings) {
    $summary = parent::settingsFieldSummary($subfield, $config, $settings);

    $options = self::positionOptions();
    $summary[] = $this->t('Position: %value', ['%value' => isset($options[$settings['position']]) ? $options[$settings['position']] : reset($options)]);

    $options = self::sizeOptions();
    $summary[] = $this->t('Size: %value', ['%value' => isset($options[$settings['size']]) ? $options[$settings['size']] : reset($options)]);

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $fieldception_helper = \Drupal::service('fieldception.helper');
    $settings = $this->getSettings();
    $field_settings = $this->getFieldSettings();
    $field_definition = $this->fieldDefinition->getFieldStorageDefinition();
    $entity = $items->getEntity();
    $cacheable_metadata = new CacheableMetadata();

    $element = [
      '#type' => 'table',
    ];
    foreach ($items as $delta => $item) {
      $element['#rows'][$delta] = [];
      foreach ($field_settings['storage'] as $subfield => $config) {
        $element['#header'][$subfield] = $config['label'];
        $subfield_settings = isset($settings['fields'][$subfield]) ? $settings['fields'][$subfield] : [];
        $subfield_formatter_settings = isset($subfield_settings['settings']) ? $subfield_settings['settings'] : [];
        $subfield_definition = $fieldception_helper->getSubfieldDefinition($field_definition, $config, $subfield);
        if ($subfield_settings['type'] === '_hidden') {
          continue;
        }
        $subfield_formatter = $fieldception_helper->getSubfieldFormatter($subfield_definition, $subfield_settings['type'], $subfield_formatter_settings, $this->viewMode, $this->label);
        $subfield_items = $fieldception_helper->getSubfieldItemList($subfield_definition, $entity, $delta);
        if (!$subfield_items->isEmpty()) {
          if ($subfield_items instanceof EntityReferenceFieldItemListInterface) {
            $subfield_formatter->prepareView([$subfield_items]);
          }
          $content = $subfield_formatter->viewElements($subfield_items, $langcode);
          foreach ($content as $child) {
            $cacheable_metadata = $cacheable_metadata->merge(CacheableMetadata::createFromRenderArray($child));
          }
          $content['#attached']['library'][] = 'fieldception/field';
          $element['#rows'][$delta][$subfield]['data'] = $content;

          if (!empty($subfield_settings['position'])) {
            $element['#rows'][$delta][$subfield]['class'][] = 'fieldception-position-' . $subfield_settings['position'];
          }

          if (!empty($subfield_settings['size'])) {
            $element['#rows'][$delta][$subfield]['class'][] = 'fieldception-size-' . $subfield_settings['size'];
          }
        }
      }
    }

    $cacheable_metadata->applyTo($element);

    if (!empty($element['#rows'])) {
      return [$element];
    }
    return [];
  }

}
