<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\personal_secretary\Form\PreparationCompletionTransitionForm;
use Drupal\personal_secretary\Service\CurrentUserPreparationService;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the derived current-user preparation surface.
 */
final class PreparationController extends ControllerBase {

  public function __construct(
    private readonly CurrentUserPreparationService $currentUserPreparations,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.current_user_preparation'),
    );
  }

  public function mine(): array {
    try {
      $model = $this->currentUserPreparations->mine();
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
      'window' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['ps-preparation-window']],
        '#value' => $this->t('Showing active overdue preparations and preparations due in the next 7 days.'),
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
          ? $this->t('No upcoming preparations are assigned to you in the next 7 days.')
          : $this->t('Nothing currently needs preparation.'),
      ];
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
        '#value' => $this->t('No current preparation has been marked prepared.'),
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
    return $this->formBuilder()->getForm(
      PreparationCompletionTransitionForm::class,
      $action,
      (int) $item['_completion_series_id'],
      (string) $item['_completion_original_occurrence_key'],
      (int) $item['_completion_requirement_id'],
      $returnSurface,
    );
  }

}
