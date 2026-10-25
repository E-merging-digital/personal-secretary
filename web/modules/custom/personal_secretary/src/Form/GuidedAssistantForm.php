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
use Drupal\personal_secretary\Service\ThreeIntentReadProposeService;
use Drupal\personal_secretary\Service\PersonalTaskMutationService;
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

  public function __construct(
    protected TodayService $today,
    protected CurrentUserActivityCreationService $activityCreation,
    protected ActivityCaptureResolver $captureResolver,
    protected LanguageManagerInterface $interfaceLanguage,
    protected PersonalTaskMutationService $taskMutations,
  ) {
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.today'),
      $container->get('personal_secretary.current_user_activity_creation'),
      $container->get('personal_secretary.activity_capture_resolver'),
      $container->get('language_manager'),
      $container->get('personal_secretary.personal_task_mutation'),
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
        'Choose an action. The assistant does not interpret free text; only an explicit confirmation can create a task or activity.',
        'Choisissez une action. L’assistant n’interprète pas de texte libre ; seule une confirmation explicite peut créer une tâche ou une activité.',
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
   * Only form-state data from the previous preview may authorize the button.
   *
   * @param mixed $result
   * @param mixed $review
   * @param array<string, mixed> $scope
   */
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
