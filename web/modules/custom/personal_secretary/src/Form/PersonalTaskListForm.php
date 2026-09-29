<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\personal_secretary\Entity\PersonalTask;
use Drupal\personal_secretary\Service\PersonalTaskMutationService;
use Drupal\personal_secretary\Service\PersonalTaskQueryService;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders My tasks and safe everyday complete/reopen actions.
 */
final class PersonalTaskListForm extends FormBase {

  public function __construct(
    private readonly PersonalTaskQueryService $taskQuery,
    private readonly PersonalTaskMutationService $taskMutations,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.personal_task_query'),
      $container->get('personal_secretary.personal_task_mutation'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_personal_task_list';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#cache'] = ['max-age' => 0];
    $form['#attributes']['class'][] = 'ps-task-list-form';

    $form['toolbar'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ps-task-list__toolbar']],
      'add' => [
        '#type' => 'link',
        '#title' => $this->t('Add task'),
        '#url' => Url::fromRoute('personal_secretary.add_task'),
        '#attributes' => ['class' => ['ps-task-list__add']],
      ],
    ];

    $reopenTaskId = $this->getRequest()->query->getInt('reopen_task');
    if ($reopenTaskId > 0) {
      try {
        $completed = $this->taskMutations->requireCurrentTask($reopenTaskId);
        if ((string) $completed->get('status')->value === PersonalTask::STATUS_COMPLETED) {
          $form['undo'] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['ps-task-undo']],
            'reopen' => [
              '#type' => 'submit',
              '#value' => $this->t('Reopen task'),
              '#name' => 'reopen_task_' . $reopenTaskId,
              '#task_id' => $reopenTaskId,
              '#task_action' => 'reopen',
              '#attributes' => ['class' => ['ps-task-undo__action']],
            ],
          ];
        }
      }
      catch (InvalidArgumentException) {
        // Do not disclose inaccessible or stale task identifiers.
      }
    }

    try {
      $items = $this->taskQuery->myOpenTasks();
    }
    catch (InvalidArgumentException) {
      $form['remediation'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Link your account to a valid Household member to see My tasks.'),
      ];
      return $form;
    }

    if ($items === []) {
      $form['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No open tasks.'),
        '#attributes' => ['class' => ['ps-empty-state']],
      ];
      return $form;
    }

    $form['items'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ps-task-list'],
        'role' => 'list',
      ],
    ];

    foreach ($items as $delta => $item) {
      $id = (int) $item['id'];
      $rowClasses = ['ps-task-row'];
      if ($item['overdue']) {
        $rowClasses[] = 'ps-task-row--overdue';
      }

      $form['items'][$delta] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => $rowClasses,
          'data-ps-task-id' => (string) $id,
          'role' => 'listitem',
        ],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $this->t('@task', ['@task' => (string) $item['title']]),
          '#attributes' => ['class' => ['ps-task-row__title']],
        ],
      ];

      if ((string) $item['due_label'] !== '') {
        $form['items'][$delta]['due'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $item['overdue']
            ? $this->t('Overdue: @due', ['@due' => (string) $item['due_label']])
            : $this->t('Due: @due', ['@due' => (string) $item['due_label']]),
          '#attributes' => ['class' => ['ps-task-row__due']],
        ];
      }

      $form['items'][$delta]['actions'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ps-task-row__actions']],
        'complete' => [
          '#type' => 'submit',
          '#value' => $this->t('Mark complete'),
          '#name' => 'complete_task_' . $id,
          '#task_id' => $id,
          '#task_action' => 'complete',
          '#button_type' => 'primary',
          '#attributes' => ['class' => ['ps-task-row__complete']],
        ],
        'edit' => [
          '#type' => 'link',
          '#title' => $this->t('Edit'),
          '#url' => Url::fromRoute('personal_secretary.edit_task', ['task' => $id]),
          '#attributes' => ['class' => ['ps-task-row__edit']],
        ],
        'delete' => [
          '#type' => 'link',
          '#title' => $this->t('Delete'),
          '#url' => Url::fromRoute('personal_secretary.delete_task', ['task' => $id]),
          '#attributes' => ['class' => ['ps-task-row__delete']],
        ],
      ];
    }

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $taskId = (int) ($trigger['#task_id'] ?? 0);
    $action = (string) ($trigger['#task_action'] ?? '');

    try {
      if ($action === 'complete') {
        $this->taskMutations->completeTask($taskId);
        $this->messenger()->addStatus(
          $this->t('Task completed. You can reopen it below if this was accidental.'),
        );
        $form_state->setRedirect(
          'personal_secretary.my_tasks',
          [],
          ['query' => ['reopen_task' => $taskId]],
        );
        return;
      }

      if ($action === 'reopen') {
        $this->taskMutations->reopenTask($taskId);
        $this->messenger()->addStatus($this->t('Task reopened.'));
        $form_state->setRedirect('personal_secretary.my_tasks');
        return;
      }
    }
    catch (InvalidArgumentException) {
      $this->messenger()->addError(
        $this->t('This task action is no longer authorized or valid.'),
      );
      $form_state->setRedirect('personal_secretary.my_tasks');
      return;
    }

    $this->messenger()->addError(
      $this->t('This task action is no longer authorized or valid.'),
    );
    $form_state->setRedirect('personal_secretary.my_tasks');
  }

}
