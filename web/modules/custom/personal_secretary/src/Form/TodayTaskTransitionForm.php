<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\personal_secretary\Entity\PersonalTask;
use Drupal\personal_secretary\Service\PersonalTaskMutationService;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One-click Today task transitions, authorized again on Form API POST.
 */
final class TodayTaskTransitionForm extends FormBase {

  public function __construct(
    private readonly PersonalTaskMutationService $taskMutations,
    private readonly RouteMatchInterface $currentRouteMatch,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.personal_task_mutation'),
      $container->get('current_route_match'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_today_task_transition';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?string $explicitAction = NULL,
    ?int $explicitTaskId = NULL,
  ): array {
    $action = $explicitAction ?? $this->action();
    $taskId = $explicitTaskId ?? $this->taskId();
    $route = $this->routeForAction($action);

    if ($taskId <= 0) {
      throw new NotFoundHttpException('The requested task is unavailable.');
    }

    try {
      $task = $this->taskMutations->requireCurrentTask($taskId);
      $expectedStatus = $action === 'complete'
        ? PersonalTask::STATUS_OPEN
        : PersonalTask::STATUS_COMPLETED;
      if ((string) $task->get('status')->value !== $expectedStatus) {
        throw new InvalidArgumentException('The task transition is stale.');
      }
    }
    catch (InvalidArgumentException $exception) {
      throw new NotFoundHttpException('The requested task is unavailable.', $exception);
    }

    $form['#action'] = Url::fromRoute($route, ['task' => $taskId])->toString();
    $form['#attributes']['class'][] = 'ps-today-task-action-form';
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $action === 'complete' ? $this->t('Mark complete') : $this->t('Reopen task'),
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['ps-today-task-action-form__submit']],
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      if ($this->action() === 'complete') {
        $this->taskMutations->completeTask($this->taskId());
        $this->messenger()->addStatus($this->t('Task completed. You can reopen it below if this was accidental.'));
        $form_state->setRedirect('personal_secretary.today', [], [
          'query' => ['reopen_task' => $this->taskId()],
        ]);
        return;
      }

      if ($this->action() === 'reopen') {
        $this->taskMutations->reopenTask($this->taskId());
        $this->messenger()->addStatus($this->t('Task reopened.'));
        $form_state->setRedirect('personal_secretary.today');
        return;
      }
    }
    catch (InvalidArgumentException) {
      $this->messenger()->addError($this->t('This task action is no longer authorized or valid.'));
      $form_state->setRedirect('personal_secretary.today');
      return;
    }

    throw new NotFoundHttpException('Unknown Today task action.');
  }

  private function action(): string {
    return (string) ($this->currentRouteMatch->getRouteObject()?->getDefault('_today_task_action') ?? '');
  }

  private function taskId(): int {
    return (int) $this->currentRouteMatch->getParameter('task');
  }

  private function routeForAction(string $action): string {
    return match ($action) {
      'complete' => 'personal_secretary.today_complete_task',
      'reopen' => 'personal_secretary.today_reopen_task',
      default => throw new NotFoundHttpException('Unknown Today task action.'),
    };
  }

}
