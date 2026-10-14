<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\personal_secretary\Entity\PersonalTask;
use Drupal\personal_secretary\Service\ActivityCaptureResolver;
use Drupal\personal_secretary\Service\CurrentUserActivityCreationService;
use Drupal\personal_secretary\Service\ThreeIntentReadProposeService;
use Drupal\personal_secretary\Service\TodayService;
use Drupal\personal_secretary\Value\ActivityCaptureExtraction;
use Drupal\personal_secretary\Value\ActivityCaptureInput;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Optional structured assistant, without any write or external provider.
 */
final class GuidedAssistantForm extends FormBase {

  private readonly ThreeIntentReadProposeService $capabilities;

  public function __construct(
    private readonly TodayService $today,
    private readonly CurrentUserActivityCreationService $activityCreation,
    ActivityCaptureResolver $captureResolver,
    private readonly LanguageManagerInterface $interfaceLanguage,
  ) {
    $this->capabilities = new ThreeIntentReadProposeService(
      $today, $activityCreation, $captureResolver,
    );
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.today'),
      $container->get('personal_secretary.current_user_activity_creation'),
      $container->get('personal_secretary.activity_capture_resolver'),
      $container->get('language_manager'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_guided_assistant';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'ps-guided-assistant';
    $form['#cache'] = ['max-age' => 0];
    $form['intro'] = [
      '#type' => 'html_tag', '#tag' => 'p',
      '#attributes' => ['class' => ['ps-guided-assistant__intro']],
      '#value' => $this->tr(
        'Choose an action. This guided assistant does not interpret free text or save anything.',
        'Choisissez une action. Cet assistant guidé n’interprète pas de texte libre et n’enregistre rien.',
      ),
    ];
    $form['back'] = $this->backLink();

    try {
      $scope = $this->activityCreation->creationScope();
      $this->today->today();
      if ($scope['households'] === []) {
        throw new InvalidArgumentException('No eligible Household.');
      }
    }
    catch (InvalidArgumentException|RuntimeException) {
      $form['remediation'] = [
        '#type' => 'html_tag', '#tag' => 'p',
        '#value' => $this->tr(
          'Link your account to an authorized Household member before using the assistant.',
          'Liez votre compte à un membre autorisé d’un foyer avant d’utiliser l’assistant.',
        ),
      ];
      return $form;
    }

    $form['intent'] = [
      '#type' => 'radios',
      '#title' => $this->tr('Choose an action', 'Choisir une action'),
      '#options' => [
        ThreeIntentReadProposeService::GET_TODAY => $this->tr('View my day', 'Consulter ma journée'),
        ThreeIntentReadProposeService::PROPOSE_TASK => $this->tr('Prepare a task', 'Préparer une tâche'),
        ThreeIntentReadProposeService::PROPOSE_ACTIVITY => $this->tr('Prepare an activity', 'Préparer une activité'),
      ],
      '#default_value' => ThreeIntentReadProposeService::GET_TODAY,
    ];

    $form['task'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ps-guided-assistant__fields']],
      '#states' => ['visible' => [':input[name="intent"]' => ['value' => ThreeIntentReadProposeService::PROPOSE_TASK]]],
    ];
    $form['task']['task_title'] = [
      '#type' => 'textfield', '#title' => $this->tr('Task title', 'Intitulé de la tâche'),
      '#maxlength' => 255,
    ];
    if (count($scope['households']) > 1) {
      $options = ['' => $this->tr('Select a Household', 'Choisir un foyer')];
      foreach ($scope['households'] as $id => $household) {
        $options[$id] = $household->label();
      }
      $form['task']['task_household'] = [
        '#type' => 'select', '#title' => $this->tr('Household', 'Foyer'),
        '#options' => $options, '#default_value' => '',
      ];
    }
    $form['task']['task_due_mode'] = [
      '#type' => 'select', '#title' => $this->tr('Due', 'Échéance'),
      '#options' => [
        PersonalTask::DUE_NONE => $this->tr('No deadline', 'Sans échéance'),
        PersonalTask::DUE_DATE => $this->tr('Date', 'Date'),
        PersonalTask::DUE_DATE_TIME => $this->tr('Date and time', 'Date et heure'),
      ],
      '#default_value' => PersonalTask::DUE_NONE,
    ];
    $form['task']['task_due_date'] = [
      '#type' => 'date', '#title' => $this->tr('Due date', 'Date d’échéance'),
      '#states' => ['visible' => [':input[name="task_due_mode"]' => ['value' => PersonalTask::DUE_DATE]]],
    ];
    $form['task']['task_due_at'] = [
      '#type' => 'textfield', '#maxlength' => 16,
      '#title' => $this->tr('Due date and time (YYYY-MM-DD HH:MM)', 'Échéance (AAAA-MM-JJ HH:MM)'),
      '#states' => ['visible' => [':input[name="task_due_mode"]' => ['value' => PersonalTask::DUE_DATE_TIME]]],
    ];

    $form['activity'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ps-guided-assistant__fields']],
      '#states' => ['visible' => [':input[name="intent"]' => ['value' => ThreeIntentReadProposeService::PROPOSE_ACTIVITY]]],
    ];
    $form['activity']['activity_label'] = [
      '#type' => 'textfield', '#title' => $this->tr('Activity title', 'Intitulé de l’activité'),
      '#maxlength' => 255,
    ];
    $form['activity']['activity_date'] = [
      '#type' => 'date', '#title' => $this->tr('Activity date', 'Date de l’activité'),
    ];
    $form['activity']['activity_mode'] = [
      '#type' => 'select', '#title' => $this->tr('Time mode', 'Type d’horaire'),
      '#options' => [
        'timed' => $this->tr('With times', 'Avec heures'),
        'all_day' => $this->tr('All day', 'Toute la journée'),
      ],
      '#default_value' => 'timed',
    ];
    $form['activity']['activity_start'] = [
      '#type' => 'textfield', '#maxlength' => 5,
      '#title' => $this->tr('Start (HH:MM)', 'Début (HH:MM)'),
      '#states' => ['visible' => [':input[name="activity_mode"]' => ['value' => 'timed']]],
    ];
    $form['activity']['activity_end'] = [
      '#type' => 'textfield', '#maxlength' => 5,
      '#title' => $this->tr('End (HH:MM)', 'Fin (HH:MM)'),
      '#states' => ['visible' => [':input[name="activity_mode"]' => ['value' => 'timed']]],
    ];
    $form['activity']['activity_responsible_self'] = [
      '#type' => 'checkbox',
      '#title' => $this->tr('I am responsible', 'Je suis responsable'),
      '#default_value' => FALSE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['ps-guided-assistant__actions']],
    ];
    $form['actions']['preview'] = [
      '#type' => 'submit',
      '#value' => $this->tr('Show preview', 'Afficher l’aperçu'),
    ];
    $result = $form_state->get('guided_result');
    if (is_array($result)) {
      $form['result'] = $this->resultBuild($result);
    }
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $scope = $this->activityCreation->creationScope();
      $today = $this->today->today();
      if ($scope['households'] === []) {
        throw new InvalidArgumentException('No eligible Household.');
      }
    }
    catch (InvalidArgumentException|RuntimeException) {
      $this->show($form_state, [
        'status' => 'ACCESS_DENIED', 'data' => NULL,
        'clarifications' => ['authorized_scope_required'], 'saved' => FALSE,
      ]);
      return;
    }

    $intent = (string) $form_state->getValue('intent');
    $input = [];
    if ($intent === ThreeIntentReadProposeService::PROPOSE_TASK) {
      $input = [
        'title' => (string) $form_state->getValue('task_title'),
        'due_mode' => (string) $form_state->getValue('task_due_mode'),
      ];
      $selected = $form_state->getValue('task_household');
      if ($selected !== NULL && $selected !== '') {
        $input['household_id'] = ctype_digit((string) $selected) ? (int) $selected : -1;
      }
      if ($input['due_mode'] === PersonalTask::DUE_DATE) {
        $input['due_date'] = trim((string) $form_state->getValue('task_due_date'));
      }
      elseif ($input['due_mode'] === PersonalTask::DUE_DATE_TIME) {
        $input['due_at'] = trim((string) $form_state->getValue('task_due_at'));
      }
    }
    elseif ($intent === ThreeIntentReadProposeService::PROPOSE_ACTIVITY) {
      $date = trim((string) $form_state->getValue('activity_date'));
      $parts = explode('-', $date);
      $validDate = count($parts) === 3
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
        && checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
      $mode = (string) $form_state->getValue('activity_mode');
      if (!in_array($mode, ['timed', 'all_day'], TRUE)) {
        $this->show($form_state, [
          'status' => 'NEEDS_CLARIFICATION', 'data' => NULL,
          'clarifications' => ['time_mode_conflict'], 'saved' => FALSE,
        ]);
        return;
      }
      $allDay = $mode === 'all_day';
      try {
        $extraction = ActivityCaptureExtraction::fromArray([
          'label_text' => trim((string) $form_state->getValue('activity_label')),
          'location_text' => NULL,
          'concerned_person_mentions' => [],
          'unclassified_person_mentions' => [],
          'concerned_person_alternative' => FALSE,
          'responsibility_candidate' => $form_state->getValue('activity_responsible_self')
            ? ActivityCaptureExtraction::RESPONSIBILITY_SELF : NULL,
          'date_expression' => $validDate ? NULL : ($date !== '' ? $date : NULL),
          'date_day' => $validDate ? (int) $parts[2] : NULL,
          'date_month' => $validDate ? (int) $parts[1] : NULL,
          'date_year' => $validDate ? (int) $parts[0] : NULL,
          'relative_day_offset' => NULL,
          'start_time_expression' => $allDay ? NULL : trim((string) $form_state->getValue('activity_start')),
          'end_time_expression' => $allDay ? NULL : trim((string) $form_state->getValue('activity_end')),
          'recurrence_expression' => NULL,
          'explicit_all_day_signal' => $allDay,
          'explicit_timed_signal' => !$allDay,
        ]);
        $input = [
          'context' => new ActivityCaptureInput(
            'Explicit structured form; no language interpretation',
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            (string) $today['timezone'],
          ),
          'extraction' => $extraction,
        ];
      }
      catch (InvalidArgumentException) {
        $this->show($form_state, [
          'status' => 'NEEDS_CLARIFICATION', 'data' => NULL,
          'clarifications' => ['invalid_structured_fields'], 'saved' => FALSE,
        ]);
        return;
      }
    }
    $this->show($form_state, $this->capabilities->handle($intent, $input));
  }

  /**
   * @param array<string, mixed> $result
   */
  private function show(FormStateInterface $form_state, array $result): void {
    $form_state->set('guided_result', $result);
    $form_state->setRebuild();
  }

  /**
   * @param array<string, mixed> $result
   * @return array<string, mixed>
   */
  private function resultBuild(array $result): array {
    $status = (string) ($result['status'] ?? 'UNAVAILABLE');
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ps-guided-assistant__result'],
        'role' => 'status',
        'aria-live' => 'polite',
      ],
      'title' => [
        '#type' => 'html_tag', '#tag' => 'h2',
        '#value' => $this->tr('Preview result', 'Résultat de l’aperçu'),
      ],
      'status' => [
        '#type' => 'html_tag', '#tag' => 'p',
        '#value' => $this->t('Status: @status', ['@status' => $status]),
      ],
      'notice' => [
        '#type' => 'html_tag', '#tag' => 'p',
        '#value' => $this->tr('Preview not saved — no task or activity created.',
          'Aperçu non enregistré — aucune tâche ni activité créée.'),
      ],
    ];
    foreach (($result['clarifications'] ?? []) as $delta => $reason) {
      $build['clarification_' . $delta] = [
        '#type' => 'html_tag', '#tag' => 'p',
        '#value' => $this->explain((string) $reason),
      ];
    }
    if ($status === 'READ_SUCCESS' && is_array($result['data'] ?? NULL)) {
      $data = $result['data'];
      $build['date'] = $this->fact($this->t('Today: @date (@timezone)', [
        '@date' => (string) $data['local_date'],
        '@timezone' => (string) $data['timezone'],
      ]));
      foreach (['tasks', 'preparations', 'planning'] as $kind) {
        $build[$kind . '_heading'] = [
          '#type' => 'html_tag', '#tag' => 'h3',
          '#value' => match ($kind) {
            'tasks' => $this->tr('Tasks', 'Tâches'),
            'preparations' => $this->tr('Preparations', 'Préparatifs'),
            default => $this->tr('Planning', 'Planning'),
          },
        ];
        if ($data[$kind] === []) {
          $build[$kind . '_empty'] = $this->fact($this->tr('No items for this section.', 'Aucun élément pour cette section.'));
        }
        foreach ($data[$kind] as $index => $item) {
          $label = $kind === 'tasks' ? $item['title']
            : ($kind === 'preparations' ? $item['instruction'] : $item['title']);
          $extra = $kind === 'planning'
            ? ($item['kind'] === 'external_event'
              ? $this->tr(' — external data stored locally; freshness not verified.',
                ' — données externes enregistrées localement ; actualisation non vérifiée.')
              : $this->tr(' — local activity.', ' — activité locale.'))
            : '';
          $build[$kind . '_' . $index] = $this->fact($this->t('@label@extra', [
            '@label' => (string) $label, '@extra' => (string) $extra,
          ]));
        }
      }
    }
    elseif ($status === 'PROPOSAL_READY' && is_array($result['data'] ?? NULL)) {
      $data = $result['data'];
      $build['proposal'] = $this->fact($this->t('@name', [
        '@name' => (string) ($data['title'] ?? $data['label'] ?? ''),
      ]));
      $date = (string) ($data['due_date'] ?? $data['due_at'] ?? $data['absolute_date'] ?? '');
      if ($date !== '') {
        $build['when'] = $this->fact($this->t('@date', ['@date' => $date]));
      }
      if (isset($data['local_start_time'], $data['local_end_time'])) {
        $build['time'] = $this->fact($this->t('@start – @end', [
          '@start' => (string) $data['local_start_time'],
          '@end' => (string) $data['local_end_time'],
        ]));
      }
    }
    return $build;
  }

  private function fact(string|TranslatableMarkup $text): array {
    return ['#type' => 'html_tag', '#tag' => 'p', '#value' => $text];
  }

  private function backLink(): array {
    return [
      '#type' => 'link',
      '#title' => $this->tr('Back to Today', 'Retour à Aujourd’hui'),
      '#url' => Url::fromRoute('personal_secretary.today'),
      '#attributes' => ['class' => ['ps-guided-assistant__back']],
    ];
  }

  private function explain(string $reason): TranslatableMarkup {
    return match ($reason) {
      'title_required_or_invalid', 'label_required' => $this->tr('Enter a valid title.', 'Indiquez un intitulé valide.'),
      'date_required', 'date_unresolved', 'due_date_required_or_invalid' => $this->tr('Choose a valid date.', 'Choisissez une date valide.'),
      'due_at_required_or_invalid' => $this->tr('Enter a valid date and time.', 'Indiquez une date et une heure valides.'),
      'start_time_invalid' => $this->tr('Enter a start time (HH:MM).', 'Indiquez une heure de début (HH:MM).'),
      'end_time_required', 'end_time_invalid', 'end_time_after_start_required' => $this->tr('Enter an end time after the start.', 'Indiquez une heure de fin après le début.'),
      'household_selection_required' => $this->tr('Choose a Household.', 'Choisissez un foyer.'),
      'authorized_scope_required', 'authorized_today_scope_required',
      'authorized_task_scope_required', 'authorized_activity_scope_required',
      'household_scope_conflict' => $this->tr('Your account cannot access this scope.', 'Votre compte ne peut pas accéder à ce contexte.'),
      default => $this->t('More information is required (@code).', ['@code' => $reason]),
    };
  }

  /**
   * Localized source strings within the exact five-file UI authorization.
   */
  private function tr(string $english, string $french): TranslatableMarkup {
    return $this->interfaceLanguage->getCurrentLanguage()->getId() === 'fr'
      ? $this->t($french)
      : $this->t($english);
  }

}
