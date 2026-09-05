<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\personal_secretary\Entity\PersonalTask;
use Drupal\personal_secretary\Service\PersonalTaskMutationService;
use Drupal\personal_secretary\Service\PersonalTaskQueryService;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders authorized PersonalTask application surfaces.
 */
final class PersonalTaskController extends ControllerBase {

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

  public function mine(): array {
    try {
      $items = $this->taskQuery->myOpenTasks();
    }
    catch (InvalidArgumentException) {
      return [
        '#cache' => ['max-age' => 0],
        'remediation' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Link your account to a valid Household member to see My tasks.'),
        ],
      ];
    }

    $build = [
      '#cache' => ['max-age' => 0],
      'add' => [
        '#type' => 'link',
        '#title' => $this->t('Add task'),
        '#url' => Url::fromRoute('personal_secretary.add_task'),
      ],
    ];

    if ($items === []) {
      $build['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No open tasks.'),
      ];
      return $build;
    }

    $build['items'] = ['#type' => 'container'];
    foreach ($items as $delta => $item) {
      $id = (int) $item['id'];
      $build['items'][$delta] = [
        '#type' => 'container',
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'strong',
          '#value' => $this->t('@task', ['@task' => (string) $item['title']]),
        ],
      ];
      if ((string) $item['due_label'] !== '') {
        $build['items'][$delta]['due'] = [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $item['overdue']
            ? $this->t('Overdue: @due', ['@due' => (string) $item['due_label']])
            : $this->t('Due: @due', ['@due' => (string) $item['due_label']]),
        ];
      }
      $build['items'][$delta]['edit'] = [
        '#type' => 'link',
        '#title' => $this->t('Edit'),
        '#url' => Url::fromRoute('personal_secretary.edit_task', ['task' => $id]),
      ];
      $build['items'][$delta]['complete'] = [
        '#type' => 'link',
        '#title' => $this->t('Mark complete'),
        '#url' => Url::fromRoute('personal_secretary.complete_task', ['task' => $id]),
      ];
      $build['items'][$delta]['delete'] = [
        '#type' => 'link',
        '#title' => $this->t('Delete'),
        '#url' => Url::fromRoute('personal_secretary.delete_task', ['task' => $id]),
      ];
    }

    return $build;
  }

  public function status(int $task): array {
    try {
      $entity = $this->taskMutations->requireCurrentTask($task);
    }
    catch (InvalidArgumentException $exception) {
      throw new NotFoundHttpException('The requested PersonalTask is unavailable.', $exception);
    }

    $item = $this->taskQuery->present($entity);
    $build = [
      '#cache' => ['max-age' => 0],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Task: @task', ['@task' => (string) $item['title']]),
      ],
      'status' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => (string) $entity->get('status')->value === PersonalTask::STATUS_COMPLETED
          ? $this->t('Completed')
          : $this->t('Open'),
      ],
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('Back to My tasks'),
        '#url' => Url::fromRoute('personal_secretary.my_tasks'),
      ],
    ];

    if ((string) $entity->get('status')->value === PersonalTask::STATUS_COMPLETED) {
      $build['reopen'] = [
        '#type' => 'link',
        '#title' => $this->t('Reopen task'),
        '#url' => Url::fromRoute('personal_secretary.reopen_task', ['task' => (int) $entity->id()]),
      ];
    }
    else {
      $build['edit'] = [
        '#type' => 'link',
        '#title' => $this->t('Edit task'),
        '#url' => Url::fromRoute('personal_secretary.edit_task', ['task' => (int) $entity->id()]),
      ];
    }

    $build['delete'] = [
      '#type' => 'link',
      '#title' => $this->t('Delete task'),
      '#url' => Url::fromRoute('personal_secretary.delete_task', ['task' => (int) $entity->id()]),
    ];

    return $build;
  }

}
