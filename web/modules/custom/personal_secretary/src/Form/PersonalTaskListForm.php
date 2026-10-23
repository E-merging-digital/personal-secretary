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

    [$statusFilter, $titleFilter] = $this->readFilters($this->getRequest()->query->all());
    $form['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ps-task-filters']],
      'title' => [
        '#type' => 'textfield',
        '#title' => $this->t('Search task titles'),
        '#default_value' => $titleFilter,
        '#maxlength' => 100,
        '#size' => 32,
      ],
      'status' => [
        '#type' => 'select',
        '#title' => $this->t('Task status filter'),
        '#options' => [
          'open' => $this->t('Open'),
          'completed' => $this->t('Completed'),
          'all' => $this->t('All'),
        ],
        '#default_value' => $statusFilter,
      ],
      'apply' => [
        '#type' => 'submit',
        '#value' => $this->t('Search tasks'),
        '#name' => 'filter_tasks',
        '#submit' => ['::submitFilter'],
      ],
      'reset' => [
        '#type' => 'link',
        '#title' => $this->t('Clear filters'),
        '#url' => Url::fromRoute('personal_secretary.my_tasks'),
      ],
    ];

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
      $items = $this->taskQuery->myTasks($this->statusConstant($statusFilter), $titleFilter);
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
        '#value' => $titleFilter !== ''
          ? $this->t('No tasks match this search.')
          : ($statusFilter === 'completed'
            ? $this->t('No completed tasks.')
            : ($statusFilter === 'all'
              ? $this->t('No tasks found.')
              : $this->t('No open tasks.'))),
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
      if ($item['overdue'] && $item['status'] === PersonalTask::STATUS_OPEN) {
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
          '#type' => 'link',
          '#title' => (string) $item['title'],
          '#url' => Url::fromRoute('personal_secretary.task_status', ['task' => $id]),
          '#attributes' => ['class' => ['ps-task-row__title']],
        ],
      ];

      if ($statusFilter !== 'open') {
        $form['items'][$delta]['status'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $item['status'] === PersonalTask::STATUS_COMPLETED
            ? $this->t('Completed')
            : $this->t('Open'),
          '#attributes' => ['class' => ['ps-task-row__status']],
        ];
      }

      if ((string) $item['due_label'] !== '') {
        $form['items'][$delta]['due'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $item['overdue'] && $item['status'] === PersonalTask::STATUS_OPEN
            ? $this->t('Overdue: @due', ['@due' => (string) $item['due_label']])
            : $this->t('Due: @due', ['@due' => (string) $item['due_label']]),
          '#attributes' => ['class' => ['ps-task-row__due']],
        ];
      }

      if ($item['status'] !== PersonalTask::STATUS_OPEN) {
        continue;
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

  /**
   * Search is read-only and uses a dedicated submit callback.
   *
   * It must never fall through to task mutation dispatch.
   */
  public function submitFilter(array &$form, FormStateInterface $form_state): void {
    [$status, $title] = $this->readFilters([
      'status' => $form_state->getValue('status')
        ?? $form_state->getValue(['filters', 'status']),
      'q' => $form_state->getValue('title')
        ?? $form_state->getValue(['filters', 'title']),
    ]);
    $form_state->setRedirect('personal_secretary.my_tasks', [], [
      'query' => $this->filterQuery($status, $title),
    ]);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $taskId = (int) ($trigger['#task_id'] ?? 0);
    $action = (string) ($trigger['#task_action'] ?? '');

    [$status, $title] = $this->readFilters($this->getRequest()->query->all());
    $filterQuery = $this->filterQuery($status, $title);

    try {
      if ($action === 'complete') {
        $this->taskMutations->completeTask($taskId);
        $this->messenger()->addStatus(
          $this->t('Task completed. You can reopen it below if this was accidental.'),
        );
        $form_state->setRedirect(
          'personal_secretary.my_tasks',
          [],
          ['query' => ['reopen_task' => $taskId] + $filterQuery],
        );
        return;
      }

      if ($action === 'reopen') {
        $this->taskMutations->reopenTask($taskId);
        $this->messenger()->addStatus($this->t('Task reopened.'));
        $form_state->setRedirect('personal_secretary.my_tasks', [], ['query' => $filterQuery]);
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

  /**
   * @param array<string, mixed> $input
   *
   * @return array{string, string}
   */
  private function readFilters(array $input): array {
    $status = $input['status'] ?? 'open';
    $status = is_string($status) && in_array($status, ['open', 'completed', 'all'], TRUE)
      ? $status
      : 'open';
    $title = $input['q'] ?? '';
    $title = is_string($title) ? trim($title) : '';
    if (mb_strlen($title) > 100) {
      $title = '';
    }
    return [$status, $title];
  }

  private function statusConstant(string $status): string {
    return match ($status) {
      'completed' => PersonalTask::STATUS_COMPLETED,
      'all' => 'ALL',
      default => PersonalTask::STATUS_OPEN,
    };
  }

  /**
   * @return array<string, string>
   */
  private function filterQuery(string $status, string $title): array {
    $query = [];
    if ($status !== 'open') {
      $query['status'] = $status;
    }
    if ($title !== '') {
      $query['q'] = $title;
    }
    return $query;
  }

}
