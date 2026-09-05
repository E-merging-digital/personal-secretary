<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use DateTimeZone;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\personal_secretary\Entity\PersonalTask;
use Drupal\personal_secretary\Service\PersonalTaskMutationService;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Adds a self-oriented PersonalTask or edits one OPEN task.
 */
final class PersonalTaskForm extends FormBase {

  public function __construct(
    private readonly PersonalTaskMutationService $taskMutations,
    private readonly RouteMatchInterface $taskRouteMatch,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.personal_task_mutation'),
      $container->get('current_route_match'),
    );
  }

  public function getFormId(): string {
    return $this->taskId() > 0
      ? 'personal_secretary_personal_task_edit'
      : 'personal_secretary_personal_task_add';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $task = $this->taskId() > 0 ? $this->loadEditableTask() : NULL;

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Task'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#default_value' => $task instanceof PersonalTask ? (string) $task->get('title')->value : '',
    ];

    if (!$task instanceof PersonalTask) {
      try {
        $eligible = $this->taskMutations->eligibleHouseholds();
      }
      catch (InvalidArgumentException) {
        $eligible = [];
      }

      if ($eligible === []) {
        $form['remediation'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('You need an authorized Household containing your linked Household member before you can add a task.'),
        ];
        return $form;
      }

      $options = [];
      foreach ($eligible as $id => $household) {
        $options[$id] = (string) $household->label();
      }
      $form['household'] = [
        '#type' => 'select',
        '#title' => $this->t('Household'),
        '#required' => TRUE,
        '#options' => $options,
        '#default_value' => count($options) === 1 ? (string) array_key_first($options) : NULL,
      ];
    }
    else {
      $household = $task->get('household')->entity;
      $form['scope'] = [
        '#type' => 'item',
        '#title' => $this->t('Household'),
        '#markup' => $household !== NULL ? $this->t('@household', ['@household' => (string) $household->label()]) : $this->t('Unavailable'),
      ];
    }

    $form['due_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Due'),
      '#required' => TRUE,
      '#options' => [
        PersonalTask::DUE_NONE => $this->t('No deadline'),
        PersonalTask::DUE_DATE => $this->t('Date'),
        PersonalTask::DUE_DATE_TIME => $this->t('Date and time'),
      ],
      '#default_value' => $task instanceof PersonalTask ? (string) $task->get('due_mode')->value : PersonalTask::DUE_NONE,
    ];

    $form['due_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Due date'),
      '#default_value' => $task instanceof PersonalTask ? (string) ($task->get('due_date')->value ?? '') : '',
      '#states' => [
        'visible' => [':input[name="due_mode"]' => ['value' => PersonalTask::DUE_DATE]],
      ],
    ];

    $timezone = $this->taskMutations->currentUserTimezoneId();
    $localDue = $task instanceof PersonalTask ? $this->taskMutations->dueAtAsLocalInput($task) : NULL;
    $defaultDateTime = NULL;
    if ($localDue !== NULL) {
      $defaultDateTime = DrupalDateTime::createFromFormat('Y-m-d H:i', $localDue, new DateTimeZone($timezone));
    }
    $form['due_at'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Due date and time'),
      '#default_value' => $defaultDateTime,
      '#date_timezone' => $timezone,
      '#states' => [
        'visible' => [':input[name="due_mode"]' => ['value' => PersonalTask::DUE_DATE_TIME]],
      ],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $task instanceof PersonalTask ? $this->t('Save task') : $this->t('Add task'),
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $title = trim((string) $form_state->getValue('title'));
    if ($title === '') {
      $form_state->setErrorByName('title', $this->t('Enter a task.'));
    }

    $mode = (string) $form_state->getValue('due_mode');
    if (!in_array($mode, [PersonalTask::DUE_NONE, PersonalTask::DUE_DATE, PersonalTask::DUE_DATE_TIME], TRUE)) {
      $form_state->setErrorByName('due_mode', $this->t('Select a valid due mode.'));
      return;
    }
    if ($mode === PersonalTask::DUE_DATE && trim((string) $form_state->getValue('due_date')) === '') {
      $form_state->setErrorByName('due_date', $this->t('Enter a due date.'));
    }
    if ($mode === PersonalTask::DUE_DATE_TIME && !($form_state->getValue('due_at') instanceof DrupalDateTime)) {
      $form_state->setErrorByName('due_at', $this->t('Enter a due date and time.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $mode = (string) $form_state->getValue('due_mode');
    $dueDate = $mode === PersonalTask::DUE_DATE ? (string) $form_state->getValue('due_date') : NULL;
    $dueAt = NULL;
    if ($mode === PersonalTask::DUE_DATE_TIME) {
      $dateTime = $form_state->getValue('due_at');
      if ($dateTime instanceof DrupalDateTime) {
        $dueAt = $dateTime->format('Y-m-d H:i');
      }
    }

    try {
      if ($this->taskId() > 0) {
        $this->taskMutations->editTask(
          $this->taskId(),
          (string) $form_state->getValue('title'),
          $mode,
          $dueDate,
          $dueAt,
        );
        $this->messenger()->addStatus($this->t('Task saved.'));
      }
      else {
        $this->taskMutations->createTask(
          (string) $form_state->getValue('title'),
          (int) $form_state->getValue('household'),
          $mode,
          $dueDate,
          $dueAt,
        );
        $this->messenger()->addStatus($this->t('Task added.'));
      }
    }
    catch (InvalidArgumentException) {
      $this->messenger()->addError($this->t('The task could not be saved because its current authorization or data is no longer valid.'));
    }

    $form_state->setRedirect('personal_secretary.my_tasks');
  }

  private function loadEditableTask(): PersonalTask {
    try {
      $task = $this->taskMutations->requireCurrentTask($this->taskId());
    }
    catch (InvalidArgumentException $exception) {
      throw new NotFoundHttpException('The requested PersonalTask is unavailable.', $exception);
    }
    if ((string) $task->get('status')->value !== PersonalTask::STATUS_OPEN) {
      throw new AccessDeniedHttpException('Completed PersonalTask must be reopened before editing.');
    }
    return $task;
  }

  private function taskId(): int {
    return (int) ($this->taskRouteMatch->getParameter('task') ?? 0);
  }

}
