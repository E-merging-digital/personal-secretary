/**
 * @file
 * Progressive, network-free browser timezone suggestion.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.personalSecretaryTimezoneDetection = {
    attach(context) {
      once(
        'personal-secretary-timezone-detection',
        'select.personal-secretary-timezone-detect',
        context,
      ).forEach((select) => {
        let detected = '';
        try {
          detected = new Intl.DateTimeFormat().resolvedOptions().timeZone || '';
        }
        catch (error) {
          detected = '';
        }

        if (
          detected !== ''
          && Array.from(select.options).some((option) => option.value === detected)
        ) {
          select.value = detected;
        }
      });
    },
  };
})(Drupal, once);
