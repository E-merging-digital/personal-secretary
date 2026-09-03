<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\personal_secretary\Service\UpcomingActivityService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the first read-only Personal Secretary application surface.
 */
final class UpcomingController extends ControllerBase {

  public function __construct(
    private readonly UpcomingActivityService $upcomingActivities,
    private readonly EntityTypeManagerInterface $domainEntityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.upcoming_activity'),
      $container->get('entity_type.manager'),
    );
  }

  public function build(): array {
    $items = $this->upcomingActivities->upcoming();
    $build = [
      '#cache' => ['max-age' => 0],
      'window' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Showing upcoming activities for the next 7 days.'),
      ],
    ];

    if ($items === []) {
      $build['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No upcoming activities in the next 7 days.'),
      ];
      if ($this->hasExistingContext()) {
        $build['add_activity'] = $this->addActivityLink();
      }
      else {
        $build['setup'] = [
          '#type' => 'link',
          '#title' => $this->t('Add your first activity'),
          '#url' => Url::fromRoute('personal_secretary.setup'),
        ];
      }
      return $build;
    }

    $build['items'] = ['#type' => 'container'];
    foreach ($items as $delta => $item) {
      $cancelTarget = $item['cancel_target'];
      unset($item['cancel_target']);
      if ($item['responsibility_label'] === '') {
        $item['responsibility_label'] = (string) $this->t('Unassigned');
      }
      $build['items'][$delta]['activity'] = [
        '#type' => 'component',
        '#component' => 'personal_secretary:upcoming-activity',
        '#props' => $item,
      ];
      if ($cancelTarget !== NULL) {
        $build['items'][$delta]['cancel'] = [
          '#type' => 'link',
          '#title' => $this->t('Cancel occurrence'),
          '#url' => Url::fromRoute(
            'personal_secretary.cancel_occurrence',
            [
              'series' => $cancelTarget['series_id'],
              'original_occurrence_key' => $cancelTarget['original_occurrence_key'],
            ],
          ),
        ];
      }
    }
    $build['add_activity'] = $this->addActivityLink();

    return $build;
  }

  private function hasExistingContext(): bool {
    foreach (['personal_secretary_person', 'personal_secretary_household'] as $entityTypeId) {
      $count = $this->domainEntityTypeManager
        ->getStorage($entityTypeId)
        ->getQuery()
        ->accessCheck(FALSE)
        ->count()
        ->execute();
      if ((int) $count === 0) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * @return array<string, mixed>
   */
  private function addActivityLink(): array {
    return [
      '#type' => 'link',
      '#title' => $this->t('Add activity'),
      '#url' => Url::fromRoute('personal_secretary.add_activity'),
    ];
  }

}
