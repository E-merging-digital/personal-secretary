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

    $build = [
      '#cache' => ['max-age' => 0],
      'window' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Showing active overdue preparations and preparations due in the next 7 days.'),
      ],
    ];

    if ($model['items'] === []) {
      $build['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No active preparations are overdue or due in the next 7 days.'),
      ];
      return $build;
    }

    $build['items'] = ['#type' => 'container'];
    foreach ($model['items'] as $delta => $item) {
      $build['items'][$delta] = [
        '#type' => 'component',
        '#component' => 'personal_secretary:preparation-item',
        '#props' => $item,
      ];
    }

    return $build;
  }

}
