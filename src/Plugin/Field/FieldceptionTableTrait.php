<?php

namespace Drupal\fieldception\Plugin\Field;

/**
 * Provides tables helpers.
 */
trait FieldceptionTableTrait {

  /**
   * Get position options.
   */
  public static function positionOptions() {
    return [
      '' => t('Left'),
      'center' => t('Center'),
      'right' => t('Right'),
      'max' => t('Maximum'),
      'min' => t('Minimum'),
    ];
  }

  /**
   * Get size options.
   */
  public static function sizeOptions() {
    return [
      '' => t('Auto'),
      'max' => t('Max'),
      'min' => t('Min'),
    ];
  }

}
