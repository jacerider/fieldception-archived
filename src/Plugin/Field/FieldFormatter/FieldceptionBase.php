<?php

namespace Drupal\fieldception\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\link\LinkItemInterface;

/**
 * Base class for Double field formatters.
 */
abstract class FieldceptionBase extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'fields' => [],
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $fieldception_helper = \Drupal::service('fieldception.helper');
    $settings = $this->getSettings();
    $field_settings = $this->getFieldSettings();
    $field_definition = $this->fieldDefinition->getFieldStorageDefinition();
    $field_name = $this->fieldDefinition->getName();

    $element = [];

    $element['fields'] = [
      '#tree' => TRUE,
    ];
    $link_field_options = [];
    foreach ($field_settings['storage'] as $subfield => $config) {
      if ($config['type'] === 'link') {
        $link_field_options[$subfield] = $config['label'];
      }
    }

    foreach ($field_settings['storage'] as $subfield => $config) {
      $wrapper_id = Html::getId('fieldception-' . $field_name . '-' . $subfield);

      $subfield_settings = isset($settings['fields'][$subfield]['settings']) ? $settings['fields'][$subfield]['settings'] : [];
      $subfield_definition = $fieldception_helper->getSubfieldDefinition($field_definition, $config, $subfield);
      // Get type. First use submitted value, Then current settings. Then
      // default formatter if nothing has been set yet.
      $subfield_formatter_type = $form_state->getValue([
        'fields',
        $field_name,
        'settings_edit_form',
        'settings',
        'fields',
        $subfield,
        'type',
      ]) ?: $this->getSubfieldFormatterType($subfield_definition);

      $is_hidden = $subfield_formatter_type === '_hidden';

      $element['fields'][$subfield] = [
        '#type' => 'fieldset',
        '#title' => $config['label'],
        '#tree' => TRUE,
        '#id' => $wrapper_id,
      ];
      $element['fields'][$subfield]['type'] = [
        '#type' => 'select',
        '#title' => $this->t('Formatter'),
        '#options' => ['_hidden' => '- Hidden -'] + $fieldception_helper->getFieldFormatterPluginManager()->getOptions($config['type']),
        '#default_value' => $subfield_formatter_type,
        '#required' => TRUE,
        '#ajax' => [
          'callback' => [get_class($this), 'settingsFormAjax'],
          'wrapper' => $wrapper_id,
        ],
      ];
      $element['fields'][$subfield]['label_display'] = [
        '#type' => 'select',
        '#title' => $this->t('Label display'),
        '#options' => $this->getFieldLabelOptions(),
        '#default_value' => !empty($settings['fields'][$subfield]['label_display']) ? $settings['fields'][$subfield]['label_display'] : 'above',
        '#access' => !$is_hidden,
      ];

      $element['fields'][$subfield]['settings'] = [
        '#access' => !$is_hidden,
      ];
      if (!$is_hidden) {
        $subfield_formatter = $fieldception_helper->getSubfieldFormatter($subfield_definition, $subfield_formatter_type, $subfield_settings, $this->viewMode, $this->label);
        $element['fields'][$subfield]['settings'] = $subfield_formatter->settingsForm($element['fields'][$subfield]['settings'], $form_state);

        if (!empty($link_field_options) && $config['type'] !== 'link' && isset($element['fields'][$subfield]['settings']['link_to_entity'])) {
          $element['fields'][$subfield]['settings']['link_to_field'] = [
            '#type' => 'select',
            '#title' => $this->t('Link using a field'),
            '#options' => ['' => $this->t('- None -')] + $link_field_options,
            '#default_value' => !empty($subfield_settings['link_to_field']) ? $subfield_settings['link_to_field'] : '',
          ];

        }
      }
    }

    return $element + parent::settingsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    return [
      'Inception of the field',
    ];
  }

  /**
   * Ajax callback for the handler settings form.
   *
   * @see static::fieldSettingsForm()
   */
  public static function settingsFormAjax($form, FormStateInterface $form_state) {
    $element = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($element['#array_parents'], 0, -1));
  }

  /**
   * {@inheritdoc}
   *
   * Merge field settings into storage settings to simplify configuration.
   */
  protected function getFieldSettings() {
    $settings = $this->fieldDefinition->getSettings();
    foreach ($settings['storage'] as $subfield => $config) {
      if (isset($settings['fields'][$subfield]['settings'])) {
        $settings['storage'][$subfield]['settings'] += $settings['fields'][$subfield]['settings'];
      }
    }
    return $settings;
  }

  /**
   * Returns an array of visibility options for field labels.
   *
   * @return array
   *   An array of visibility options.
   */
  protected function getFieldLabelOptions() {
    return [
      'above' => $this->t('Above'),
      'inline' => $this->t('Inline'),
      'hidden' => '- ' . $this->t('Hidden') . ' -',
      'visually_hidden' => '- ' . $this->t('Visually Hidden') . ' -',
    ];
  }

  /**
   * Get subfield widget type.
   */
  protected function getSubfieldFormatterType($subfield_definition) {
    $subfield = $subfield_definition->getSubfield();
    $settings = $this->getSettings();
    if (!empty($settings['fields'][$subfield]['type'])) {
      return $settings['fields'][$subfield]['type'];
    }
    return \Drupal::service('fieldception.helper')->getSubfieldDefaultFormatter($subfield_definition);
  }

  /**
   * Builds the \Drupal\Core\Url object for a link field item.
   *
   * @param \Drupal\link\LinkItemInterface $item
   *   The link field item being rendered.
   *
   * @return \Drupal\Core\Url
   *   A Url object.
   */
  protected function buildUrl(LinkItemInterface $item) {
    $url = $item->getUrl() ?: Url::fromRoute('<none>');

    $settings = $this->getSettings();
    $options = $item->options;
    $options += $url->getOptions();

    // Add optional 'rel' attribute to link options.
    if (!empty($settings['rel'])) {
      $options['attributes']['rel'] = $settings['rel'];
    }
    // Add optional 'target' attribute to link options.
    if (!empty($settings['target'])) {
      $options['attributes']['target'] = $settings['target'];
    }
    $url->setOptions($options);

    return $url;
  }

}
