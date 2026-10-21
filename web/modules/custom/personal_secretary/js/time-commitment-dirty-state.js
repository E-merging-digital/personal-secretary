/**
 * @file
 * Marks the time-commitment editor clean only for unchanged visible fields.
 *
 * Persisted transition semantics remain exclusively server-side.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.personalSecretaryTimeCommitmentDirtyState = {
    attach(context) {
      once(
        'personal-secretary-time-commitment-dirty-state',
        'form[data-ps-time-commitment-editor]',
        context,
      ).forEach((form) => {
        const date = form.querySelector('input[data-ps-time-commitment-date]');
        const modes = form.querySelectorAll('input[name="mode"]');
        const save = form.querySelector('[data-ps-time-commitment-save]');
        const cleanHelp = form.querySelector('[data-ps-time-commitment-clean]');
        if (!date || !modes.length || !save || !cleanHelp) {
          return;
        }

        // Capture the exact initial rendered form values, not a domain rule.
        const initialDate = date.value;
        const currentMode = () =>
          form.querySelector('input[name="mode"]:checked')?.value ?? '';
        const initialMode = currentMode();

        const update = () => {
          const dirty = date.value !== initialDate || currentMode() !== initialMode;
          save.disabled = !dirty;
          cleanHelp.hidden = dirty;
        };

        date.addEventListener('input', update);
        date.addEventListener('change', update);
        modes.forEach((radio) => {
          radio.addEventListener('input', update);
          radio.addEventListener('change', update);
        });
        update();
      });
    },
  };
})(Drupal, once);
