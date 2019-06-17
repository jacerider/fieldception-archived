<?php

namespace Drupal\fieldception\Plugin\Field\FieldType;

use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Plugin implementation of the 'fieldception' field type.
 *
 * @FieldType(
 *   id = "fieldception_entity_reference",
 *   label = @Translation("Fieldception entity reference"),
 *   description = @Translation("An entity field containing an entity reference."),
 *   category = @Translation("Reference"),
 *   default_widget = "entity_reference_autocomplete",
 *   default_formatter = "entity_reference_label",
 *   list_class = "\Drupal\Core\Field\EntityReferenceFieldItemList",
 *   no_ui = TRUE,
 * )
 */
class FieldceptionEntityReferenceItem extends EntityReferenceItem {

  /**
   * {@inheritdoc}
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::fieldSettingsForm($form, $form_state);
    unset($elements['handler']['handler']['#limit_validation_errors']);
    return $elements;
  }

  /**
   * Form element validation handler; Invokes selection plugin's validation.
   *
   * @param array $form
   *   The form where the settings form is being included in.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the (entire) configuration form.
   */
  public static function fieldSettingsFormValidate(array $form, FormStateInterface $form_state) {
    $current_subfield = $form['#fieldception_subfield'];
    $field_definition = $form_state->getFormObject()->getEntity();
    $settings = $field_definition->getSettings();
    foreach ($settings['storage'] as $subfield => $config) {
      if ($current_subfield == $subfield) {
        /** @var \Drupal\fieldception\FieldceptionHelper $fieldception_helper */
        $fieldception_helper = \Drupal::service('fieldception.helper');
        $subfield_definition = $fieldception_helper->getSubfieldDefinition($field_definition, $config, $subfield);
        $subfield_form_state = $fieldception_helper->getSubfieldFormState($subfield_definition, $form_state);
        parent::fieldSettingsFormValidate($form, $subfield_form_state);
        $form_state->setValue(['settings'], $subfield_form_state->getValue(['settings']));
      }
    }
  }

}
