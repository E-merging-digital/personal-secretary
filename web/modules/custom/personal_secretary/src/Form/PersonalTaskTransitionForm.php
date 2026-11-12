<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\personal_secretary\Entity\PersonalTask;
use Drupal\personal_secretary\Service\PersonalTaskMutationService;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirms complete, reopen, or delete for one authorized PersonalTask.
 */
final class PersonalTaskTransitionForm extends ConfirmFormBase {

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
    return 'personal_secretary_personal_task_' . $this->action();
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);
    if ($this->action() === 'delete') {
      // Keep only bounded navigation context on the confirmation POST.
      $form['#action'] = Url::fromRoute('personal_secretary.delete_task', ['task' => $this->taskId()], [
        'query' => $this->deleteReturnQuery(),
      ])->toString();
    }
    return $form;
  }

  public function getQuestion(): TranslatableMarkup {
    $task = $this->loadTask();
    return match ($this->action()) {
      'complete' => $this->t('Mark %task complete?', ['%task' => (string) $task->label()]),
      'reopen' => $this->t('Reopen %task?', ['%task' => (string) $task->label()]),
      'delete' => $this->t('Delete %task?', ['%task' => (string) $task->label()]),
      default => throw new NotFoundHttpException('Unknown PersonalTask action.'),
    };
  }

  public function getCancelUrl(): Url {
    if ($this->action() !== 'delete') {
      return Url::fromRoute('personal_secretary.my_tasks');
    }

    $query = $this->deleteReturnQuery();
    return isset($query['from'])
      ? Url::fromRoute('personal_secretary.today')
      : Url::fromRoute('personal_secretary.my_tasks', [], ['query' => $query]);
  }

  public function getConfirmText(): TranslatableMarkup {
    return match ($this->action()) {
      'complete' => $this->t('Mark complete'),
      'reopen' => $this->t('Reopen task'),
      'delete' => $this->t('Delete task'),
      default => $this->t('Confirm'),
    };
  }

  public function getDescription() {
    return match ($this->action()) {
      'complete', 'reopen' => $this->t('This task status can be changed again later.'),
      'delete' => parent::getDescription(),
      default => $this->t('Confirm this task action.'),
    };
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      if ($this->action() === 'complete') {
        $this->taskMutations->completeTask($this->taskId());
        $this->messenger()->addStatus($this->t('Task completed. You can reopen it below if this was accidental.'));
        $form_state->setRedirect(
          'personal_secretary.my_tasks',
          [],
          ['query' => ['reopen_task' => $this->taskId()]],
        );
        return;
      }
      if ($this->action() === 'reopen') {
        $this->taskMutations->reopenTask($this->taskId());
        $this->messenger()->addStatus($this->t('Task reopened.'));
        $form_state->setRedirect('personal_secretary.my_tasks');
        return;
      }
      if ($this->action() === 'delete') {
        $this->taskMutations->deleteTask($this->taskId());
        $this->messenger()->addStatus($this->t('Task deleted.'));
        $this->redirectAfterDelete($form_state);
        return;
      }
    }
    catch (InvalidArgumentException) {
      $this->messenger()->addError($this->t('This task action is no longer authorized or valid.'));
      if ($this->action() === 'delete') {
        $this->redirectAfterDelete($form_state);
      }
      else {
        $form_state->setRedirect('personal_secretary.my_tasks');
      }
      return;
    }

    throw new NotFoundHttpException('Unknown PersonalTask action.');
  }

  /**
   * Redirects only to a known product route after a confirmed delete.
   */
  private function redirectAfterDelete(FormStateInterface $form_state): void {
    $query = $this->deleteReturnQuery();
    if (isset($query['from'])) {
      $form_state->setRedirect('personal_secretary.today');
      return;
    }
    $form_state->setRedirect('personal_secretary.my_tasks', [], ['query' => $query]);
  }

  /**
   * Validated presentation context, never an authorization or destination.
   *
   * @return array<string, string>
   */
  private function deleteReturnQuery(): array {
    $query = $this->getRequest()->query->all();
    if (($query['from'] ?? NULL) === 'today') {
      return ['from' => 'today'];
    }

    $filters = [];
    $status = $query['status'] ?? NULL;
    if (is_string($status) && in_array($status, ['completed', 'all'], TRUE)) {
      $filters['status'] = $status;
    }
    $title = $query['q'] ?? NULL;
    if (is_string($title)) {
      $title = trim($title);
      if ($title !== '' && mb_strlen($title) <= 100) {
        $filters['q'] = $title;
      }
    }
    if (($query['sort'] ?? NULL) === 'title') {
      $filters['sort'] = 'title';
    }
    if (!isset($filters['status']) && ($query['urgency'] ?? NULL) === 'overdue') {
      $filters['urgency'] = 'overdue';
    }
    return $filters;
  }

  private function loadTask(): PersonalTask {
    try {
      return $this->taskMutations->requireCurrentTask($this->taskId());
    }
    catch (InvalidArgumentException $exception) {
      throw new NotFoundHttpException('The requested PersonalTask is unavailable.', $exception);
    }
  }

  private function taskId(): int {
    return (int) $this->taskRouteMatch->getParameter('task');
  }

  private function action(): string {
    return (string) ($this->taskRouteMatch->getRouteObject()?->getDefault('_task_action') ?? '');
  }

}
