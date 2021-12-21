<?php

namespace Drupal\fieldception\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * Plugin implementations for 'fieldception' formatter.
 *
 * @FieldFormatter(
 *   id = "fieldception_unformatted_list",
 *   label = @Translation("Unformatted list"),
 *   field_types = {"fieldception"}
 * )
 */
class FieldceptionUnformattedListFormatter extends FieldceptionBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    /** @var \Drupal\fieldception\FieldceptionHelper $fieldception_helper */
    $settings = $this->getSettings();
    $field_settings = $this->getFieldSettings();
    $field_definition = $this->fieldDefinition;
    $entity = $items->getEntity();
    $cacheable_metadata = new CacheableMetadata();

    $element = [];
    foreach ($items as $delta => $item) {
      $element[$delta] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['subfield-unformatted-list']],
      ];
      foreach ($field_settings['storage'] as $subfield => $config) {
        $subfield_settings = isset($settings['fields'][$subfield]) ? $settings['fields'][$subfield] : [];
        $subfield_formatter_settings = isset($subfield_settings['settings']) ? $subfield_settings['settings'] : [];
        $subfield_definition = $this->fieldceptionHelper->getSubfieldDefinition($field_definition, $config, $subfield);
        if ($subfield_settings['type'] === '_hidden') {
          continue;
        }
        $subfield_formatter = $this->fieldceptionHelper->getSubfieldFormatter($subfield_definition, $subfield_settings['type'], $subfield_formatter_settings, $this->viewMode, $this->label);
        $subfield_items = $this->fieldceptionHelper->getSubfieldItemList($subfield_definition, $entity, $delta);
        if (!$subfield_items->isEmpty()) {
          if ($subfield_items instanceof EntityReferenceFieldItemListInterface) {
            $subfield_formatter->prepareView([$subfield_items]);
          }
          $content = $subfield_formatter->viewElements($subfield_items, $langcode);
          foreach ($content as $child) {
            $cacheable_metadata = $cacheable_metadata->merge(CacheableMetadata::createFromRenderArray($child));
          }
          $element[$delta][$subfield] = [
            '#theme' => 'fieldception_subfield',
            '#definition' => $subfield_definition,
            '#label' => $config['label'],
            '#label_display' => !empty($settings['fields'][$subfield]['label_display']) ? $settings['fields'][$subfield]['label_display'] : 'above',
            '#content' => $content,
          ];
          if (!empty($subfield_settings['link_to_field']) && isset($field_settings['storage'][$subfield_settings['link_to_field']])) {
            $subfield_link = $subfield_settings['link_to_field'];
            $subfield_link_config = $field_settings['storage'][$subfield_link];
            $subfield_link_definition = $this->fieldceptionHelper->getSubfieldDefinition($field_definition, $subfield_link_config, $subfield_link);
            $subfield_link_items = $this->fieldceptionHelper->getSubfieldItemList($subfield_link_definition, $entity, $delta);
            $first = $subfield_link_items->first();
            if (!$first->isEmpty()) {
              $url = $this->buildUrl($first);
              if ($url) {
                $element[$delta][$subfield]['#tag'] = 'a';
                $element[$delta][$subfield]['#attributes'] = $url->getOption('attributes');
                $element[$delta][$subfield]['#attributes']['href'] = $url->toString();
              }
            }
          }
        }
      }
    }

    $cacheable_metadata->applyTo($element);

    return $element;
  }

}
