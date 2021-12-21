<?php

/**
 * @file
 * Theming for fieldception elements.
 */

/**
 * Preprocess fieldception subfield.
 */
function template_preprocess_fieldception_subfield(&$variables) {
  $variables['field_name'] = $variables['definition']->getName();
  $variables['attributes']['class'][] = 'subfield';
  $variables['title_attributes']['class'][] = 'subfield-label';
  $variables['content_attributes']['class'][] = 'subfield-content';

  $variables['label_hidden'] = ($variables['label_display'] == 'hidden');
}
