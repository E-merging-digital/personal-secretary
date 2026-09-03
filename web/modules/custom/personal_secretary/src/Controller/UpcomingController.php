<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\personal_secretary\Service\UpcomingActivityService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the first read-only Personal Secretary application surface.
 */
final class UpcomingController extends ControllerBase {

  public function __construct(
    private readonly UpcomingActivityService $upcomingActivities,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.upcoming_activity'),
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
      return $build;
    }

    $build['items'] = ['#type' => 'container'];
    foreach ($items as $delta => $item) {
      if ($item['responsibility_label'] === '') {
        $item['responsibility_label'] = (string) $this->t('Unassigned');
      }
      $build['items'][$delta] = [
        '#type' => 'component',
        '#component' => 'personal_secretary:upcoming-activity',
        '#props' => $item,
      ];
    }

    return $build;
  }

}
