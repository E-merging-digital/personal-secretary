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
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Value\ActivityCaptureProposal;
use Drupal\personal_secretary\Service\ActivityCaptureResolver;
use Drupal\personal_secretary\Service\CurrentUserActivityCreationService;
use Drupal\personal_secretary\Service\CurrentUserPreparationService;
use Drupal\personal_secretary\Service\PreparationCompletionService;
use Drupal\personal_secretary\Service\ThreeIntentReadProposeService;
use Drupal\personal_secretary\Service\PersonalTaskMutationService;
use Drupal\personal_secretary\Service\PersonalTaskQueryService;
use Drupal\personal_secretary\Service\TodayService;
use Drupal\personal_secretary\Value\ActivityCaptureExtraction;
use Drupal\personal_secretary\Value\ActivityCaptureInput;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Optional structured assistant with explicit human-confirmed local actions.
 */
final class GuidedAssistantForm extends FormBase {

  private const MARK_PREPARATION = 'MARK_PREPARATION';
  private const COMPLETE_TASK = 'COMPLETE_TASK';
  private const EDIT_TASK = 'EDIT_TASK';
  private const KEEP_DUE = 'UNCHANGED';

  public function __construct(
    protected TodayService $today,
    protected CurrentUserActivityCreationService $activityCreation,
    protected ActivityCaptureResolver $captureResolver,
    protected LanguageManagerInterface $interfaceLanguage,
    protected PersonalTaskMutationService $taskMutations,
    protected PersonalTaskQueryService $taskQuery,
    protected CurrentUserPreparationService $currentUserPreparations,
    protected PreparationCompletionService $preparationCompletion,
  ) {
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.today'),
      $container->get('personal_secretary.current_user_activity_creation'),
      $container->get('personal_secretary.activity_capture_resolver'),
      $container->get('language_manager'),
      $container->get('personal_secretary.personal_task_mutation'),
      $container->get('personal_secretary.personal_task_query'),
      $container->get('personal_secretary.current_user_preparation'),
      $container->get('personal_secretary.preparation_completion'),
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
        'Choose an action. The assistant does not interpret free text; creating or editing a task, creating an activity, marking a preparation done, or completing a task requires explicit confirmation.',
        'Choisissez une action. L’assistant n’interprète pas de texte libre ; créer ou modifier une tâche, créer une activité, marquer un préparatif comme fait ou terminer une tâche exige une confirmation explicite.',
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
        ThreeIntentReadProposeService::GET_TOMORROW => $this->tr('View tomorrow', 'Préparer demain'),
        ThreeIntentReadProposeService::PROPOSE_TASK => $this->tr('Prepare a task', 'Préparer une tâche'),
        ThreeIntentReadProposeService::PROPOSE_ACTIVITY => $this->tr('Prepare an activity', 'Préparer une activité'),
        self::MARK_PREPARATION => $this->tr('Mark a preparation done', 'Marquer un préparatif comme fait'),
        self::COMPLETE_TASK => $this->tr('Complete an existing task', 'Terminer une tâche existante'),
        self::EDIT_TASK => $this->tr('Edit an existing task', 'Modifier une tâche existante'),
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
    $form['activity']['activity_recurrence'] = [
      '#type' => 'select',
      '#title' => $this->tr('Repeat', 'Répétition'),
      '#options' => [
        ActivityCaptureProposal::INTENT_ONE_OFF => $this->tr('One-off', 'Ponctuelle'),
        ActivityCaptureProposal::INTENT_WEEKLY => $this->tr('Every week', 'Chaque semaine'),
      ],
      '#default_value' => ActivityCaptureProposal::INTENT_ONE_OFF,
    ];
    $form['activity']['activity_weekday'] = [
      '#type' => 'select',
      '#title' => $this->tr('Weekday of the first activity', 'Jour de la semaine de la première activité'),
      '#options' => [
        '' => $this->tr('Choose a weekday', 'Choisir un jour'),
        'MONDAY' => $this->tr('Monday', 'Lundi'),
        'TUESDAY' => $this->tr('Tuesday', 'Mardi'),
        'WEDNESDAY' => $this->tr('Wednesday', 'Mercredi'),
        'THURSDAY' => $this->tr('Thursday', 'Jeudi'),
        'FRIDAY' => $this->tr('Friday', 'Vendredi'),
        'SATURDAY' => $this->tr('Saturday', 'Samedi'),
        'SUNDAY' => $this->tr('Sunday', 'Dimanche'),
      ],
      '#default_value' => '',
      '#states' => ['visible' => [':input[name="activity_recurrence"]' => ['value' => ActivityCaptureProposal::INTENT_WEEKLY]]],
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

    $form['preparation'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ps-guided-assistant__fields']],
      '#states' => ['visible' => [':input[name="intent"]' => ['value' => self::MARK_PREPARATION]]],
    ];
    try {
      $choices = $this->preparationChoices();
      if ($choices === []) {
        $form['preparation']['empty'] = $this->fact($this->tr(
          'No pending preparation is due or overdue in the next 30 days.',
          'Aucun préparatif non fait n’est en retard ou à échéance dans les 30 prochains jours.',
        ));
      }
      else {
        $options = ['' => $this->tr('Choose a pending preparation', 'Choisir un préparatif à faire')];
        foreach ($choices as $key => $item) {
          $options[$key] = $this->preparationLabel($item);
        }
        $form['preparation']['preparation_choice'] = [
          '#type' => 'select',
          '#title' => $this->tr('Preparation to mark done', 'Préparatif à marquer comme fait'),
          '#options' => $options,
          '#default_value' => '',
        ];
      }
    }
    catch (InvalidArgumentException|RuntimeException) {
      $form['preparation']['unavailable'] = $this->fact($this->tr(
        'Preparations are currently unavailable. No change can be made.',
        'Les préparatifs sont momentanément indisponibles. Aucune modification ne peut être effectuée.',
      ));
    }
    $form['preparation']['all'] = [
      '#type' => 'link',
      '#title' => $this->tr('View My preparations', 'Voir Mes préparatifs'),
      '#url' => Url::fromRoute('personal_secretary.my_preparations'),
    ];

    $form['complete_task'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ps-guided-assistant__fields']],
      '#states' => ['visible' => [':input[name="intent"]' => ['value' => self::COMPLETE_TASK]]],
    ];
    try {
      $choices = $this->taskCompletionChoices($scope);
      if ($choices === []) {
        $form['complete_task']['empty'] = $this->fact($this->tr(
          'You have no open task to complete.',
          'Vous n’avez aucune tâche ouverte à terminer.',
        ));
      }
      else {
        $options = ['' => $this->tr('Choose an open task', 'Choisir une tâche ouverte')];
        foreach ($choices as $key => $item) {
          $options[$key] = $this->taskCompletionLabel($item);
        }
        $form['complete_task']['complete_task_choice'] = [
          '#type' => 'select',
          '#title' => $this->tr('Task to complete', 'Tâche à terminer'),
          '#options' => $options,
          '#default_value' => '',
        ];
      }
    }
    catch (InvalidArgumentException|RuntimeException) {
      $form['complete_task']['unavailable'] = $this->fact($this->tr(
        'Open tasks are currently unavailable. No change can be made.',
        'Les tâches ouvertes sont momentanément indisponibles. Aucune modification ne peut être effectuée.',
      ));
    }
    $form['complete_task']['all'] = [
      '#type' => 'link',
      '#title' => $this->tr('View My tasks', 'Voir Mes tâches'),
      '#url' => Url::fromRoute('personal_secretary.my_tasks'),
    ];

    $form['edit_task'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ps-guided-assistant__fields']],
      '#states' => ['visible' => [':input[name="intent"]' => ['value' => self::EDIT_TASK]]],
    ];
    try {
      $choices = $this->taskCompletionChoices($scope);
      if ($choices === []) {
        $form['edit_task']['empty'] = $this->fact($this->tr(
          'You have no open task to edit.',
          'Vous n’avez aucune tâche ouverte à modifier.',
        ));
      }
      else {
        $options = ['' => $this->tr('Choose an open task', 'Choisir une tâche ouverte')];
        foreach ($choices as $key => $item) {
          $options[$key] = $this->taskCompletionLabel($item);
        }
        $form['edit_task']['edit_task_choice'] = [
          '#type' => 'select',
          '#title' => $this->tr('Task to edit', 'Tâche à modifier'),
          '#options' => $options,
          '#default_value' => '',
        ];
        $form['edit_task']['edit_task_title'] = [
          '#type' => 'textfield',
          '#title' => $this->tr('New title (leave blank to keep the current title)', 'Nouvel intitulé (laisser vide pour conserver l’actuel)'),
          '#maxlength' => 255,
        ];
        $form['edit_task']['edit_task_due_mode'] = [
          '#type' => 'select',
          '#title' => $this->tr('New deadline', 'Nouvelle échéance'),
          '#options' => [
            self::KEEP_DUE => $this->tr('Keep current deadline', 'Conserver l’échéance actuelle'),
            PersonalTask::DUE_NONE => $this->tr('No deadline', 'Sans échéance'),
            PersonalTask::DUE_DATE => $this->tr('Date', 'Date'),
            PersonalTask::DUE_DATE_TIME => $this->tr('Date and time', 'Date et heure'),
          ],
          '#default_value' => self::KEEP_DUE,
        ];
        $form['edit_task']['edit_task_due_date'] = [
          '#type' => 'date',
          '#title' => $this->tr('New due date', 'Nouvelle date d’échéance'),
          '#states' => ['visible' => [':input[name="edit_task_due_mode"]' => ['value' => PersonalTask::DUE_DATE]]],
        ];
        $form['edit_task']['edit_task_due_at'] = [
          '#type' => 'textfield',
          '#maxlength' => 16,
          '#title' => $this->tr('New due date and time (YYYY-MM-DD HH:MM)', 'Nouvelle échéance (AAAA-MM-JJ HH:MM)'),
          '#description' => $this->t('@timezone', ['@timezone' => $this->taskMutations->currentUserTimezoneId()]),
          '#states' => ['visible' => [':input[name="edit_task_due_mode"]' => ['value' => PersonalTask::DUE_DATE_TIME]]],
        ];
      }
    }
    catch (InvalidArgumentException|RuntimeException) {
      $form['edit_task']['unavailable'] = $this->fact($this->tr(
        'Open tasks are currently unavailable. No change can be made.',
        'Les tâches ouvertes sont momentanément indisponibles. Aucune modification ne peut être effectuée.',
      ));
    }
    $form['edit_task']['all'] = [
      '#type' => 'link',
      '#title' => $this->tr('View My tasks', 'Voir Mes tâches'),
      '#url' => Url::fromRoute('personal_secretary.my_tasks'),
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
      $form['result'] = $this->resultBuild($result, $scope);
      if ($this->taskEditReviewContextValid($result, $form_state->get('task_edit_review'), $scope)) {
        $form['task_edit_confirmation'] = [
          '#type' => 'container',
          'notice' => $this->fact($this->tr(
            'Saving will change only the reviewed task title and/or deadline. Check the exact before/after values above.',
            'Enregistrer modifiera uniquement l’intitulé et/ou l’échéance de la tâche vérifiée. Contrôlez les valeurs avant/après ci-dessus.',
          )),
          'actions' => [
            '#type' => 'actions',
            'confirm' => [
              '#type' => 'submit',
              '#name' => 'confirm_edit_existing_task',
              '#value' => $this->tr('Save this change', 'Enregistrer cette modification'),
              '#button_type' => 'primary',
            ],
          ],
        ];
      }
      if ($this->taskCompletionReviewContextValid($result, $form_state->get('task_completion_review'), $scope)) {
        $form['task_completion_confirmation'] = [
          '#type' => 'container',
          'notice' => $this->fact($this->tr(
            'Complete this task will save one local status change. Check the exact task above before confirming.',
            'Terminer cette tâche enregistrera un changement de statut local. Vérifiez la tâche exacte ci-dessus avant de confirmer.',
          )),
          'actions' => [
            '#type' => 'actions',
            'confirm' => [
              '#type' => 'submit',
              '#name' => 'confirm_complete_existing_task',
              '#value' => $this->tr('Complete this task', 'Terminer cette tâche'),
              '#button_type' => 'primary',
            ],
          ],
        ];
      }
      if ($this->preparationReviewContextValid($result, $form_state->get('preparation_review'), $scope)) {
        $form['preparation_confirmation'] = [
          '#type' => 'container',
          'notice' => $this->fact($this->tr(
            'Mark this preparation as done will save one local completion. Check the exact preparation above before confirming.',
            'Marquer ce préparatif comme fait enregistrera une seule réalisation locale. Vérifiez le préparatif exact ci-dessus avant de confirmer.',
          )),
          'actions' => [
            '#type' => 'actions',
            'confirm' => [
              '#type' => 'submit',
              '#name' => 'confirm_mark_preparation',
              '#value' => $this->tr('Mark this preparation as done', 'Marquer ce préparatif comme fait'),
              '#button_type' => 'primary',
            ],
          ],
        ];
      }
      if ($this->taskReviewContextValid($result, $form_state->get('task_review'), $scope)) {
        $form['task_confirmation'] = [
          '#type' => 'container',
          'notice' => $this->fact($this->tr(
            'Create this task will save one real local task. Review the details above before confirming.',
            'Créer cette tâche enregistrera une véritable tâche locale. Vérifiez les détails ci-dessus avant de confirmer.',
          )),
          'actions' => [
            '#type' => 'actions',
            'confirm' => [
              '#type' => 'submit',
              '#name' => 'confirm_create_task',
              '#value' => $this->tr('Create this task', 'Créer cette tâche'),
              '#button_type' => 'primary',
            ],
          ],
        ];
      }
      if ($this->activityReviewContextValid($result, $form_state->get('activity_review'), $scope)) {
        $form['activity_confirmation'] = [
          '#type' => 'container',
          'notice' => $this->fact($this->tr(
            'Create this activity will save one real local one-off activity. Review the details above before confirming.',
            'Créer cette activité enregistrera une véritable activité ponctuelle locale. Vérifiez les détails ci-dessus avant de confirmer.',
          )),
          'actions' => [
            '#type' => 'actions',
            'confirm' => [
              '#type' => 'submit',
              '#name' => 'confirm_create_activity',
              '#value' => $this->tr('Create this activity', 'Créer cette activité'),
              '#button_type' => 'primary',
            ],
          ],
        ];
      }
      if ($this->activityReviewContextValid($result, $form_state->get('activity_review'), $scope, ActivityCaptureProposal::INTENT_WEEKLY)) {
        $form['weekly_confirmation'] = [
          '#type' => 'container',
          'notice' => $this->fact($this->tr(
            'Create this weekly activity will save a recurring local activity without a predefined end date. Review the details above before confirming.',
            'Créer cette activité hebdomadaire enregistrera une activité locale récurrente sans date de fin prédéfinie. Vérifiez les détails ci-dessus avant de confirmer.',
          )),
          'actions' => [
            '#type' => 'actions',
            'confirm' => [
              '#type' => 'submit',
              '#name' => 'confirm_create_weekly_activity',
              '#value' => $this->tr('Create this weekly activity', 'Créer cette activité hebdomadaire'),
              '#button_type' => 'primary',
            ],
          ],
        ];
      }
    }
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement()['#name'] ?? '';
    $confirm = $trigger === 'confirm_create_task';
    $confirmActivity = $trigger === 'confirm_create_activity';
    $confirmWeekly = $trigger === 'confirm_create_weekly_activity';
    $confirmPreparation = $trigger === 'confirm_mark_preparation';
    $confirmExistingTask = $trigger === 'confirm_complete_existing_task';
    $confirmEditTask = $trigger === 'confirm_edit_existing_task';
    try {
      $scope = $this->activityCreation->creationScope();
      $today = $this->today->today();
      if ($scope['households'] === []) {
        throw new InvalidArgumentException('No eligible Household.');
      }
    }
    catch (InvalidArgumentException|RuntimeException) {
      $form_state->set('task_review', NULL);
      $form_state->set('activity_review', NULL);
      $form_state->set('preparation_review', NULL);
      $form_state->set('task_completion_review', NULL);
      $form_state->set('task_edit_review', NULL);
      $this->show($form_state, [
        'status' => 'ACCESS_DENIED', 'data' => NULL,
        'clarifications' => ['authorized_scope_required'], 'saved' => FALSE,
      ]);
      return;
    }

    $intent = (string) $form_state->getValue('intent');
    $capabilities = new ThreeIntentReadProposeService(
      $this->today, $this->activityCreation, $this->captureResolver,
    );
    if ($confirmEditTask) {
      $review = $form_state->get('task_edit_review');
      $previous = $form_state->get('guided_result');
      if ($intent !== self::EDIT_TASK
        || !$this->taskEditReviewContextValid($previous, $review, $scope)
        || $this->taskEditFields($form_state) !== $review['fields']
        || (string) $form_state->getValue('edit_task_choice') !== $review['choice']) {
        $this->rejectTaskEditConfirmation($form_state);
        return;
      }
      try {
        $choices = $this->taskCompletionChoices($scope);
        $item = $choices[$review['choice']] ?? NULL;
        if (!is_array($item)) {
          throw new InvalidArgumentException('The reviewed task is no longer eligible.');
        }
        $before = $this->taskEditSnapshot($item);
        if ($before !== $review['before']
          || $this->taskEditProposal($form_state, $before, $review['timezone']) !== $review['after']) {
          throw new InvalidArgumentException('The reviewed task or proposal changed.');
        }
        // Consume the exact server-side review before the sole governed write.
        $form_state->set('task_edit_review', NULL);
        $after = $review['after'];
        $this->taskMutations->editTask(
          (int) $before['id'],
          $after['title'],
          $after['due_mode'],
          $after['due_mode'] === PersonalTask::DUE_DATE ? $after['due_date'] : NULL,
          $after['due_mode'] === PersonalTask::DUE_DATE_TIME ? $after['due_local'] : NULL,
        );
      }
      catch (\Exception) {
        $this->rejectTaskEditConfirmation($form_state);
        return;
      }
      $this->messenger()->addStatus($this->tr('Task updated.', 'Tâche modifiée.'));
      $form_state->setRedirect('personal_secretary.my_tasks');
      return;
    }

    if ($confirmExistingTask) {
      $review = $form_state->get('task_completion_review');
      $previous = $form_state->get('guided_result');
      if ($intent !== self::COMPLETE_TASK
        || !$this->taskCompletionReviewContextValid($previous, $review, $scope)
        || (string) $form_state->getValue('complete_task_choice') !== $review['choice']) {
        $this->rejectTaskCompletionConfirmation($form_state);
        return;
      }
      try {
        $choices = $this->taskCompletionChoices($scope);
        $item = $choices[$review['choice']] ?? NULL;
        if (!is_array($item)
          || $this->taskCompletionSummary($item) !== $review['summary']) {
          throw new InvalidArgumentException('The reviewed task changed.');
        }
        $taskId = (int) $review['summary']['id'];
        // Re-check domain authorization and open status at the write boundary.
        $this->taskMutations->requireCurrentTask($taskId);
        $form_state->set('task_completion_review', NULL);
        if (!$this->taskMutations->completeTask($taskId)) {
          throw new InvalidArgumentException('The task was already completed.');
        }
      }
      catch (InvalidArgumentException|RuntimeException) {
        $this->rejectTaskCompletionConfirmation($form_state);
        return;
      }
      $this->messenger()->addStatus($this->tr(
        'Task completed.', 'Tâche terminée.',
      ));
      $form_state->setRedirect('personal_secretary.my_tasks');
      return;
    }

    if ($confirmPreparation) {
      $review = $form_state->get('preparation_review');
      $previous = $form_state->get('guided_result');
      if ($intent !== self::MARK_PREPARATION
        || !$this->preparationReviewContextValid($previous, $review, $scope)
        || (string) $form_state->getValue('preparation_choice') !== $review['choice']) {
        $this->rejectPreparationConfirmation($form_state);
        return;
      }
      try {
        $choices = $this->preparationChoices();
        $item = $choices[$review['choice']] ?? NULL;
        if (!is_array($item)
          || $this->preparationSummary($item) !== $review['summary']
          || $this->preparationTarget($item) !== $review['target']) {
          throw new InvalidArgumentException('The reviewed preparation is stale.');
        }
        // Revalidate exact current-user eligibility, identity and state.
        [$seriesId, $occurrenceKey, $requirementId] = $review['target'];
        $current = $this->preparationCompletion->describeCurrentPreparation(
          $seriesId, $occurrenceKey, $requirementId,
        );
        if ($current['prepared'] || $current['instruction'] !== $review['summary']['instruction']) {
          throw new InvalidArgumentException('The reviewed preparation is no longer pending.');
        }
        // Consume the review before the sole governed domain mutation.
        $form_state->set('preparation_review', NULL);
        if (!$this->preparationCompletion->markPrepared(
          $seriesId, $occurrenceKey, $requirementId,
        )) {
          throw new InvalidArgumentException('The preparation was already marked done.');
        }
      }
      catch (InvalidArgumentException|RuntimeException) {
        $this->rejectPreparationConfirmation($form_state);
        return;
      }
      $this->messenger()->addStatus($this->tr(
        'Preparation marked done.', 'Préparatif marqué comme fait.',
      ));
      $form_state->setRedirect('personal_secretary.my_preparations');
      return;
    }

    if ($confirm) {
      $review = $form_state->get('task_review');
      $previous = $form_state->get('guided_result');
      if ($intent !== ThreeIntentReadProposeService::PROPOSE_TASK
        || !$this->taskReviewContextValid($previous, $review, $scope)
        || $review['fields'] !== $this->taskFields($form_state)) {
        $this->rejectTaskConfirmation($form_state);
        return;
      }
      // Re-evaluate the proposal and the current authorization before WRITE.
      $fresh = $capabilities->handle($intent, $this->taskInput($form_state));
      if ($fresh['status'] !== 'PROPOSAL_READY' || $fresh['saved'] !== FALSE
        || $fresh['data'] !== $review['proposal']) {
        $this->rejectTaskConfirmation($form_state);
        return;
      }
      $data = $fresh['data'];
      try {
        $task = $this->taskMutations->createTask(
          (string) $data['title'],
          (int) $data['household_id'],
          (string) $data['due_mode'],
          $data['due_date'],
          $data['due_at'],
        );
        if ($task->id() === NULL) {
          throw new RuntimeException('A saved PersonalTask must have an ID.');
        }
      }
      catch (InvalidArgumentException|RuntimeException) {
        $this->rejectTaskConfirmation($form_state);
        return;
      }
      $form_state->set('task_review', NULL);
      $this->messenger()->addStatus($this->tr(
        'Task created.', 'Tâche créée.',
      ));
      $form_state->setRedirect('personal_secretary.my_tasks');
      return;
    }

    if ($confirmActivity || $confirmWeekly) {
      $expectedIntent = $confirmWeekly ? ActivityCaptureProposal::INTENT_WEEKLY : ActivityCaptureProposal::INTENT_ONE_OFF;
      $review = $form_state->get('activity_review');
      $previous = $form_state->get('guided_result');
      if ($intent !== ThreeIntentReadProposeService::PROPOSE_ACTIVITY
        || !$this->activityReviewContextValid($previous, $review, $scope, $expectedIntent)
        || $review['fields'] !== $this->activityFields($form_state)
        || $review['timezone'] !== (string) $today['timezone']) {
        $this->rejectActivityConfirmation($form_state);
        return;
      }
      try {
        // A new read-only proposal must exactly match the reviewed one.
        $fresh = $capabilities->handle($intent, $this->activityInput($form_state, (string) $today['timezone']));
        if ($fresh['status'] !== 'PROPOSAL_READY' || $fresh['saved'] !== FALSE
          || $fresh['data'] !== $review['proposal']) {
          $this->rejectActivityConfirmation($form_state);
          return;
        }
        $data = $fresh['data'];
        $timezone = new DateTimeZone((string) $data['source_timezone']);
        $date = (string) $data['absolute_date'];
        if ($data['time_mode'] === ActivitySeries::TIME_MODE_ALL_DAY) {
          $start = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
          $end = $start instanceof DateTimeImmutable ? $start->modify('+1 day') : FALSE;
          $expectedStart = $date;
          $format = 'Y-m-d';
        }
        else {
          $expectedStart = $date . ' ' . (string) $data['local_start_time'];
          $expectedEnd = $date . ' ' . (string) $data['local_end_time'];
          $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $expectedStart, $timezone);
          $end = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $expectedEnd, $timezone);
          $format = 'Y-m-d H:i';
        }
        if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable
          || $start->format($format) !== $expectedStart
          || ($data['time_mode'] === ActivitySeries::TIME_MODE_TIMED && $end->format($format) !== $expectedEnd)
          || $end <= $start) {
          throw new InvalidArgumentException('Reviewed local activity date/time is invalid.');
        }
        // Consume the Form API review before the sole domain mutation.
        $form_state->set('activity_review', NULL);
        $series = $this->activityCreation->{$confirmWeekly ? 'addWeeklyActivity' : 'addOneOffActivity'}(
          (int) $review['person_id'],
          (int) $data['household_id'],
          (string) $data['label'],
          $start,
          $end,
          '',
          0,
          '',
          [],
          (string) $data['time_mode'],
        );
        if ($series->id() === NULL) {
          throw new RuntimeException('A saved ActivitySeries must have an ID.');
        }
      }
      catch (\Exception) {
        $this->rejectActivityConfirmation($form_state);
        return;
      }
      $this->messenger()->addStatus($confirmWeekly
        ? $this->tr('Weekly activity created.', 'Activité hebdomadaire créée.')
        : $this->tr('Activity created.', 'Activité créée.'));
      $form_state->setRedirect('personal_secretary.my_upcoming');
      return;
    }

    // Preview is always read-only, and supersedes any earlier confirmation.
    $form_state->set('task_review', NULL);
    $form_state->set('activity_review', NULL);
    $form_state->set('preparation_review', NULL);
    $form_state->set('task_completion_review', NULL);
    $form_state->set('task_edit_review', NULL);
    if ($intent === self::EDIT_TASK) {
      try {
        $choice = (string) $form_state->getValue('edit_task_choice');
        $choices = $this->taskCompletionChoices($scope);
        $item = $choices[$choice] ?? NULL;
        if (!is_array($item)) {
          throw new InvalidArgumentException('choose_open_task');
        }
        $before = $this->taskEditSnapshot($item);
        $timezone = $this->taskMutations->currentUserTimezoneId();
        $after = $this->taskEditProposal($form_state, $before, $timezone);
        $changed = $this->taskEditChanged($before, $after);
        $result = [
          'intent' => self::EDIT_TASK,
          'status' => 'PROPOSAL_READY',
          'data' => ['before' => $before, 'after' => $after, 'timezone' => $timezone, 'changed' => $changed],
          'clarifications' => [],
          'saved' => FALSE,
        ];
        if ($changed) {
          $form_state->set('task_edit_review', [
            'choice' => $choice,
            'fields' => $this->taskEditFields($form_state),
            'before' => $before,
            'after' => $after,
            'timezone' => $timezone,
            'user_id' => (int) $this->currentUser()->id(),
            'person_id' => (int) $scope['current_person']->id(),
            'household_ids' => $this->householdIds($scope),
            'expires_at' => \Drupal::time()->getCurrentTime() + 600,
          ]);
        }
      }
      catch (\Exception $e) {
        $reason = in_array($e->getMessage(), ['choose_open_task', 'invalid_edit_title', 'invalid_edit_due_mode', 'invalid_edit_due_date', 'invalid_edit_due_at'], TRUE)
          ? $e->getMessage() : 'fresh_task_edit_preview_required';
        $result = [
          'intent' => self::EDIT_TASK,
          'status' => 'NEEDS_CLARIFICATION',
          'data' => NULL,
          'clarifications' => [$reason],
          'saved' => FALSE,
        ];
      }
      $this->show($form_state, $result);
      return;
    }
    if ($intent === self::COMPLETE_TASK) {
      try {
        $choice = (string) $form_state->getValue('complete_task_choice');
        $choices = $this->taskCompletionChoices($scope);
        $item = $choices[$choice] ?? NULL;
        if (!is_array($item)) {
          throw new InvalidArgumentException('No open task was selected.');
        }
        $summary = $this->taskCompletionSummary($item);
        $review = [
          'choice' => $choice,
          'summary' => $summary,
          'user_id' => (int) $this->currentUser()->id(),
          'person_id' => (int) $scope['current_person']->id(),
          'household_ids' => $this->householdIds($scope),
          'expires_at' => \Drupal::time()->getCurrentTime() + 600,
        ];
        $result = [
          'intent' => self::COMPLETE_TASK,
          'status' => 'PROPOSAL_READY',
          'data' => $summary,
          'clarifications' => [],
          'saved' => FALSE,
        ];
        $form_state->set('task_completion_review', $review);
      }
      catch (InvalidArgumentException|RuntimeException) {
        $result = [
          'intent' => self::COMPLETE_TASK,
          'status' => 'NEEDS_CLARIFICATION',
          'data' => NULL,
          'clarifications' => ['choose_open_task'],
          'saved' => FALSE,
        ];
      }
      $this->show($form_state, $result);
      return;
    }
    if ($intent === self::MARK_PREPARATION) {
      try {
        $choice = (string) $form_state->getValue('preparation_choice');
        $choices = $this->preparationChoices();
        $item = $choices[$choice] ?? NULL;
        if (!is_array($item)) {
          throw new InvalidArgumentException('No pending preparation was selected.');
        }
        $summary = $this->preparationSummary($item);
        $review = [
          'choice' => $choice,
          'summary' => $summary,
          'target' => $this->preparationTarget($item),
          'user_id' => (int) $this->currentUser()->id(),
          'person_id' => (int) $scope['current_person']->id(),
          'household_ids' => $this->householdIds($scope),
          'expires_at' => \Drupal::time()->getCurrentTime() + 600,
        ];
        $result = [
          'intent' => self::MARK_PREPARATION,
          'status' => 'PROPOSAL_READY',
          'data' => $summary,
          'clarifications' => [],
          'saved' => FALSE,
        ];
        $form_state->set('preparation_review', $review);
      }
      catch (InvalidArgumentException|RuntimeException) {
        $result = [
          'intent' => self::MARK_PREPARATION,
          'status' => 'NEEDS_CLARIFICATION',
          'data' => NULL,
          'clarifications' => ['choose_pending_preparation'],
          'saved' => FALSE,
        ];
      }
      $this->show($form_state, $result);
      return;
    }
    $input = [];
    if ($intent === ThreeIntentReadProposeService::PROPOSE_TASK) {
      $input = $this->taskInput($form_state);
    }
    elseif ($intent === ThreeIntentReadProposeService::PROPOSE_ACTIVITY) {
      try {
        $input = $this->activityInput($form_state, (string) $today['timezone']);
      }
      catch (InvalidArgumentException $e) {
        $this->show($form_state, [
          'intent' => $intent,
          'status' => 'NEEDS_CLARIFICATION', 'data' => NULL,
          'clarifications' => [$e->getMessage()], 'saved' => FALSE,
        ]);
        return;
      }
    }
    $result = $capabilities->handle($intent, $input);
    if ($intent === ThreeIntentReadProposeService::PROPOSE_TASK
      && $result['status'] === 'PROPOSAL_READY'
      && $result['saved'] === FALSE
      && is_array($result['data'])) {
      $data = $result['data'];
      $household = $scope['households'][(int) $data['household_id']] ?? NULL;
      if ($household !== NULL) {
        $form_state->set('task_review', [
          'fields' => $this->taskFields($form_state),
          'proposal' => $data,
          'user_id' => (int) $this->currentUser()->id(),
          'person_id' => (int) $scope['current_person']->id(),
          'household_ids' => $this->householdIds($scope),
          'household_label' => (string) $household->label(),
          'timezone' => $this->taskMutations->currentUserTimezoneId(),
        ]);
      }
    }
    if ($intent === ThreeIntentReadProposeService::PROPOSE_ACTIVITY
      && $result['status'] === 'PROPOSAL_READY'
      && $result['saved'] === FALSE
      && is_array($result['data'])) {
      $data = $result['data'];
      $household = $scope['households'][(int) ($data['household_id'] ?? 0)] ?? NULL;
      if ($household !== NULL) {
        $review = [
          'fields' => $this->activityFields($form_state),
          'proposal' => $data,
          'user_id' => (int) $this->currentUser()->id(),
          'person_id' => (int) $scope['current_person']->id(),
          'household_ids' => $this->householdIds($scope),
          'household_label' => (string) $household->label(),
          'timezone' => (string) $today['timezone'],
          'expires_at' => \Drupal::time()->getCurrentTime() + 600,
        ];
        if ($this->activityReviewContextValid($result, $review, $scope, (string) ($data['intent'] ?? ''))) {
          $form_state->set('activity_review', $review);
        }
      }
    }
    $this->show($form_state, $result);
  }

  /**
   * Compare the full persisted task snapshot, not just its display label.
   *
   * @param array<string, mixed> $item
   * @return array<string, int|string>
   */
  private function taskEditSnapshot(array $item): array {
    $task = $this->taskMutations->requireCurrentTask((int) $item['id']);
    if ((string) $task->get('status')->value !== PersonalTask::STATUS_OPEN) {
      throw new InvalidArgumentException('Task is no longer open.');
    }
    return [
      'id' => (int) $task->id(),
      'title' => (string) $task->get('title')->value,
      'status' => (string) $task->get('status')->value,
      'household_id' => (int) $task->get('household')->target_id,
      'person_id' => (int) $task->get('assigned_person')->target_id,
      'household_label' => (string) $item['_completion_household_label'],
      'due_mode' => (string) $task->get('due_mode')->value,
      'due_date' => (string) ($task->get('due_date')->value ?? ''),
      'due_at' => (string) ($task->get('due_at')->value ?? ''),
      'due_label' => (string) $item['due_label'],
    ];
  }

  /**
   * Pure read-only proposal following the existing PersonalTask due conventions.
   *
   * @param array<string, int|string> $before
   * @return array<string, string>
   */
  private function taskEditProposal(FormStateInterface $form_state, array $before, string $timezone): array {
    $titleInput = trim((string) $form_state->getValue('edit_task_title'));
    if (mb_strlen($titleInput) > 255) {
      throw new InvalidArgumentException('invalid_edit_title');
    }
    $title = $titleInput === '' ? (string) $before['title'] : $titleInput;
    if ($title === '' || mb_strlen($title) > 255) {
      throw new InvalidArgumentException('invalid_edit_title');
    }

    $chosenMode = (string) $form_state->getValue('edit_task_due_mode');
    if (!in_array($chosenMode, [self::KEEP_DUE, PersonalTask::DUE_NONE, PersonalTask::DUE_DATE, PersonalTask::DUE_DATE_TIME], TRUE)) {
      throw new InvalidArgumentException('invalid_edit_due_mode');
    }
    $mode = $chosenMode === self::KEEP_DUE ? (string) $before['due_mode'] : $chosenMode;
    $date = '';
    $at = '';
    $local = '';
    if ($chosenMode === self::KEEP_DUE) {
      $date = (string) $before['due_date'];
      $at = (string) $before['due_at'];
      if ($mode === PersonalTask::DUE_DATE_TIME) {
        $utc = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $at, new DateTimeZone('UTC'));
        if (!$utc instanceof DateTimeImmutable || $utc->format('Y-m-d\TH:i:s') !== $at) {
          throw new InvalidArgumentException('invalid_edit_due_at');
        }
        $local = $utc->setTimezone(new DateTimeZone($timezone))->format('Y-m-d H:i');
      }
    }
    elseif ($mode === PersonalTask::DUE_DATE) {
      $date = trim((string) $form_state->getValue('edit_task_due_date'));
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        throw new InvalidArgumentException('invalid_edit_due_date');
      }
      $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
      if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('invalid_edit_due_date');
      }
    }
    elseif ($mode === PersonalTask::DUE_DATE_TIME) {
      $local = trim((string) $form_state->getValue('edit_task_due_at'));
      if (preg_match('/^\d{4}-\d{2}-\d{2} (?:[01]\d|2[0-3]):[0-5]\d$/', $local) !== 1) {
        throw new InvalidArgumentException('invalid_edit_due_at');
      }
      $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $local, new DateTimeZone($timezone));
      if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d H:i') !== $local) {
        throw new InvalidArgumentException('invalid_edit_due_at');
      }
      $at = $parsed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');
    }
    elseif ($mode !== PersonalTask::DUE_NONE) {
      throw new InvalidArgumentException('invalid_edit_due_mode');
    }

    // editTask() accepts a local date/time. Never let a title-only edit
    // silently shift the original UTC instant during an ambiguous DST hour.
    if ($chosenMode === self::KEEP_DUE && $mode === PersonalTask::DUE_DATE_TIME
      && $title !== $before['title']) {
      $roundTrip = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $local, new DateTimeZone($timezone));
      if (!$roundTrip instanceof DateTimeImmutable
        || $roundTrip->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s') !== $at) {
        throw new InvalidArgumentException('invalid_edit_due_at');
      }
    }
    return [
      'title' => $title,
      'due_mode' => $mode,
      'due_date' => $date,
      'due_at' => $at,
      'due_local' => $local,
    ];
  }

  /**
   * @param array<string, int|string> $before
   * @param array<string, string> $after
   */
  private function taskEditChanged(array $before, array $after): bool {
    foreach (['title', 'due_mode', 'due_date', 'due_at'] as $field) {
      if ($before[$field] !== $after[$field]) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @return array<string, string>
   */
  private function taskEditFields(FormStateInterface $form_state): array {
    $fields = [];
    foreach (['intent', 'edit_task_choice', 'edit_task_title', 'edit_task_due_mode', 'edit_task_due_date', 'edit_task_due_at'] as $name) {
      $fields[$name] = trim((string) $form_state->getValue($name));
    }
    return $fields;
  }

  /**
   * Only the unexpired, current server-side proposal can show or use WRITE.
   *
   * @param mixed $result
   * @param mixed $review
   * @param array<string, mixed> $scope
   */
  private function taskEditReviewContextValid(mixed $result, mixed $review, array $scope): bool {
    return is_array($result) && is_array($review)
      && ($result['intent'] ?? NULL) === self::EDIT_TASK
      && ($result['status'] ?? NULL) === 'PROPOSAL_READY'
      && ($result['saved'] ?? NULL) === FALSE
      && ($result['data'] ?? NULL) === [
        'before' => $review['before'] ?? NULL,
        'after' => $review['after'] ?? NULL,
        'timezone' => $review['timezone'] ?? NULL,
        'changed' => TRUE,
      ]
      && is_array($review['before'] ?? NULL)
      && is_array($review['after'] ?? NULL)
      && ($review['before']['status'] ?? NULL) === PersonalTask::STATUS_OPEN
      && ($review['before']['id'] ?? 0) > 0
      && $this->taskEditChanged($review['before'], $review['after'])
      && is_array($review['fields'] ?? NULL)
      && is_string($review['choice'] ?? NULL)
      && $review['choice'] !== ''
      && ($review['user_id'] ?? NULL) === (int) $this->currentUser()->id()
      && ($review['person_id'] ?? NULL) === (int) $scope['current_person']->id()
      && ($review['household_ids'] ?? NULL) === $this->householdIds($scope)
      && ($review['timezone'] ?? NULL) === $this->taskMutations->currentUserTimezoneId()
      && isset($scope['households'][(int) ($review['before']['household_id'] ?? 0)])
      && $review['before']['household_label'] === (string) $scope['households'][(int) $review['before']['household_id']]->label()
      && is_int($review['expires_at'] ?? NULL)
      && $review['expires_at'] > \Drupal::time()->getCurrentTime()
      && $review['expires_at'] <= \Drupal::time()->getCurrentTime() + 600;
  }

  /**
   * Validate a prior server-held task-completion preview.
   *
   * @param mixed $result
   * @param mixed $review
   * @param array<string, mixed> $scope
   */
  private function taskCompletionReviewContextValid(mixed $result, mixed $review, array $scope): bool {
    return is_array($result) && is_array($review)
      && ($result['intent'] ?? NULL) === self::COMPLETE_TASK
      && ($result['status'] ?? NULL) === 'PROPOSAL_READY'
      && ($result['saved'] ?? NULL) === FALSE
      && is_array($review['summary'] ?? NULL)
      && ($result['data'] ?? NULL) === $review['summary']
      && is_string($review['choice'] ?? NULL)
      && $review['choice'] !== ''
      && ($review['summary']['status'] ?? NULL) === PersonalTask::STATUS_OPEN
      && is_int($review['summary']['id'] ?? NULL)
      && $review['summary']['id'] > 0
      && ($review['user_id'] ?? NULL) === (int) $this->currentUser()->id()
      && ($review['person_id'] ?? NULL) === (int) $scope['current_person']->id()
      && ($review['household_ids'] ?? NULL) === $this->householdIds($scope)
      && is_int($review['expires_at'] ?? NULL)
      && $review['expires_at'] > \Drupal::time()->getCurrentTime()
      && $review['expires_at'] <= \Drupal::time()->getCurrentTime() + 600;
  }

  /**
   * @param array<string, mixed> $scope
   *
   * @return array<string, array<string, mixed>>
   */
  private function taskCompletionChoices(array $scope): array {
    $choices = [];
    foreach ($this->taskQuery->myOpenTasks() as $item) {
      $taskId = (int) $item['id'];
      $task = $this->taskMutations->requireCurrentTask($taskId);
      $householdId = (int) $task->get('household')->target_id;
      $household = $scope['households'][$householdId] ?? NULL;
      if ($taskId <= 0 || $household === NULL) {
        throw new RuntimeException('Open task does not belong to the current authorized scope.');
      }
      $item['_completion_household_id'] = $householdId;
      $item['_completion_household_label'] = (string) $household->label();
      $key = hash('sha256', implode("\0", [(string) $taskId, (string) $householdId]));
      $choices[$key] = $item;
    }
    return $choices;
  }

  /**
   * @param array<string, mixed> $item
   */
  private function taskCompletionLabel(array $item): string {
    $due = (string) $item['due_label'];
    return implode(' — ', [
      (string) $item['title'],
      (string) $item['_completion_household_label'],
      $due !== '' ? $due : (string) $this->tr('No deadline', 'Sans échéance'),
    ]);
  }

  /**
   * @param array<string, mixed> $item
   *
   * @return array<string, int|string>
   */
  private function taskCompletionSummary(array $item): array {
    return [
      'id' => (int) $item['id'],
      'title' => (string) $item['title'],
      'status' => (string) $item['status'],
      'due_mode' => (string) $item['due_mode'],
      'due_label' => (string) $item['due_label'],
      'household_id' => (int) $item['_completion_household_id'],
      'household_label' => (string) $item['_completion_household_label'],
    ];
  }

  /**
   * Only form-state data from the previous preview may authorize the button.
   *
   * @param mixed $result
   * @param mixed $review
   * @param array<string, mixed> $scope
   */
  private function preparationReviewContextValid(mixed $result, mixed $review, array $scope): bool {
    return is_array($result) && is_array($review)
      && ($result['intent'] ?? NULL) === self::MARK_PREPARATION
      && ($result['status'] ?? NULL) === 'PROPOSAL_READY'
      && ($result['saved'] ?? NULL) === FALSE
      && is_array($review['summary'] ?? NULL)
      && ($result['data'] ?? NULL) === $review['summary']
      && is_string($review['choice'] ?? NULL)
      && $review['choice'] !== ''
      && is_array($review['target'] ?? NULL)
      && count($review['target']) === 3
      && ($review['user_id'] ?? NULL) === (int) $this->currentUser()->id()
      && ($review['person_id'] ?? NULL) === (int) $scope['current_person']->id()
      && ($review['household_ids'] ?? NULL) === $this->householdIds($scope)
      && is_int($review['expires_at'] ?? NULL)
      && $review['expires_at'] > \Drupal::time()->getCurrentTime()
      && $review['expires_at'] <= \Drupal::time()->getCurrentTime() + 600;
  }

  /**
   * @return array<string, array<string, mixed>>
   */
  private function preparationChoices(): array {
    $choices = [];
    foreach ($this->currentUserPreparations->mine(NULL, 30)['items'] as $item) {
      if ($item['prepared']) {
        continue;
      }
      $identity = $this->preparationIdentity($item);
      $choices[$identity] = $item;
    }
    return $choices;
  }

  /**
   * @param array<string, mixed> $item
   */
  private function preparationIdentity(array $item): string {
    return hash('sha256', implode("\0", [
      (string) $item['_completion_series_id'],
      (string) $item['_completion_target_revision_id'],
      (string) $item['_completion_original_occurrence_key'],
      (string) $item['_completion_requirement_id'],
    ]));
  }

  /**
   * @param array<string, mixed> $item
   */
  private function preparationLabel(array $item): string {
    return implode(' — ', [
      (string) $item['instruction'],
      (string) $item['activity_label'],
      (string) ($item['activity_start'] ?: $item['all_day_start_label']),
      (string) $item['due_time'],
    ]);
  }

  /**
   * @param array<string, mixed> $item
   * @return array<string, string|int>
   */
  private function preparationSummary(array $item): array {
    return [
      'instruction' => (string) $item['instruction'],
      'activity_label' => (string) $item['activity_label'],
      'activity_start' => (string) ($item['activity_start'] ?: $item['all_day_start_label']),
      'due_time' => (string) $item['due_time'],
      'revision_id' => (int) $item['_completion_target_revision_id'],
    ];
  }

  /**
   * @param array<string, mixed> $item
   * @return array{int, string, int}
   */
  private function preparationTarget(array $item): array {
    return [
      (int) $item['_completion_series_id'],
      (string) $item['_completion_original_occurrence_key'],
      (int) $item['_completion_requirement_id'],
    ];
  }

  private function taskReviewContextValid(mixed $result, mixed $review, array $scope): bool {
    if (!is_array($result) || !is_array($review)
      || ($result['intent'] ?? NULL) !== ThreeIntentReadProposeService::PROPOSE_TASK
      || ($result['status'] ?? NULL) !== 'PROPOSAL_READY'
      || ($result['saved'] ?? NULL) !== FALSE
      || ($result['data'] ?? NULL) !== ($review['proposal'] ?? NULL)
      || !is_array($review['proposal'] ?? NULL)
      || ($review['proposal']['ready_for_confirmation'] ?? FALSE) !== TRUE
      || ($review['user_id'] ?? NULL) !== (int) $this->currentUser()->id()
      || ($review['person_id'] ?? NULL) !== (int) $scope['current_person']->id()
      || ($review['household_ids'] ?? NULL) !== $this->householdIds($scope)
      || ($review['timezone'] ?? NULL) !== $this->taskMutations->currentUserTimezoneId()
      || !is_array($review['fields'] ?? NULL)) {
      return FALSE;
    }
    $householdId = (int) ($review['proposal']['household_id'] ?? 0);
    $household = $scope['households'][$householdId] ?? NULL;
    return $household !== NULL
      && ($review['household_label'] ?? NULL) === (string) $household->label();
  }

  /**
   * The previous server-side preview, not an agent or POST argument, gates WRITE.
   *
   * @param mixed $result
   * @param mixed $review
   * @param array<string, mixed> $scope
   */
  private function activityReviewContextValid(mixed $result, mixed $review, array $scope, string $expectedIntent = ActivityCaptureProposal::INTENT_ONE_OFF): bool {
    if (!is_array($result) || !is_array($review)
      || ($result['intent'] ?? NULL) !== ThreeIntentReadProposeService::PROPOSE_ACTIVITY
      || ($result['status'] ?? NULL) !== 'PROPOSAL_READY'
      || ($result['saved'] ?? NULL) !== FALSE
      || ($result['data'] ?? NULL) !== ($review['proposal'] ?? NULL)
      || !is_array($review['proposal'] ?? NULL)
      || ($review['proposal']['ready_for_confirmation'] ?? FALSE) !== TRUE
      || !in_array($expectedIntent, [ActivityCaptureProposal::INTENT_ONE_OFF, ActivityCaptureProposal::INTENT_WEEKLY], TRUE)
      || ($review['proposal']['intent'] ?? NULL) !== $expectedIntent
      || ($expectedIntent === ActivityCaptureProposal::INTENT_ONE_OFF && ($review['proposal']['weekday'] ?? NULL) !== NULL)
      || ($expectedIntent === ActivityCaptureProposal::INTENT_WEEKLY && ($review['proposal']['weekday'] ?? NULL) !== ($review['fields']['activity_weekday'] ?? NULL))
      || ($expectedIntent === ActivityCaptureProposal::INTENT_WEEKLY && !$this->weeklyDateMatches($review['proposal']))
      || !ActivitySeries::supportsTimeMode((string) ($review['proposal']['time_mode'] ?? ''))
      || ($review['proposal']['responsible_person_id'] ?? NULL) !== (int) $scope['current_person']->id()
      || ($review['proposal']['concerned_person_ids'] ?? NULL) !== []
      || ($review['proposal']['location'] ?? NULL) !== NULL
      || ($review['user_id'] ?? NULL) !== (int) $this->currentUser()->id()
      || ($review['person_id'] ?? NULL) !== (int) $scope['current_person']->id()
      || ($review['household_ids'] ?? NULL) !== $this->householdIds($scope)
      || ($review['timezone'] ?? NULL) !== ($review['proposal']['source_timezone'] ?? NULL)
      || !is_int($review['expires_at'] ?? NULL)
      || $review['expires_at'] <= \Drupal::time()->getCurrentTime()
      || $review['expires_at'] > \Drupal::time()->getCurrentTime() + 600
      || !is_array($review['fields'] ?? NULL)
      || ($review['fields']['activity_responsible_self'] ?? NULL) !== '1'
      || ($review['fields']['activity_mode'] ?? NULL) !== ($review['proposal']['time_mode'] ?? NULL)) {
      return FALSE;
    }
    $householdId = (int) ($review['proposal']['household_id'] ?? 0);
    $household = $scope['households'][$householdId] ?? NULL;
    return $household !== NULL
      && ($review['household_label'] ?? NULL) === (string) $household->label();
  }

  /**
   * @param array<string, mixed> $scope
   * @return int[]
   */
  private function householdIds(array $scope): array {
    $ids = array_map('intval', array_keys($scope['households']));
    sort($ids, SORT_NUMERIC);
    return $ids;
  }

  /**
   * @return array<string, string>
   */
  private function taskFields(FormStateInterface $form_state): array {
    $fields = [];
    foreach (['intent', 'task_title', 'task_household', 'task_due_mode', 'task_due_date', 'task_due_at'] as $name) {
      $fields[$name] = trim((string) $form_state->getValue($name));
    }
    return $fields;
  }

  /**
   * @return array<string, string>
   */
  private function activityFields(FormStateInterface $form_state): array {
    $fields = [];
    foreach (['intent', 'activity_label', 'activity_date', 'activity_mode', 'activity_start', 'activity_end', 'activity_responsible_self', 'activity_recurrence', 'activity_weekday'] as $name) {
      $fields[$name] = trim((string) $form_state->getValue($name));
    }
    return $fields;
  }

  /**
   * Builds an independent deterministic proposal from submitted typed fields.
   *
   * @return array<string, mixed>
   */
  private function activityInput(FormStateInterface $form_state, string $timezone): array {
    $date = trim((string) $form_state->getValue('activity_date'));
    $parts = explode('-', $date);
    $validDate = count($parts) === 3
      && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date) === 1
      && checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    $mode = (string) $form_state->getValue('activity_mode');
    if (!in_array($mode, [ActivitySeries::TIME_MODE_TIMED, ActivitySeries::TIME_MODE_ALL_DAY], TRUE)) {
      throw new InvalidArgumentException('time_mode_conflict');
    }
    $allDay = $mode === ActivitySeries::TIME_MODE_ALL_DAY;
    $recurrence = (string) $form_state->getValue('activity_recurrence');
    if (!in_array($recurrence, [ActivityCaptureProposal::INTENT_ONE_OFF, ActivityCaptureProposal::INTENT_WEEKLY], TRUE)) {
      throw new InvalidArgumentException('recurrence_required');
    }
    $weekday = trim((string) $form_state->getValue('activity_weekday'));
    $days = [
      'MONDAY' => 'monday', 'TUESDAY' => 'tuesday', 'WEDNESDAY' => 'wednesday',
      'THURSDAY' => 'thursday', 'FRIDAY' => 'friday',
      'SATURDAY' => 'saturday', 'SUNDAY' => 'sunday',
    ];
    if ($recurrence === ActivityCaptureProposal::INTENT_WEEKLY) {
      if (!$validDate || !isset($days[$weekday])) {
        throw new InvalidArgumentException('weekly_date_weekday_required');
      }
      $localDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone($timezone));
      if (!$localDate instanceof DateTimeImmutable || strtoupper($localDate->format('l')) !== $weekday) {
        throw new InvalidArgumentException('weekly_date_weekday_mismatch');
      }
      if ($allDay) {
        if (trim((string) $form_state->getValue('activity_start')) !== ''
          || trim((string) $form_state->getValue('activity_end')) !== '') {
          throw new InvalidArgumentException('weekly_all_day_without_times');
        }
      }
      else {
        $start = trim((string) $form_state->getValue('activity_start'));
        $end = trim((string) $form_state->getValue('activity_end'));
        if (preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $start) === 1
          && preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $end) === 1
          && $end > $start
          && (!$this->uniqueWeeklyLocalTime($date, $start, $timezone)
            || !$this->uniqueWeeklyLocalTime($date, $end, $timezone))) {
          throw new InvalidArgumentException('weekly_dst_invalid');
        }
      }
    }
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
        'recurrence_expression' => $recurrence === ActivityCaptureProposal::INTENT_WEEKLY
          ? 'every ' . $days[$weekday] : NULL,
        'explicit_all_day_signal' => $allDay,
        'explicit_timed_signal' => !$allDay,
      ]);
    }
    catch (InvalidArgumentException) {
      throw new InvalidArgumentException('invalid_structured_fields');
    }
    return [
      'context' => new ActivityCaptureInput(
        'Explicit structured form; no language interpretation',
        new DateTimeImmutable('now', new DateTimeZone('UTC')),
        $timezone,
      ),
      'extraction' => $extraction,
    ];
  }

  /**
   * Rejects normalized nonexistent times and duplicate local times at DST fall-back.
   */
  private function uniqueWeeklyLocalTime(string $date, string $time, string $timezone): bool {
    $zone = new DateTimeZone($timezone);
    $local = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $zone);
    if (!$local instanceof DateTimeImmutable || $local->format('Y-m-d H:i') !== $date . ' ' . $time) {
      return FALSE;
    }
    $wall = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, new DateTimeZone('UTC'));
    if (!$wall instanceof DateTimeImmutable) {
      return FALSE;
    }
    $transitions = $zone->getTransitions($local->getTimestamp() - 86400, $local->getTimestamp() + 86400);
    if ($transitions === FALSE) {
      return TRUE;
    }
    $previousOffset = NULL;
    foreach ($transitions as $transition) {
      $offset = (int) $transition['offset'];
      if ($previousOffset !== NULL && $offset < $previousOffset
        && $wall->getTimestamp() >= (int) $transition['ts'] + $offset
        && $wall->getTimestamp() < (int) $transition['ts'] + $previousOffset) {
        return FALSE;
      }
      $previousOffset = $offset;
    }
    return TRUE;
  }

  /**
   * @param array<string, mixed> $data
   */
  private function weeklyDateMatches(array $data): bool {
    $weekday = (string) ($data['weekday'] ?? '');
    $date = (string) ($data['absolute_date'] ?? '');
    if (!in_array($weekday, ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'], TRUE)
      || preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date) !== 1) {
      return FALSE;
    }
    $localDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
    return $localDate instanceof DateTimeImmutable
      && $localDate->format('Y-m-d') === $date
      && strtoupper($localDate->format('l')) === $weekday;
  }

  /**
   * @return array<string, mixed>
   */
  private function taskInput(FormStateInterface $form_state): array {
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
    return $input;
  }

  private function rejectTaskEditConfirmation(FormStateInterface $form_state): void {
    $form_state->set('task_edit_review', NULL);
    $this->show($form_state, [
      'intent' => self::EDIT_TASK,
      'status' => 'NEEDS_CLARIFICATION',
      'data' => NULL,
      'clarifications' => ['fresh_task_edit_preview_required'],
      'saved' => FALSE,
    ]);
  }

  private function rejectTaskCompletionConfirmation(FormStateInterface $form_state): void {
    $form_state->set('task_completion_review', NULL);
    $this->show($form_state, [
      'intent' => self::COMPLETE_TASK,
      'status' => 'NEEDS_CLARIFICATION',
      'data' => NULL,
      'clarifications' => ['fresh_task_completion_preview_required'],
      'saved' => FALSE,
    ]);
  }

  private function rejectPreparationConfirmation(FormStateInterface $form_state): void {
    $form_state->set('preparation_review', NULL);
    $this->show($form_state, [
      'intent' => self::MARK_PREPARATION,
      'status' => 'NEEDS_CLARIFICATION',
      'data' => NULL,
      'clarifications' => ['fresh_preparation_preview_required'],
      'saved' => FALSE,
    ]);
  }

  private function rejectTaskConfirmation(FormStateInterface $form_state): void {
    $form_state->set('task_review', NULL);
    $this->show($form_state, [
      'intent' => ThreeIntentReadProposeService::PROPOSE_TASK,
      'status' => 'NEEDS_CLARIFICATION',
      'data' => NULL,
      'clarifications' => ['fresh_task_preview_required'],
      'saved' => FALSE,
    ]);
  }

  private function rejectActivityConfirmation(FormStateInterface $form_state): void {
    $form_state->set('activity_review', NULL);
    $this->show($form_state, [
      'intent' => ThreeIntentReadProposeService::PROPOSE_ACTIVITY,
      'status' => 'NEEDS_CLARIFICATION',
      'data' => NULL,
      'clarifications' => ['fresh_activity_preview_required'],
      'saved' => FALSE,
    ]);
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
  private function resultBuild(array $result, array $scope): array {
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
        '#value' => match ($result['intent'] ?? NULL) {
          self::EDIT_TASK => $this->tr(
            'Preview only — the task has not been changed.',
            'Aperçu uniquement — la tâche n’a pas été modifiée.',
          ),
          self::COMPLETE_TASK => $this->tr(
            'Preview only — the task has not been completed.',
            'Aperçu uniquement — la tâche n’a pas été terminée.',
          ),
          self::MARK_PREPARATION => $this->tr(
            'Preview only — the preparation has not been marked done.',
            'Aperçu uniquement — le préparatif n’a pas été marqué comme fait.',
          ),
          default => $this->tr(
            'Preview not saved — no task or activity created.',
            'Aperçu non enregistré — aucune tâche ni activité créée.',
          ),
        },
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
      $tomorrow = ($result['intent'] ?? NULL) === ThreeIntentReadProposeService::GET_TOMORROW;
      $build['date'] = $this->fact($this->t('@day: @date (@timezone)', [
        '@day' => $tomorrow ? $this->tr('Tomorrow', 'Demain') : $this->tr('Today', 'Aujourd’hui'),
        '@date' => (string) $data['local_date'],
        '@timezone' => (string) $data['timezone'],
      ]));
      $build['briefing'] = $tomorrow
        ? $this->tomorrowBriefing($data)
        : $this->todayBriefing($data);
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
          if ($kind === 'tasks' || $kind === 'preparations') {
            $label = $kind === 'tasks' ? (string) $item['title'] : (string) $item['instruction'];
            $due = $kind === 'tasks' ? (string) $item['due_label'] : (string) $item['due_time'];
            $when = $item['overdue']
              ? $this->tr('Overdue', 'En retard')
              : $this->tr('Due', 'Échéance');
            $build[$kind . '_' . $index] = $this->fact($this->t('@label — @when: @due', [
              '@label' => $label, '@when' => $when, '@due' => $due,
            ]));
            continue;
          }
          $extra = $item['kind'] === 'external_event'
            ? $this->tr(' — external data stored locally; freshness not verified.',
              ' — données externes enregistrées localement ; actualisation non vérifiée.')
            : $this->tr(' — local activity.', ' — activité locale.');
          $build[$kind . '_' . $index] = $this->fact($this->t('@label@extra', [
            '@label' => (string) $item['title'], '@extra' => $extra,
          ]));
        }
      }
    }
    elseif ($status === 'PROPOSAL_READY' && is_array($result['data'] ?? NULL)) {
      $data = $result['data'];
      if (($result['intent'] ?? NULL) === self::EDIT_TASK) {
        $before = $data['before'];
        $after = $data['after'];
        $timezone = (string) $data['timezone'];
        $oldDue = $this->taskEditDueLabel($before['due_mode'], $before['due_date'], $before['due_at'], $timezone);
        $newDue = $this->taskEditDueLabel($after['due_mode'], $after['due_date'], $after['due_at'], $timezone);
        $build['task_to_edit'] = $this->fact($this->t('@label: @title — @household', [
          '@label' => $this->tr('Task', 'Tâche'),
          '@title' => (string) $before['title'],
          '@household' => (string) $before['household_label'],
        ]));
        $build['before_title'] = $this->fact($this->t('@label: @value', [
          '@label' => $this->tr('Before — title', 'Avant — intitulé'),
          '@value' => (string) $before['title'],
        ]));
        $build['after_title'] = $this->fact($this->t('@label: @value', [
          '@label' => $this->tr('After — title', 'Après — intitulé'),
          '@value' => (string) $after['title'],
        ]));
        $build['before_due'] = $this->fact($this->t('@label: @value', [
          '@label' => $this->tr('Before — deadline', 'Avant — échéance'),
          '@value' => $oldDue,
        ]));
        $build['after_due'] = $this->fact($this->t('@label: @value', [
          '@label' => $this->tr('After — deadline', 'Après — échéance'),
          '@value' => $newDue,
        ]));
        $build['effect'] = $this->fact($data['changed']
          ? $this->tr(
            'Only the title and/or deadline shown above will change after a separate confirmation. The task remains open and in the same Household.',
            'Seuls l’intitulé et/ou l’échéance présentés ci-dessus changeront après une confirmation distincte. La tâche reste ouverte dans le même foyer.',
          )
          : $this->tr(
            'Nothing has changed. No save is needed or available.',
            'Aucune modification. Aucun enregistrement n’est nécessaire ni proposé.',
          ));
        return $build;
      }
      if (($result['intent'] ?? NULL) === self::COMPLETE_TASK) {
        $due = (string) $data['due_label'];
        $build['task_to_complete'] = $this->fact($this->t('@title — @household — @due', [
          '@title' => (string) $data['title'],
          '@household' => (string) $data['household_label'],
          '@due' => $due !== '' ? $due : $this->tr('No deadline', 'Sans échéance'),
        ]));
        $build['effect'] = $this->fact($this->tr(
          'Only this task will be completed after confirmation. You can reopen it from My tasks.',
          'Seule cette tâche sera terminée après confirmation. Vous pourrez la rouvrir dans Mes tâches.',
        ));
        return $build;
      }
      if (($result['intent'] ?? NULL) === self::MARK_PREPARATION) {
        $build['preparation'] = $this->fact($this->t('@instruction — @activity — @start — @due', [
          '@instruction' => (string) $data['instruction'],
          '@activity' => (string) $data['activity_label'],
          '@start' => (string) $data['activity_start'],
          '@due' => (string) $data['due_time'],
        ]));
        $build['effect'] = $this->fact($this->tr(
          'Only this preparation will be marked done after confirmation.',
          'Seul ce préparatif sera marqué comme fait après confirmation.',
        ));
        return $build;
      }
      $build['proposal'] = $this->fact($this->t('@name', [
        '@name' => (string) ($data['title'] ?? $data['label'] ?? ''),
      ]));
      if (($result['intent'] ?? NULL) === ThreeIntentReadProposeService::PROPOSE_TASK) {
        $household = $scope['households'][(int) ($data['household_id'] ?? 0)] ?? NULL;
        if ($household !== NULL) {
          $build['household'] = $this->fact($this->t('@label: @value', [
            '@label' => $this->tr('Household', 'Foyer'),
            '@value' => (string) $household->label(),
          ]));
        }
        $modes = [
          PersonalTask::DUE_NONE => $this->tr('No deadline', 'Sans échéance'),
          PersonalTask::DUE_DATE => $this->tr('Date', 'Date'),
          PersonalTask::DUE_DATE_TIME => $this->tr('Date and time', 'Date et heure'),
        ];
        $mode = (string) ($data['due_mode'] ?? '');
        if (isset($modes[$mode])) {
          $build['due_mode'] = $this->fact($modes[$mode]);
        }
        if ($mode === PersonalTask::DUE_DATE_TIME) {
          $build['timezone'] = $this->fact($this->t('@timezone', [
            '@timezone' => $this->taskMutations->currentUserTimezoneId(),
          ]));
        }
      }
      if (($result['intent'] ?? NULL) === ThreeIntentReadProposeService::PROPOSE_ACTIVITY) {
        $household = $scope['households'][(int) ($data['household_id'] ?? 0)] ?? NULL;
        if ($household !== NULL) {
          $build['household'] = $this->fact($this->t('@label: @value', [
            '@label' => $this->tr('Household', 'Foyer'),
            '@value' => (string) $household->label(),
          ]));
        }
        if (($data['intent'] ?? NULL) === ActivityCaptureProposal::INTENT_WEEKLY) {
          $names = [
            'MONDAY' => $this->tr('Monday', 'Lundi'),
            'TUESDAY' => $this->tr('Tuesday', 'Mardi'),
            'WEDNESDAY' => $this->tr('Wednesday', 'Mercredi'),
            'THURSDAY' => $this->tr('Thursday', 'Jeudi'),
            'FRIDAY' => $this->tr('Friday', 'Vendredi'),
            'SATURDAY' => $this->tr('Saturday', 'Samedi'),
            'SUNDAY' => $this->tr('Sunday', 'Dimanche'),
          ];
          $build['recurrence'] = $this->fact($this->tr('Every week', 'Chaque semaine'));
          $build['weekday'] = $this->fact($names[(string) ($data['weekday'] ?? '')]
            ?? $this->tr('Choose a weekday', 'Choisir un jour'));
        }
        $build['time_mode'] = $this->fact(
          ($data['time_mode'] ?? '') === ActivitySeries::TIME_MODE_ALL_DAY
            ? $this->tr('All day', 'Toute la journée')
            : $this->tr('With times', 'Avec heures'),
        );
        $build['responsible'] = $this->fact(
          ($data['responsible_person_id'] ?? NULL) !== NULL
            ? $this->tr('I am responsible', 'Je suis responsable')
            : $this->tr('Responsibility not specified', 'Responsabilité non précisée'),
        );
        $build['timezone'] = $this->fact($this->t('@timezone', [
          '@timezone' => (string) ($data['source_timezone'] ?? ''),
        ]));
      }
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


  /**
   * Formats canonical stored due values in the Drupal User timezone.
   */
  private function taskEditDueLabel(string $mode, string $date, string $at, string $timezone): string {
    if ($mode === PersonalTask::DUE_NONE) {
      return (string) $this->tr('No deadline', 'Sans échéance');
    }
    if ($mode === PersonalTask::DUE_DATE) {
      return $date;
    }
    $utc = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $at, new DateTimeZone('UTC'));
    if (!$utc instanceof DateTimeImmutable) {
      return (string) $this->tr('Unavailable', 'Indisponible');
    }
    return $utc->setTimezone(new DateTimeZone($timezone))->format('Y-m-d H:i') . ' (' . $timezone . ')';
  }

  /**
   * Read-only briefing composed solely from the authorized GET_TODAY snapshot.
   *
   * @param array<string, mixed> $data
   *   Whitelisted presentation data, not provider payloads or entity IDs.
   */
  private function todayBriefing(array $data): array {
    $taskOverdue = count(array_filter(
      $data['tasks'],
      static fn(array $item): bool => (bool) $item['overdue'],
    ));
    $preparationOverdue = count(array_filter(
      $data['preparations'],
      static fn(array $item): bool => (bool) $item['overdue'],
    ));

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ps-card', 'ps-guided-assistant__briefing']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->tr('Your day at a glance', 'Votre journée en bref'),
      ],
    ];
    foreach ([
      'tasks' => [$this->tr('Tasks due or overdue', 'Tâches dues ou en retard'), count($data['tasks'])],
      'tasks_overdue' => [$this->tr('Overdue tasks', 'Tâches en retard'), $taskOverdue],
      'preparations' => [$this->tr('Preparations due or overdue', 'Préparatifs dus ou en retard'), count($data['preparations'])],
      'preparations_overdue' => [$this->tr('Overdue preparations', 'Préparatifs en retard'), $preparationOverdue],
      'planning' => [$this->tr('Planning items', 'Éléments du planning'), count($data['planning'])],
    ] as $key => [$label, $count]) {
      $build[$key] = $this->fact($this->t('@label: @count', [
        '@label' => $label,
        '@count' => $count,
      ]));
    }
    $build['attention'] = $this->fact($taskOverdue + $preparationOverdue > 0
      ? $this->tr(
        'Some items are overdue. Check the lists below before planning your day.',
        'Certains éléments sont en retard. Vérifiez les listes ci-dessous avant de planifier votre journée.',
      )
      : $this->tr(
        'No overdue tasks or preparations.',
        'Aucune tâche ni aucun préparatif en retard.',
      ));

    $build['links'] = $this->briefingLinks();
    return $build;
  }

  /**
   * Read-only evening view from the same privacy-filtered day projection.
   */
  private function tomorrowBriefing(array $data): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ps-card', 'ps-guided-assistant__briefing']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->tr('Tomorrow at a glance', 'Demain en bref'),
      ],
    ];
    foreach ([
      'tasks' => [$this->tr('Tasks due tomorrow', 'Tâches à échéance demain'), count($data['tasks'])],
      'preparations' => [$this->tr('Preparations due tomorrow', 'Préparatifs à faire demain'), count($data['preparations'])],
      'planning' => [$this->tr('Planning items tomorrow', 'Éléments au planning demain'), count($data['planning'])],
    ] as $key => [$label, $count]) {
      $build[$key] = $this->fact($this->t('@label: @count', [
        '@label' => $label,
        '@count' => $count,
      ]));
    }
    $build['notice'] = $this->fact($this->tr(
      'A preview of currently known items, not a live calendar sync. Due or overdue items for today remain in View my day.',
      'Un aperçu des éléments actuellement connus, sans synchronisation du calendrier en direct. Les échéances du jour et les retards restent dans Consulter ma journée.',
    ));
    $build['links'] = $this->briefingLinks();
    return $build;
  }

  /**
   * @return array<string, mixed>
   */
  private function briefingLinks(): array {
    $links = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ps-guided-assistant__briefing-links']],
    ];
    foreach ([
      'today' => ['personal_secretary.today', $this->tr('Today', 'Aujourd’hui')],
      'tasks' => ['personal_secretary.my_tasks', $this->tr('My tasks', 'Mes tâches')],
      'preparations' => ['personal_secretary.my_preparations', $this->tr('My preparations', 'Mes préparatifs')],
      'upcoming' => ['personal_secretary.my_upcoming', $this->tr('My upcoming', 'Mes activités à venir')],
    ] as $key => [$route, $label]) {
      $links[$key] = [
        '#type' => 'link',
        '#title' => $label,
        '#url' => Url::fromRoute($route),
        '#attributes' => ['class' => ['button']],
      ];
    }
    return $links;
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
      'recurrence_required' => $this->tr('Choose one-off or weekly.', 'Choisissez ponctuelle ou chaque semaine.'),
      'weekly_date_weekday_required' => $this->tr('Choose a valid first date and weekday.', 'Choisissez une première date et un jour de semaine valides.'),
      'weekly_date_weekday_mismatch' => $this->tr('The first date must match the selected weekday.', 'La première date doit correspondre au jour de semaine choisi.'),
      'weekly_all_day_without_times' => $this->tr('All-day weekly activities cannot have clock times.', 'Une activité hebdomadaire sur toute la journée ne peut pas comporter d’heures.'),
      'weekly_dst_invalid' => $this->tr('The selected local time is ambiguous or does not exist due to a clock change.', 'L’heure locale choisie est ambiguë ou inexistante en raison du changement d’heure.'),
      'fresh_activity_preview_required' => $this->tr(
        'The activity preview is no longer valid. Review a new preview before creating an activity.',
        'L’aperçu de l’activité n’est plus valide. Affichez un nouvel aperçu avant de créer une activité.',
      ),
      'invalid_edit_title' => $this->tr(
        'Enter a new title of at most 255 characters, or leave it blank.',
        'Indiquez un nouvel intitulé de 255 caractères maximum ou laissez ce champ vide.',
      ),
      'invalid_edit_due_mode' => $this->tr(
        'Choose a valid deadline mode.',
        'Choisissez un mode d’échéance valide.',
      ),
      'invalid_edit_due_date' => $this->tr(
        'Enter a valid due date (YYYY-MM-DD).',
        'Indiquez une date d’échéance valide (AAAA-MM-JJ).',
      ),
      'invalid_edit_due_at' => $this->tr(
        'Enter a valid date and time in your timezone (YYYY-MM-DD HH:MM).',
        'Indiquez une date et heure valides dans votre fuseau (AAAA-MM-JJ HH:MM).',
      ),
      'fresh_task_edit_preview_required' => $this->tr(
        'This edit preview is no longer valid. Show a new preview before saving.',
        'Cet aperçu de modification n’est plus valide. Affichez un nouvel aperçu avant d’enregistrer.',
      ),
      'choose_open_task' => $this->tr(
        'Choose an open task and show a new preview.',
        'Choisissez une tâche ouverte et affichez un nouvel aperçu.',
      ),
      'fresh_task_completion_preview_required' => $this->tr(
        'This task preview is no longer valid. Show a new preview before completing it.',
        'Cet aperçu de tâche n’est plus valide. Affichez un nouvel aperçu avant de la terminer.',
      ),
      'choose_pending_preparation' => $this->tr(
        'Choose a pending preparation and show a new preview.',
        'Choisissez un préparatif à faire et affichez un nouvel aperçu.',
      ),
      'fresh_preparation_preview_required' => $this->tr(
        'This preparation preview is no longer valid. Show a new preview before marking it done.',
        'Cet aperçu de préparatif n’est plus valide. Affichez un nouvel aperçu avant de le marquer comme fait.',
      ),
      'fresh_task_preview_required' => $this->tr(
        'The task preview is no longer valid. Review a new preview before creating a task.',
        'L’aperçu de la tâche n’est plus valide. Affichez un nouvel aperçu avant de créer une tâche.',
      ),
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
