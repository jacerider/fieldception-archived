<?php

namespace Drupal\fieldception\Plugin\Field\FieldType;

use Drupal\dynamic_entity_reference\Plugin\Field\FieldType\DynamicEntityReferenceItem;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'fieldception' field type.
 *
 * @FieldType(
 *   id = "fieldception_dynamic_entity_reference",
 *   label = @Translation("Fieldception entity reference"),
 *   description = @Translation("An entity field containing a dynamic entity reference."),
 *   category = @Translation("Dynamic Reference"),
 *   list_class = "\Drupal\dynamic_entity_reference\Plugin\Field\FieldType\DynamicEntityReferenceFieldItemList",
 *   default_widget = "dynamic_entity_reference_default",
 *   default_formatter = "dynamic_entity_reference_label",
 *   no_ui = TRUE,
 * )
 */
class FieldceptionDynamicEntityReferenceItem extends DynamicEntityReferenceItem {

  /**
   * {@inheritdoc}
   */
  public function setValue($values, $notify = TRUE) {
    // An error occures when $values is empty. May have side effects.
    if (empty($values)) {
      return;
    }
    parent::setValue($values, $notify);
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
