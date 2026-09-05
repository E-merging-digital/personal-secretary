<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\personal_secretary\Service\TodayService;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the derived current-user Today surface.
 */
final class TodayController extends ControllerBase {

  public function __construct(
    private readonly TodayService $todayService,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.today'),
    );
  }

  public function build(): array {
    try {
      $today = $this->todayService->today();
    }
    catch (InvalidArgumentException) {
      $build = [
        '#cache' => ['max-age' => 0],
        'remediation' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Link your account to a valid Household member to see Today.'),
        ],
      ];
      if ($this->currentUser()->hasPermission(HouseholdAuthorizationService::ADMIN_PERMISSION)) {
        $build['link_current_user_to_person'] = [
          '#type' => 'link',
          '#title' => $this->t('Link my account to household member'),
          '#url' => Url::fromRoute('personal_secretary.link_current_user_to_person'),
        ];
      }
      return $build;
    }

    $build = [
      '#cache' => ['max-age' => 0],
      'day' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Today: @date (@timezone)', [
          '@date' => $today['local_date'],
          '@timezone' => $today['timezone'],
        ]),
      ],
      'tasks' => [
        '#type' => 'container',
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $this->t('Tasks'),
        ],
        'all' => [
          '#type' => 'link',
          '#title' => $this->t('My tasks'),
          '#url' => Url::fromRoute('personal_secretary.my_tasks'),
        ],
      ],
    ];

    if ($today['tasks'] === []) {
      $build['tasks']['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No tasks are due or overdue today.'),
      ];
    }
    else {
      $build['tasks']['items'] = ['#type' => 'container'];
      foreach ($today['tasks'] as $delta => $item) {
        $id = (int) $item['id'];
        $build['tasks']['items'][$delta] = [
          '#type' => 'container',
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'strong',
            '#value' => $this->t('@task', ['@task' => (string) $item['title']]),
          ],
          'due' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $item['overdue']
              ? $this->t('Overdue: @due', ['@due' => (string) $item['due_label']])
              : $this->t('Due: @due', ['@due' => (string) $item['due_label']]),
          ],
          'status' => [
            '#type' => 'link',
            '#title' => $this->t('Task status'),
            '#url' => Url::fromRoute('personal_secretary.task_status', ['task' => $id]),
          ],
          'edit' => [
            '#type' => 'link',
            '#title' => $this->t('Edit'),
            '#url' => Url::fromRoute('personal_secretary.edit_task', ['task' => $id]),
          ],
          'complete' => [
            '#type' => 'link',
            '#title' => $this->t('Mark complete'),
            '#url' => Url::fromRoute('personal_secretary.complete_task', ['task' => $id]),
          ],
        ];
      }
    }

    $build['preparations'] = [
      '#type' => 'container',
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Preparations'),
      ],
      'all' => [
        '#type' => 'link',
        '#title' => $this->t('My preparations'),
        '#url' => Url::fromRoute('personal_secretary.my_preparations'),
      ],
    ];

    if ($today['preparations'] === []) {
      $build['preparations']['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No active preparations are overdue or due today.'),
      ];
    }
    else {
      $build['preparations']['items'] = ['#type' => 'container'];
      foreach ($today['preparations'] as $delta => $item) {
        $build['preparations']['items'][$delta] = [
          '#type' => 'component',
          '#component' => 'personal_secretary:preparation-item',
          '#props' => $item,
        ];
      }
    }

    $build['activities'] = [
      '#type' => 'container',
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Activities'),
      ],
      'all' => [
        '#type' => 'link',
        '#title' => $this->t('My upcoming'),
        '#url' => Url::fromRoute('personal_secretary.my_upcoming'),
      ],
    ];

    if ($today['activities'] === []) {
      $build['activities']['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No activities concern you today.'),
      ];
    }
    else {
      $build['activities']['items'] = ['#type' => 'container'];
      foreach ($today['activities'] as $delta => $item) {
        $build['activities']['items'][$delta] = [
          '#type' => 'component',
          '#component' => 'personal_secretary:today-activity',
          '#props' => [
            'activity_label' => $item['activity_label'],
            'effective_start' => $item['effective_start'],
            'effective_end' => $item['effective_end'],
            'effective_start_iso' => $item['effective_start_iso'],
            'effective_end_iso' => $item['effective_end_iso'],
            'display_timezone' => $item['display_timezone'],
          ],
        ];
      }
    }

    return $build;
  }

}
