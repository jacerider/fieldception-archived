<?php

namespace Drupal\fieldception\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\link\LinkItemInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\fieldception\FieldceptionHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for Double field formatters.
 */
abstract class FieldceptionBase extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * The Fieldception helper.
   *
   * @var \Drupal\fieldception\FieldceptionHelper
   */
  protected $fieldceptionHelper;

  /**
   * {@inheritdoc}
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, FieldceptionHelper $fieldcaption_helper) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->fieldceptionHelper = $fieldcaption_helper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('fieldception.helper')
    );
  }

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
  public static function defaultWidgetSettings() {
    return [
      'label_display' => 'above',
      'settings' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSettings() {
    $field_definition = $this->fieldDefinition;
    // Merge defaults before returning the array.
    if (!$this->defaultSettingsMerged) {
      $this->mergeDefaults();
    }
    $field_settings = $this->getFieldSettings();
    foreach ($field_settings['storage'] as $subfield => $config) {
      $subfield_definition = $this->fieldceptionHelper->getSubfieldDefinition($field_definition, $config, $subfield);
      $this->settings[$subfield] = isset($this->settings['fields'][$subfield]) ? $this->settings['fields'][$subfield] : [];
      if (empty($this->settings['fields'][$subfield]['type'])) {
        $this->settings['fields'][$subfield]['type'] = $this->fieldceptionHelper->getSubfieldDefaultFormatter($subfield_definition);
      }
      $this->settings['fields'][$subfield] += static::defaultWidgetSettings();
    }
    return $this->settings;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $settings = $this->getSettings();
    $field_settings = $this->getFieldSettings();

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
      $subfield_settings = isset($settings['fields'][$subfield]) ? $settings['fields'][$subfield] : [];
      $subfield_formatter_settings = isset($subfield_settings['settings']) ? $subfield_settings['settings'] : [];
      $element['fields'][$subfield] = $this->settingsFormField($subfield, $config, $subfield_settings, $form_state);
      if ($element['fields'][$subfield]['settings']['#access'] && !empty($link_field_options) && $config['type'] !== 'link' && isset($element['settings']['link_to_entity'])) {
        $element['fields'][$subfield]['settings']['link_to_field'] = [
          '#type' => 'select',
          '#title' => $this->t('Link using a field'),
          '#options' => ['' => $this->t('- None -')] + $link_field_options,
          '#default_value' => !empty($subfield_formatter_settings['link_to_field']) ? $subfield_formatter_settings['link_to_field'] : '',
        ];
      }
    }

    return $element + parent::settingsForm($form, $form_state);
  }

  /**
   * Individual field settings.
   */
  protected function settingsFormField($subfield, $config, $settings, FormStateInterface $form_state) {
    $field_definition = $this->fieldDefinition;
    $field_name = $this->fieldDefinition->getName();
    $wrapper_id = Html::getId('fieldception-' . $field_name . '-' . $subfield);
    $subfield_formatter_settings = isset($settings['settings']) ? $settings['settings'] : [];
    $subfield_definition = $this->fieldceptionHelper->getSubfieldDefinition($field_definition, $config, $subfield);
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
    ]) ?: $settings['type'];

    $is_hidden = $subfield_formatter_type === '_hidden';
    $element = [
      '#type' => 'fieldset',
      '#title' => $config['label'],
      '#tree' => TRUE,
      '#id' => $wrapper_id,
    ];
    $element['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Formatter'),
      '#options' => ['_hidden' => '- Hidden -'] + $this->fieldceptionHelper->getFieldFormatterPluginManager()->getOptions($config['type']),
      '#default_value' => $subfield_formatter_type,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => [get_class($this), 'settingsFormAjax'],
        'wrapper' => $wrapper_id,
      ],
    ];
    $element['label_display'] = [
      '#type' => 'select',
      '#title' => $this->t('Label display'),
      '#options' => $this->getFieldLabelOptions(),
      '#default_value' => !empty($settings['label_display']) ? $settings['label_display'] : 'above',
      '#access' => !$is_hidden,
    ];

    $element['settings'] = [
      '#access' => !$is_hidden,
    ];
    if (!$is_hidden) {
      $subfield_formatter = $this->fieldceptionHelper->getSubfieldFormatter($subfield_definition, $subfield_formatter_type, $subfield_formatter_settings, $this->viewMode, $this->label);
      $element['settings'] += $subfield_formatter->settingsForm($element['settings'], $form_state);
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $settings = $this->getSettings();
    $field_settings = $this->getFieldSettings();
    $field_definition = $this->fieldDefinition->getFieldStorageDefinition();
    $summary = [];

    foreach ($field_settings['storage'] as $subfield => $config) {
      if (key($field_settings['storage']) != $subfield) {
        $summary[] = '--------';
      }
      $subfield_settings = isset($settings['fields'][$subfield]) ? $settings['fields'][$subfield] : [];
      $summary = array_merge($summary, $this->settingsFieldSummary($subfield, $config, $subfield_settings));
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  protected function settingsFieldSummary($subfield, $config, $settings) {
    $summary = [];
    $field_definition = $this->fieldDefinition;
    $subfield_formatter_settings = isset($settings['settings']) ? $settings['settings'] : [];
    $subfield_definition = $this->fieldceptionHelper->getSubfieldDefinition($field_definition, $config, $subfield);
    $subfield_formatter_type = $settings['type'];
    $subfield_formatter_options = $this->fieldceptionHelper->getFieldFormatterPluginManager()->getOptions($config['type']);
    $is_hidden = $subfield_formatter_type === '_hidden';
    $summary[] = $this->t('Subfield: %value', ['%value' => $config['label']]);
    if (!$is_hidden && isset($subfield_formatter_options[$subfield_formatter_type])) {
      $subfield_formatter = $this->fieldceptionHelper->getSubfieldFormatter($subfield_definition, $subfield_formatter_type, $subfield_formatter_settings, $this->viewMode, $this->label);
      $summary[] = $this->t('Label display: %value', ['%value' => $this->getFieldLabelOptions()[$settings['label_display']]]);
      $summary[] = $this->t('Format: %value', ['%value' => $subfield_formatter_options[$subfield_formatter_type]]);
      $summary = array_merge($summary, $subfield_formatter->settingsSummary());
    }
    else {
      $summary[] = $this->t('Format: %value', ['%value' => $this->t('- Hidden -')]);
    }
    return $summary;
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
