/**
 * @file
 * Suggests a same-day end for new timed activities without overriding edits.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.personalSecretaryActivityDefaultDuration = {
    attach(context) {
      once(
        'personal-secretary-activity-default-duration',
        'input[data-ps-activity-start]',
        context,
      ).forEach((start) => {
        const form = start.closest('form');
        const end = form?.querySelector('input[data-ps-activity-end]');
        if (!form || !end) {
          return;
        }

        // A server-rendered end (e.g. validation error) is user-controlled.
        let autoManaged = end.value === '';
        let previousSuggestion = null;

        const status = document.createElement('span');
        status.className = 'visually-hidden';
        status.dataset.psActivityDurationStatus = '';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        end.insertAdjacentElement('afterend', status);

        const isTimed = () =>
          form.querySelector('input[name="time_mode"]:checked')?.value === 'timed';

        const suggest = () => {
          if (!autoManaged || !isTimed()) {
            return;
          }

          const match = /^([01]\d|2[0-3]):([0-5]\d)$/.exec(start.value);
          const minutes = match ? Number(match[1]) * 60 + Number(match[2]) + 30 : 1440;
          if (minutes >= 1440) {
            // Do not represent a next-day end as a same-day clock value.
            if (previousSuggestion !== null && end.value === previousSuggestion) {
              end.value = '';
              status.textContent = '';
            }
            previousSuggestion = null;
            return;
          }

          if (end.value !== '' && end.value !== previousSuggestion) {
            autoManaged = false;
            return;
          }

          const suggestion = String(Math.floor(minutes / 60)).padStart(2, '0')
            + ':' + String(minutes % 60).padStart(2, '0');
          end.value = suggestion;
          previousSuggestion = suggestion;
          // Reuse the localized Drupal label; announce without changing focus.
          const label = end.labels?.[0]?.textContent.trim() || '';
          status.textContent = label + ': ' + suggestion;
        };

        // Editing or clearing the end stays manual for this form instance.
        const markManual = () => {
          autoManaged = false;
          previousSuggestion = null;
          status.textContent = '';
        };

        start.addEventListener('input', suggest);
        start.addEventListener('change', suggest);
        end.addEventListener('input', markManual);
        end.addEventListener('change', markManual);
        form.querySelectorAll('input[name="time_mode"]').forEach((mode) => {
          mode.addEventListener('change', suggest);
        });

        suggest();
      });
    },
  };
})(Drupal, once);
