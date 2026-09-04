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
    $hasExistingContext = $this->hasExistingContext();
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
      if ($hasExistingContext) {
        $build['add_household_member'] = $this->addHouseholdMemberLink();
        $build['rename_household_member'] = $this->renameHouseholdMemberLink();
        $build['link_current_user_to_person'] = $this->linkCurrentUserToPersonLink();
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

    if ($hasExistingContext) {
      $build['add_household_member'] = $this->addHouseholdMemberLink();
      $build['rename_household_member'] = $this->renameHouseholdMemberLink();
      $build['link_current_user_to_person'] = $this->linkCurrentUserToPersonLink();
    }
    $build['items'] = ['#type' => 'container'];
    foreach ($items as $delta => $item) {
      $scheduleTarget = $item['schedule_target'];
      $responsibilityTarget = $item['responsibility_target'];
      $actionTarget = $item['cancel_target'];
      unset($item['schedule_target'], $item['responsibility_target'], $item['cancel_target']);
      if ($item['responsibility_label'] === '') {
        $item['responsibility_label'] = (string) $this->t('Unassigned');
      }
      $build['items'][$delta]['activity'] = [
        '#type' => 'component',
        '#component' => 'personal_secretary:upcoming-activity',
        '#props' => $item,
      ];

      $build['items'][$delta]['schedule'] = [
        '#type' => 'link',
        '#title' => $this->t('Change recurring schedule'),
        '#url' => Url::fromRoute(
          'personal_secretary.edit_recurring_schedule',
          ['series' => $scheduleTarget['series_id']],
        ),
      ];
      $build['items'][$delta]['recurring_responsibility'] = [
        '#type' => 'link',
        '#title' => $this->t('Change recurring responsibility'),
        '#url' => Url::fromRoute(
          'personal_secretary.edit_recurring_responsibility',
          ['series' => $scheduleTarget['series_id']],
        ),
      ];

      $responsibilityRouteParameters = [
        'series' => $responsibilityTarget['series_id'],
        'original_occurrence_key' => $responsibilityTarget['original_occurrence_key'],
      ];
      $build['items'][$delta]['responsibility'] = [
        '#type' => 'link',
        '#title' => $this->t('Change responsibility'),
        '#url' => Url::fromRoute(
          'personal_secretary.responsibility_occurrence',
          $responsibilityRouteParameters,
        ),
      ];

      if ($actionTarget !== NULL) {
        $routeParameters = [
          'series' => $actionTarget['series_id'],
          'original_occurrence_key' => $actionTarget['original_occurrence_key'],
        ];
        $build['items'][$delta]['reschedule'] = [
          '#type' => 'link',
          '#title' => $this->t('Reschedule occurrence'),
          '#url' => Url::fromRoute(
            'personal_secretary.reschedule_occurrence',
            $routeParameters,
          ),
        ];
        $build['items'][$delta]['cancel'] = [
          '#type' => 'link',
          '#title' => $this->t('Cancel occurrence'),
          '#url' => Url::fromRoute(
            'personal_secretary.cancel_occurrence',
            $routeParameters,
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

  /**
   * @return array<string, mixed>
   */
  private function addHouseholdMemberLink(): array {
    return [
      '#type' => 'link',
      '#title' => $this->t('Add household member'),
      '#url' => Url::fromRoute('personal_secretary.add_household_member'),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function renameHouseholdMemberLink(): array {
    return [
      '#type' => 'link',
      '#title' => $this->t('Rename household member'),
      '#url' => Url::fromRoute('personal_secretary.rename_household_member'),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function linkCurrentUserToPersonLink(): array {
    return [
      '#type' => 'link',
      '#title' => $this->t('Link my account to household member'),
      '#url' => Url::fromRoute('personal_secretary.link_current_user_to_person'),
    ];
  }

}
