<?php

/**
 * @file
 * Theming for fieldception elements.
 */

function template_preprocess_fieldception_subfield(&$variables) {
  $variables['attributes']['class'][] = 'subfield';
  $variables['title_attributes']['class'][] = 'subfield-label';
  $variables['content_attributes']['class'][] = 'subfield-content';

  $variables['label_hidden'] = ($variables['label_display'] == 'hidden');
}
