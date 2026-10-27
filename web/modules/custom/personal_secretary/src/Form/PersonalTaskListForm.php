<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormBuilderInterface;
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

  // Core Form API caches rebuilt AJAX forms between authenticated POSTs.
  // Keep these injected services writable for Drupal's dependency restoration.
  public function __construct(
    protected PersonalTaskQueryService $taskQuery,
    protected PersonalTaskMutationService $taskMutations,
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

    // Keep the GET/PRG Undo on ordinary pages. AJAX transitions carry their
    // current Undo target in server FormState, never in a browser-supplied ID.
    $form['live'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'ps-task-live'],
    ];
    $ajaxResult = $form_state->get('task_ajax_result');
    $form['live']['feedback'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => is_array($ajaxResult) ? (string) ($ajaxResult['message'] ?? '') : '',
      '#attributes' => [
        'id' => 'ps-task-feedback',
        'class' => ['ps-task-feedback'],
        'role' => 'status',
        'aria-live' => 'polite',
        'aria-atomic' => 'true',
        'tabindex' => '-1',
      ],
    ];
    $ajax = $this->taskAjaxOptions($statusFilter, $titleFilter);
    $stateUndoId = $form_state->get('task_ajax_undo_task_id');
    $reopenTaskId = is_int($stateUndoId)
      ? $stateUndoId
      : $this->getRequest()->query->getInt('reopen_task');
    if ($reopenTaskId > 0) {
      try {
        $completed = $this->taskMutations->requireCurrentTask($reopenTaskId);
        if ((string) $completed->get('status')->value === PersonalTask::STATUS_COMPLETED) {
          $form['live']['undo'] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['ps-task-undo']],
            'reopen' => [
              '#type' => 'submit',
              '#value' => $this->t('Reopen task'),
              '#name' => 'reopen_task_' . $reopenTaskId,
              '#task_id' => $reopenTaskId,
              '#task_action' => 'reopen',
              '#ajax' => $ajax,
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
      $form['live']['remediation'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Link your account to a valid Household member to see My tasks.'),
      ];
      return $form;
    }

    if ($items === []) {
      $form['live']['empty'] = [
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

    $form['live']['items'] = [
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

      $form['live']['items'][$delta] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => $rowClasses,
          'data-ps-task-id' => (string) $id,
          'role' => 'listitem',
        ],
        'title' => [
          '#type' => 'link',
          '#title' => (string) $item['title'],
          '#url' => Url::fromRoute(
            'personal_secretary.task_status',
            ['task' => $id],
            ['query' => $this->filterQuery($statusFilter, $titleFilter)],
          ),
          '#attributes' => ['class' => ['ps-task-row__title']],
        ],
      ];

      if ($statusFilter !== 'open') {
        $form['live']['items'][$delta]['status'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $item['status'] === PersonalTask::STATUS_COMPLETED
            ? $this->t('Completed')
            : $this->t('Open'),
          '#attributes' => ['class' => ['ps-task-row__status']],
        ];
      }

      if ((string) $item['due_label'] !== '') {
        $form['live']['items'][$delta]['due'] = [
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

      $form['live']['items'][$delta]['actions'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ps-task-row__actions']],
        'complete' => [
          '#type' => 'submit',
          '#value' => $this->t('Mark complete'),
          '#name' => 'complete_task_' . $id,
          '#task_id' => $id,
          '#task_action' => 'complete',
          '#ajax' => $ajax,
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
   * Replaces only the server-rebuilt task results after an authorized POST.
   */
  public function ajaxTaskTransition(array &$form, FormStateInterface $form_state): AjaxResponse {
    $result = $form_state->get('task_ajax_result');
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#ps-task-live', $form['live']));

    $focus = '#ps-task-feedback';
    if (is_array($result) && !empty($result['ok'])) {
      $taskId = (int) ($result['task_id'] ?? 0);
      if ($result['action'] === 'complete') {
        $focus = '#ps-task-live .ps-task-undo__action';
      }
      elseif ($result['action'] === 'reopen' && $taskId > 0) {
        $focus = '#ps-task-live [data-ps-task-id="' . $taskId . '"] .ps-task-row__complete';
      }
    }
    $response->addCommand(new InvokeCommand($focus, 'focus'));
    return $response;
  }

  /**
   * Keeps current status/title filters on the Core Form AJAX POST endpoint.
   *
   * @return array<string, mixed>
   *   Core Form API AJAX button settings.
   */
  private function taskAjaxOptions(string $status, string $title): array {
    return [
      'callback' => '::ajaxTaskTransition',
      'url' => Url::fromRoute('personal_secretary.my_tasks'),
      'options' => [
        'query' => $this->filterQuery($status, $title) + [
          FormBuilderInterface::AJAX_FORM_REQUEST => TRUE,
        ],
      ],
      'wrapper' => 'ps-task-live',
      'progress' => ['type' => 'throbber'],
    ];
  }

  /**
   * Search is read-only and uses a dedicated submit callback.
   *
   * It must never fall through to task mutation dispatch.
   */
  public function submitFilter(array &$form, FormStateInterface $form_state): void {
    // Read the actual submitted field names; the container may flatten them.
    $input = $form_state->getUserInput();
    [$status, $title] = $this->readFilters([
      'status' => $input['status'] ?? $input['filters']['status']
        ?? $form_state->getValue('status')
        ?? $form_state->getValue(['filters', 'status']),
      'q' => $input['title'] ?? $input['filters']['title']
        ?? $form_state->getValue('title')
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
    $isAjax = isset($trigger['#ajax']) && $this->getRequest()->isXmlHttpRequest();

    [$status, $title] = $this->readFilters($this->getRequest()->query->all());
    $filterQuery = $this->filterQuery($status, $title);

    try {
      if ($action === 'complete') {
        $changed = $this->taskMutations->completeTask($taskId);
        $message = $this->t('Task completed. You can reopen it below if this was accidental.');
        if ($isAjax) {
          $this->setAjaxResult($form_state, $changed, $action, $taskId, $changed
            ? $message
            : $this->t('This task action is no longer authorized or valid.'));
          if ($changed) {
            $form_state->set('task_ajax_undo_task_id', $taskId);
          }
          return;
        }

        $this->messenger()->addStatus($message);
        $form_state->setRedirect(
          'personal_secretary.my_tasks',
          [],
          ['query' => ['reopen_task' => $taskId] + $filterQuery],
        );
        return;
      }

      if ($action === 'reopen') {
        $changed = $this->taskMutations->reopenTask($taskId);
        $message = $this->t('Task reopened.');
        if ($isAjax) {
          $this->setAjaxResult($form_state, $changed, $action, $taskId, $changed
            ? $message
            : $this->t('This task action is no longer authorized or valid.'));
          $form_state->set('task_ajax_undo_task_id', 0);
          return;
        }

        $this->messenger()->addStatus($message);
        $form_state->setRedirect('personal_secretary.my_tasks', [], ['query' => $filterQuery]);
        return;
      }
    }
    catch (InvalidArgumentException) {
      if ($isAjax) {
        $this->setAjaxResult($form_state, FALSE, $action, $taskId,
          $this->t('This task action is no longer authorized or valid.'));
        return;
      }
      $this->messenger()->addError(
        $this->t('This task action is no longer authorized or valid.'),
      );
      $form_state->setRedirect('personal_secretary.my_tasks');
      return;
    }

    if ($isAjax) {
      $this->setAjaxResult($form_state, FALSE, $action, $taskId,
        $this->t('This task action is no longer authorized or valid.'));
      return;
    }
    $this->messenger()->addError(
      $this->t('This task action is no longer authorized or valid.'),
    );
    $form_state->setRedirect('personal_secretary.my_tasks');
  }

  /**
   * Saves an AJAX outcome only after the domain service checked the mutation.
   */
  private function setAjaxResult(
    FormStateInterface $form_state,
    bool $ok,
    string $action,
    int $taskId,
    \Drupal\Core\StringTranslation\TranslatableMarkup $message,
  ): void {
    $form_state->set('task_ajax_result', [
      'ok' => $ok,
      'action' => $action,
      'task_id' => $taskId,
      'message' => (string) $message,
    ]);
    $form_state->setRebuild();
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
