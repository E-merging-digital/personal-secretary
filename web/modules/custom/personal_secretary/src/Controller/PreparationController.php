<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormState;
use Drupal\Core\Url;
use Drupal\personal_secretary\Form\PreparationCompletionTransitionForm;
use Drupal\personal_secretary\Service\CurrentUserPreparationService;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Renders the derived current-user preparation surface.
 */
final class PreparationController extends ControllerBase {

  public function __construct(
    private readonly CurrentUserPreparationService $currentUserPreparations,
    private readonly RequestStack $requestStack,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.current_user_preparation'),
      $container->get('request_stack'),
    );
  }

  public function mine(): array {
    // Read raw input so arrays or forged values safely fall back to seven days.
    $query = $this->requestStack->getCurrentRequest()?->query->all() ?? [];
    $windowDays = ($query['days'] ?? NULL) === '30' ? 30 : 7;

    try {
      $model = $this->currentUserPreparations->mine(NULL, $windowDays);
    }
    catch (InvalidArgumentException) {
      $build = [
        '#cache' => ['max-age' => 0],
        'remediation' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Link your account to a valid Household member to see My preparations.'),
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

    $toPrepare = array_values(array_filter(
      $model['items'],
      static fn(array $item): bool => ($item['prepared'] ?? FALSE) !== TRUE,
    ));
    $prepared = array_values(array_filter(
      $model['items'],
      static fn(array $item): bool => ($item['prepared'] ?? FALSE) === TRUE,
    ));

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ps-preparation-surface']],
      '#cache' => ['max-age' => 0],
      'horizon' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['ps-preparation-horizon'],
          'role' => 'group',
          'aria-label' => (string) $this->t('Preparation horizon'),
        ],
        'seven' => [
          '#type' => 'link',
          '#title' => $this->t('7 days'),
          '#url' => Url::fromRoute('personal_secretary.my_preparations', [], ['query' => ['days' => 7]]),
          '#attributes' => [
            'class' => $windowDays === 7 ? ['button', 'button--primary'] : ['button'],
          ] + ($windowDays === 7 ? ['aria-current' => 'page'] : []),
        ],
        'thirty' => [
          '#type' => 'link',
          '#title' => $this->t('30 days'),
          '#url' => Url::fromRoute('personal_secretary.my_preparations', [], ['query' => ['days' => 30]]),
          '#attributes' => [
            'class' => $windowDays === 30 ? ['button', 'button--primary'] : ['button'],
          ] + ($windowDays === 30 ? ['aria-current' => 'page'] : []),
        ],
      ],
      'window' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['ps-preparation-window']],
        '#value' => $windowDays === 30
          ? $this->t('Showing active overdue preparations and preparations due in the next 30 days.')
          : $this->t('Showing active overdue preparations and preparations due in the next 7 days.'),
      ],
      'feedback' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'ps-preparation-feedback',
          'class' => ['ps-preparation-feedback'],
          'role' => 'status',
          'aria-live' => 'polite',
          'aria-atomic' => 'true',
        ],
      ],
      'to_prepare' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ps-preparation-section']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $this->t('To prepare'),
        ],
      ],
      'prepared' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ps-preparation-section']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $this->t('Prepared'),
        ],
      ],
    ];

    if ($toPrepare === []) {
      $build['to_prepare']['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['ps-empty-state']],
        '#value' => $model['items'] === []
          ? ($windowDays === 30
            ? $this->t('No upcoming preparations are assigned to you in the next 30 days.')
            : $this->t('No upcoming preparations are assigned to you in the next 7 days.'))
          : $this->t('Nothing currently needs preparation.'),
      ];
      if ($model['items'] === []) {
        $build['to_prepare']['explanation'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Preparations are added through activities. You can include a preparation when adding an activity.'),
        ];
        $build['to_prepare']['add_activity'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['ps-upcoming-actions']],
          'link' => [
            '#type' => 'link',
            '#title' => $this->t('Add an activity to prepare'),
            '#url' => Url::fromRoute('personal_secretary.add_activity'),
            '#attributes' => ['class' => ['ps-upcoming-actions__add']],
          ],
        ];
      }
    }
    else {
      $build['to_prepare']['items'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ps-preparation-list']],
      ];
      foreach ($toPrepare as $delta => $item) {
        $build['to_prepare']['items'][$delta] = $this->itemBuild($item, FALSE, 'mine');
      }
    }

    if ($prepared === []) {
      $build['prepared']['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['ps-empty-state']],
        '#value' => $this->t('No preparation shown in this period has been marked prepared.'),
      ];
    }
    else {
      $build['prepared']['items'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ps-preparation-list']],
      ];
      foreach ($prepared as $delta => $item) {
        $build['prepared']['items'][$delta] = $this->itemBuild($item, TRUE, 'mine');
      }
    }

    // Only these two lists are refreshed after an inline completion action.
    // Horizon navigation and its URL remain in the original document.
    $build['sections'] = [
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      '#attributes' => [
        'id' => 'ps-preparation-live',
        'class' => ['ps-preparation-live'],
      ],
      'to_prepare' => $build['to_prepare'],
      'prepared' => $build['prepared'],
    ];
    unset($build['to_prepare'], $build['prepared']);

    return $build;
  }

  /**
   * @param array<string, mixed> $item
   *
   * @return array<string, mixed>
   */
  private function itemBuild(array $item, bool $prepared, string $returnSurface): array {
    return [
      '#type' => 'container',
      '#attributes' => [
        'id' => PreparationCompletionTransitionForm::rowId(
          (int) $item['_completion_series_id'],
          (string) $item['_completion_original_occurrence_key'],
          (int) $item['_completion_requirement_id'],
        ),
        'class' => [
          'ps-preparation-row',
          $prepared ? 'ps-preparation-row--prepared' : 'ps-preparation-row--pending',
        ],
      ],
      'item' => [
        '#type' => 'component',
        '#component' => 'personal_secretary:preparation-item',
        '#props' => $this->presentationProps($item),
      ],
      'state' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['ps-preparation-row__state']],
        '#value' => $prepared
          ? $this->t('Prepared')
          : $this->t('Not prepared'),
      ],
      'action' => $this->actionForm(
        $prepared ? 'not_prepared' : 'prepared',
        $item,
        $returnSurface,
      ),
    ];
  }

  /**
   * @param array<string, mixed> $item
   *
   * @return array<string, mixed>
   */
  private function presentationProps(array $item): array {
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
   * @param array<string, mixed> $item
   */
  private function actionForm(string $action, array $item, string $returnSurface): array {
    $args = [
      $action,
      (int) $item['_completion_series_id'],
      (string) $item['_completion_original_occurrence_key'],
      (int) $item['_completion_requirement_id'],
      $returnSurface,
    ];

    // In the AJAX POST, these are *new* action forms for display, not the
    // submitted form. Keep Drupal FormBuilder from reprocessing the current
    // form_id/build_id and raising a nested FormAjaxException.
    if ($this->requestStack->getCurrentRequest()?->isXmlHttpRequest()) {
      $formState = new FormState();
      $formState->setUserInput([]);
      $formState->setBuildInfo(['args' => $args]);
      return $this->formBuilder()->buildForm(PreparationCompletionTransitionForm::class, $formState);
    }

    return $this->formBuilder()->getForm(PreparationCompletionTransitionForm::class, ...$args);
  }

}
