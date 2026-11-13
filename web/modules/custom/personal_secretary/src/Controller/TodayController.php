<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Drupal\personal_secretary\Entity\PersonalTask;
use Drupal\personal_secretary\Form\PreparationCompletionTransitionForm;
use Drupal\personal_secretary\Form\TodayTaskTransitionForm;
use Drupal\personal_secretary\Form\TodayQuickTaskForm;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\personal_secretary\Service\TodayService;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders the derived current-user Today surface.
 */
final class TodayController extends ControllerBase {

  public function __construct(
    private readonly TodayService $todayService,
    private readonly LanguageManagerInterface $todayLanguageManager,
    private readonly RequestStack $requestStack,
    private readonly DateFormatterInterface $todayDateFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.today'),
      $container->get('language_manager'),
      $container->get('request_stack'),
      $container->get('date.formatter'),
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

    // Format the existing source-local civil date for presentation only.
    $localNoon = new \DateTimeImmutable(
      $today['local_date'] . ' 12:00:00',
      new \DateTimeZone($today['timezone']),
    );
    $displayDate = $this->todayDateFormatter->format(
      $localNoon->getTimestamp(), 'custom', 'l j F Y', $today['timezone'],
      $this->todayLanguageManager->getCurrentLanguage()->getId(),
    );

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ps-today']],
      '#cache' => ['max-age' => 0],
      'day' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['ps-today__date-context']],
        '#value' => $this->t('Today: @date (@timezone)', [
          '@date' => $displayDate,
          '@timezone' => $today['timezone'],
        ]),
      ],
      'assistant' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ps-today-assistant']],
        'entry' => [
          '#type' => 'link',
          '#title' => $this->todayLanguageManager->getCurrentLanguage()->getId() === 'fr'
            ? $this->t('Parler à l’assistant')
            : $this->t('Talk to the assistant'),
          '#url' => Url::fromRoute('personal_secretary.guided_assistant'),
          '#attributes' => ['class' => ['ps-today-assistant__link']],
        ],
      ],
      'tasks' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ps-today-section', 'ps-today-section--tasks']],
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
        'add' => [
          '#type' => 'link',
          '#title' => $this->t('Add task'),
          '#url' => Url::fromRoute('personal_secretary.add_task', [], ['query' => ['from' => 'today']]),
          '#attributes' => ['class' => ['ps-task-list__add']],
        ],
        'quick' => $this->formBuilder()->getForm(TodayQuickTaskForm::class),
      ],
    ];

    // Offer Undo only for an authorized task that is still completed.
    $reopenTaskId = $this->requestStack->getCurrentRequest()?->query->getInt('reopen_task') ?? 0;
    if ($reopenTaskId > 0) {
      try {
        $build['tasks']['undo'] = $this->formBuilder()->getForm(
          TodayTaskTransitionForm::class, 'reopen', $reopenTaskId,
        );
      }
      catch (NotFoundHttpException) {
        // Do not reveal stale or inaccessible task identifiers.
      }
    }

    if ($today['tasks'] === []) {
      $build['tasks']['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No tasks are due or overdue today.'),
      ];
    }
    else {
      $build['tasks']['items'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ps-today-stack']],
      ];
      $overdueCount = count(array_filter($today['tasks'], static fn(array $item): bool => (bool) $item['overdue']));
      $showGroups = $overdueCount > 0 && $overdueCount < count($today['tasks']);
      if ($showGroups) {
        $build['tasks']['items']['#attributes']['class'] = ['ps-task-groups'];
        foreach (['overdue' => $this->t('Overdue tasks'), 'today' => $this->t('Due today')] as $key => $title) {
          $build['tasks']['items'][$key] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['ps-task-group']],
            'heading' => [
              '#type' => 'html_tag',
              '#tag' => 'h3',
              '#attributes' => ['class' => ['ps-task-group__heading']],
              '#value' => $title,
            ],
            'items' => [
              '#type' => 'container',
              '#attributes' => ['class' => ['ps-today-stack']],
            ],
          ];
        }
      }
      foreach ($today['tasks'] as $delta => $item) {
        $id = (int) $item['id'];
        $row = [
          '#type' => 'container',
          '#attributes' => ['class' => ['ps-card', 'ps-task-card']],
          'title' => [
            '#type' => 'link',
            '#title' => (string) $item['title'],
            '#url' => Url::fromRoute('personal_secretary.task_status', ['task' => $id], ['query' => ['from' => 'today']]),
            '#attributes' => ['class' => ['ps-task-card__title']],
          ],
          'due' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#attributes' => ['class' => ['ps-task-card__due']],
            '#value' => $item['overdue']
              ? $this->t('Overdue: @due', ['@due' => (string) $item['due_display_label']])
              : $this->t('Due: @due', ['@due' => (string) $item['due_display_label']]),
          ],

          'edit' => [
            '#type' => 'link',
            '#title' => $this->t('Edit'),
            '#url' => Url::fromRoute('personal_secretary.edit_task', ['task' => $id], ['query' => ['from' => 'today']]),
          ],
          'complete' => $this->formBuilder()->getForm(TodayTaskTransitionForm::class, 'complete', $id),
        ];
        if ($showGroups) {
          $build['tasks']['items'][$item['overdue'] ? 'overdue' : 'today']['items'][$delta] = $row;
        }
        else {
          $build['tasks']['items'][$delta] = $row;
        }
      }
    }

    $build['preparations'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ps-today-section', 'ps-today-section--preparations']],
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
        '#value' => $this->t('No unprepared active preparations are overdue or due today.'),
      ];
      $build['preparations']['explanation'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Preparations are added through activities. You can include a preparation when adding an activity.'),
      ];
      $build['preparations']['add_activity'] = [
        '#type' => 'link',
        '#title' => $this->t('Add an activity to prepare'),
        '#url' => Url::fromRoute('personal_secretary.add_activity', [], ['query' => ['from' => 'today']]),
        '#attributes' => ['class' => ['ps-upcoming-actions__add']],
      ];
    }
    else {
      $build['preparations']['items'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ps-today-stack']],
      ];
      $overdueCount = count(array_filter($today['preparations'], static fn(array $item): bool => (bool) $item['overdue']));
      $showGroups = $overdueCount > 0 && $overdueCount < count($today['preparations']);
      if ($showGroups) {
        $build['preparations']['items']['#attributes']['class'] = ['ps-task-groups'];
        foreach (['overdue' => $this->t('Overdue preparations'), 'today' => $this->t('Due today')] as $key => $title) {
          $build['preparations']['items'][$key] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['ps-task-group']],
            'heading' => [
              '#type' => 'html_tag',
              '#tag' => 'h3',
              '#attributes' => ['class' => ['ps-task-group__heading']],
              '#value' => $title,
            ],
            'items' => [
              '#type' => 'container',
              '#attributes' => ['class' => ['ps-today-stack']],
            ],
          ];
        }
      }
      foreach ($today['preparations'] as $delta => $item) {
        if (($item['prepared'] ?? FALSE) === TRUE) {
          throw new RuntimeException('Today preparation read exposed a prepared candidate.');
        }
        $row = [
          '#type' => 'container',
          '#attributes' => ['class' => ['ps-card', 'ps-preparation-card']],
          'item' => [
            '#type' => 'component',
            '#component' => 'personal_secretary:preparation-item',
            '#props' => $this->preparationProps($item),
          ],
          'detail' => [
            '#type' => 'link',
            '#title' => $this->t('View details'),
            '#url' => Url::fromRoute('personal_secretary.occurrence_detail', [
              'series' => (int) $item['_completion_series_id'],
              'original_occurrence_key' => (string) $item['_completion_original_occurrence_key'],
            ], ['query' => ['from' => 'today']]),
            '#attributes' => ['class' => ['ps-action-link']],
          ],
          'action' => $this->preparationActionForm($item, 'today'),
        ];
        if ($showGroups) {
          $build['preparations']['items'][$item['overdue'] ? 'overdue' : 'today']['items'][$delta] = $row;
        }
        else {
          $build['preparations']['items'][$delta] = $row;
        }
      }
    }

    $build['planning'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ps-today-section', 'ps-today-section--activities']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Planning'),
      ],
      'all' => [
        '#type' => 'link',
        '#title' => $this->t('My upcoming'),
        '#url' => Url::fromRoute('personal_secretary.my_upcoming'),
      ],
      'add' => [
        '#type' => 'link',
        '#title' => $this->t('Add activity'),
        '#url' => Url::fromRoute('personal_secretary.add_activity', [], ['query' => ['from' => 'today']]),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
    ];

    if ($today['planning'] === []) {
      $build['planning']['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No planning items concern you today.'),
      ];
    }
    else {
      $build['planning']['items'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ps-today-stack']],
      ];
      foreach ($today['planning'] as $delta => $planningItem) {
        $item = $planningItem['item'];
        $dateLabels = $this->planningDateLabels($item);
        if ($planningItem['kind'] === 'external_event') {
          $build['planning']['items'][$delta] = [
            '#type' => 'component',
            '#component' => 'personal_secretary:external-event-shadow',
            '#props' => [
              'title' => $item['title'],
              'location' => $item['location'],
              'all_day' => $item['all_day'],
              'all_day_start_date' => $item['all_day_start_date'],
              'all_day_end_date' => $item['all_day_end_date'],
              'effective_start' => $item['effective_start'],
              'effective_end' => $item['effective_end'],
              'effective_start_iso' => $item['effective_start_iso'],
              'effective_end_iso' => $item['effective_end_iso'],
              'display_start_label' => $dateLabels['display_start_label'],
              'display_end_label' => $dateLabels['display_end_label'],
              'display_timezone' => $item['display_timezone'],
              'source_timezone' => $item['source_timezone'],
              'source_label' => $item['source_label'],
              'busy_impact' => $item['busy_impact'],
            ],
          ];
          continue;
        }

        $build['planning']['items'][$delta] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['ps-today-activity-entry']],
          'activity' => [
            '#type' => 'component',
            '#component' => 'personal_secretary:today-activity',
            '#props' => [
              'activity_label' => $item['activity_label'],
              'location' => $item['location'],
              'concerned_person_labels' => $item['concerned_person_labels'],
              'all_day' => $item['all_day'],
              'all_day_start_date' => $item['all_day_start_date'],
              'all_day_end_date' => $item['all_day_end_date'],
              'effective_start' => $item['effective_start'],
              'effective_end' => $item['effective_end'],
              'effective_start_iso' => $item['effective_start_iso'],
              'effective_end_iso' => $item['effective_end_iso'],
              'display_start_label' => $dateLabels['display_start_label'],
              'display_end_label' => $dateLabels['display_end_label'],
              'display_timezone' => $item['display_timezone'],
              'source_timezone' => $item['source_timezone'],
            ],
          ],
          'detail' => [
            '#type' => 'link',
            '#title' => $this->t('View details'),
            '#url' => Url::fromRoute('personal_secretary.occurrence_detail', [
              'series' => (int) $item['series_id'],
              'original_occurrence_key' => (string) $item['original_occurrence_key'],
            ], ['query' => ['from' => 'today']]),
            '#attributes' => ['class' => ['ps-action-link']],
          ],
        ];
      }
    }

    return $build;
  }

  /**
   * Formats Today Planning labels without changing the underlying dates.
   *
   * @param array<string, mixed> $item
   *   Existing Today activity or external-event projection.
   *
   * @return array{display_start_label: string, display_end_label: string}
   *   Human-readable labels for the two Planning components.
   */
  private function planningDateLabels(array $item): array {
    $language = $this->todayLanguageManager->getCurrentLanguage()->getId();
    if ($item['all_day']) {
      // Civil dates are source-local, not UTC instants. Midday avoids
      // accidentally moving an all-day date across timezones.
      $timezone = (string) ($item['source_timezone'] ?: $item['display_timezone']);
      $zone = new \DateTimeZone($timezone);
      $start = new \DateTimeImmutable($item['all_day_start_date'] . ' 12:00:00', $zone);
      $end = new \DateTimeImmutable($item['all_day_end_date'] . ' 12:00:00', $zone);
      $pattern = 'j M Y';
    }
    else {
      $timezone = (string) $item['display_timezone'];
      $start = new \DateTimeImmutable((string) $item['effective_start_iso']);
      $end = new \DateTimeImmutable((string) $item['effective_end_iso']);
      $pattern = 'j M Y, H:i';
    }

    return [
      'display_start_label' => $this->todayDateFormatter->format($start->getTimestamp(), 'custom', $pattern, $timezone, $language),
      'display_end_label' => $this->todayDateFormatter->format($end->getTimestamp(), 'custom', $pattern, $timezone, $language),
    ];
  }

  /**
   * @param array<string, mixed> $item
   *
   * @return array<string, mixed>
   */
  private function preparationProps(array $item): array {
    return [
      'instruction' => (string) $item['instruction'],
      'due_time' => (string) $item['due_time'],
      'due_time_iso' => (string) $item['due_time_iso'],
      'overdue' => (bool) $item['overdue'],
      'activity_label' => (string) $item['activity_label'],
      'all_day' => (bool) $item['all_day'],
      'all_day_start_date' => (string) $item['all_day_start_date'],
      'all_day_end_date' => (string) $item['all_day_end_date'],
      'all_day_start_label' => (string) $item['all_day_start_label'],
      'all_day_end_label' => (string) $item['all_day_end_label'],
      'activity_start' => (string) $item['activity_start'],
      'activity_start_iso' => (string) $item['activity_start_iso'],
      'display_timezone' => (string) $item['display_timezone'],
    ];
  }

  /**
   * Builds the preparation completion transition form.
   *
   * @param array<string, mixed> $item
   *   Preparation read-model item.
   * @param string $returnSurface
   *   Product surface to return to after the transition.
   *
   * @return array<string, mixed>
   *   Render array for the transition form.
   */
  private function preparationActionForm(array $item, string $returnSurface): array {
    return $this->formBuilder()->getForm(
      PreparationCompletionTransitionForm::class,
      'prepared',
      (int) $item['_completion_series_id'],
      (string) $item['_completion_original_occurrence_key'],
      (int) $item['_completion_requirement_id'],
      $returnSurface,
    );
  }

}
