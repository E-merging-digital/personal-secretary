<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
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
      '#cache' => ['max-age' => 0],
      'window' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Showing active overdue preparations and preparations due in the next 7 days.'),
      ],
      'to_prepare' => [
        '#type' => 'container',
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $this->t('To prepare'),
        ],
      ],
      'prepared' => [
        '#type' => 'container',
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
        '#value' => $this->t('Nothing currently needs preparation.'),
      ];
    }
    else {
      $build['to_prepare']['items'] = ['#type' => 'container'];
      foreach ($toPrepare as $delta => $item) {
        $build['to_prepare']['items'][$delta] = $this->itemBuild($item, FALSE, 'mine');
      }
    }

    if ($prepared === []) {
      $build['prepared']['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No current preparation has been marked prepared.'),
      ];
    }
    else {
      $build['prepared']['items'] = ['#type' => 'container'];
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
    $build = [
      '#type' => 'container',
      'item' => [
        '#type' => 'component',
        '#component' => 'personal_secretary:preparation-item',
        '#props' => $this->presentationProps($item),
      ],
    ];

    if ($prepared) {
      $preparedTime = trim((string) ($item['prepared_time'] ?? ''));
      $preparedTimeIso = trim((string) ($item['prepared_time_iso'] ?? ''));
      $build['state'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $preparedTime !== ''
          ? $this->t('Prepared at @time.', ['@time' => $preparedTime])
          : $this->t('Prepared.'),
      ];
      if ($preparedTime !== '' && $preparedTimeIso !== '') {
        $build['state']['#value'] = $this->t('Prepared at @time.', ['@time' => $preparedTime]);
      }
      $build['action'] = [
        '#type' => 'link',
        '#title' => $this->t('Mark not prepared'),
        '#url' => $this->actionUrl('personal_secretary.mark_preparation_not_prepared', $item, $returnSurface),
      ];
      return $build;
    }

    $build['action'] = [
      '#type' => 'link',
      '#title' => $this->t('Mark prepared'),
      '#url' => $this->actionUrl('personal_secretary.mark_preparation_prepared', $item, $returnSurface),
    ];
    return $build;
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
      'activity_start' => (string) $item['activity_start'],
      'activity_start_iso' => (string) $item['activity_start_iso'],
      'display_timezone' => (string) $item['display_timezone'],
    ];
  }

  /**
   * @param array<string, mixed> $item
   */
  private function actionUrl(string $route, array $item, string $returnSurface): Url {
    return Url::fromRoute($route, [
      'series' => (int) $item['_completion_series_id'],
      'original_occurrence_key' => (string) $item['_completion_original_occurrence_key'],
      'preparation_requirement' => (int) $item['_completion_requirement_id'],
      'return_surface' => $returnSurface,
    ]);
  }

}
