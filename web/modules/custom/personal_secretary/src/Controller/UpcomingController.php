<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\personal_secretary\Service\PauseRecurringActivityService;
use Drupal\personal_secretary\Service\UpcomingActivityService;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders the first read-only Personal Secretary application surfaces.
 */
final class UpcomingController extends ControllerBase {

  public function __construct(
    private readonly UpcomingActivityService $upcomingActivities,
    private readonly EntityTypeManagerInterface $domainEntityTypeManager,
    private readonly CurrentPersonResolver $currentPersonResolver,
    private readonly HouseholdAuthorizationService $householdAuthorization,
    private readonly PauseRecurringActivityService $pauseRecurringActivity,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.upcoming_activity'),
      $container->get('entity_type.manager'),
      $container->get('personal_secretary.current_person'),
      $container->get('personal_secretary.household_authorization'),
      $container->get('personal_secretary.pause_recurring_activity'),
    );
  }

  public function build(): array {
    $items = $this->upcomingActivities->upcoming();
    $hasExistingContext = $this->hasExistingContext();
    $build = $this->windowBuild(
      (string) $this->t('Showing upcoming activities for the next 7 days.'),
    );

    if ($items === []) {
      $build['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No upcoming activities in the next 7 days.'),
      ];
      if ($hasExistingContext) {
        $build['my_upcoming'] = $this->myUpcomingLink();
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
      $build['my_upcoming'] = $this->myUpcomingLink();
      $build['add_household_member'] = $this->addHouseholdMemberLink();
      $build['rename_household_member'] = $this->renameHouseholdMemberLink();
      $build['link_current_user_to_person'] = $this->linkCurrentUserToPersonLink();
    }
    $build['items'] = $this->buildItems($items, TRUE, FALSE, FALSE);
    $build['add_activity'] = $this->addActivityLink();

    return $build;
  }

  public function buildMine(): array {
    $householdIds = $this->householdAuthorization->authorizedHouseholdIds($this->currentUser());
    try {
      $person = $this->currentPersonResolver->resolve($this->currentUser());
    }
    catch (InvalidArgumentException) {
      $build = [
        '#cache' => ['max-age' => 0],
        'remediation' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Link your account to a Household member to see My upcoming.'),
        ],
      ];
      if ($this->currentUser()->hasPermission(HouseholdAuthorizationService::ADMIN_PERMISSION)) {
        $build['link_current_user_to_person'] = $this->linkCurrentUserToPersonLink();
      }
      return $build;
    }

    $items = $this->upcomingActivities->upcomingForPersonInHouseholds(
      $person,
      $householdIds,
    );
    $build = $this->windowBuild(
      (string) $this->t('Showing My upcoming activities for the next 7 days.'),
    );
    $build['add_activity'] = $this->addActivityLink();

    if ($items === []) {
      $build['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No upcoming activities are assigned to you in the next 7 days.'),
      ];
      return $build;
    }

    $isAdmin = $this->currentUser()->hasPermission(HouseholdAuthorizationService::ADMIN_PERMISSION);
    $build['items'] = $this->buildItems($items, $isAdmin, !$isAdmin, TRUE);
    return $build;
  }

  public function detail(string $series, string $original_occurrence_key): array {
    $householdIds = $this->householdAuthorization->authorizedHouseholdIds($this->currentUser());

    try {
      $person = $this->currentPersonResolver->resolve($this->currentUser());
      $item = $this->upcomingActivities->occurrenceForPersonInHouseholds(
        $person,
        $householdIds,
        (int) $series,
        $original_occurrence_key,
      );
    }
    catch (InvalidArgumentException|\RuntimeException $exception) {
      throw new NotFoundHttpException('The requested occurrence is unavailable.', $exception);
    }

    $isAdmin = $this->currentUser()->hasPermission(HouseholdAuthorizationService::ADMIN_PERMISSION);

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ps-occurrence-detail']],
      '#cache' => ['max-age' => 0],
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('Back to My upcoming'),
        '#url' => Url::fromRoute('personal_secretary.my_upcoming'),
        '#attributes' => ['class' => ['ps-occurrence-detail__back']],
      ],
      'occurrence' => $this->buildItems([$item], $isAdmin, !$isAdmin, FALSE),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function windowBuild(string $message): array {
    return [
      '#cache' => ['max-age' => 0],
      'window' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $message,
      ],
    ];
  }

  /**
   * @param array<int, array<string, mixed>> $items
   *
   * @return array<string|int, mixed>
   */
  private function buildItems(
    array $items,
    bool $includeAdminMutationLinks,
    bool $includeSelfMutationLinks,
    bool $includeDetailLinks,
  ): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ps-upcoming-list'], 'role' => 'list'],
    ];

    foreach ($items as $delta => $item) {
      $scheduleTarget = $item['schedule_target'];
      $responsibilityTarget = $item['responsibility_target'];
      $actionTarget = $item['cancel_target'];
      $allDay = (bool) ($item['all_day'] ?? FALSE);
      unset($item['schedule_target'], $item['responsibility_target'], $item['cancel_target']);

      if ($item['responsibility_label'] === '') {
        $item['responsibility_label'] = (string) $this->t('Not assigned');
      }

      $build[$delta] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ps-upcoming-list__item'], 'role' => 'listitem'],
      ];
      $build[$delta]['activity'] = [
        '#type' => 'component',
        '#component' => 'personal_secretary:upcoming-activity',
        '#props' => $item,
      ];

      $occurrenceActions = [];
      if ($includeDetailLinks) {
        $occurrenceActions['detail'] = $this->detailLink($responsibilityTarget);
      }
      if ($includeAdminMutationLinks) {
        $occurrenceActions['responsibility'] = [
          '#type' => 'link',
          '#title' => $this->t('Change responsibility'),
          '#url' => Url::fromRoute('personal_secretary.responsibility_occurrence', [
            'series' => $responsibilityTarget['series_id'],
            'original_occurrence_key' => $responsibilityTarget['original_occurrence_key'],
          ]),
          '#attributes' => ['class' => ['ps-action-link']],
        ];
      }
      if (($includeAdminMutationLinks || $includeSelfMutationLinks) && $actionTarget !== NULL) {
        if (!$allDay) {
          $occurrenceActions['reschedule'] = $this->rescheduleLink($actionTarget);
        }
        $occurrenceActions['cancel'] = $this->cancelLink($actionTarget);
      }

      if ($occurrenceActions !== []) {
        $build[$delta]['occurrence_actions'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['ps-action-group', 'ps-action-group--occurrence']],
          'title' => ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $this->t('This occurrence')],
          'links' => ['#type' => 'container', '#attributes' => ['class' => ['ps-action-group__links']]] + $occurrenceActions,
        ];
      }

      if (!$includeAdminMutationLinks) {
        continue;
      }

      $seriesActions = [];
      if (!$allDay) {
        $seriesActions['schedule'] = [
          '#type' => 'link',
          '#title' => $this->t('Change recurring schedule'),
          '#url' => Url::fromRoute('personal_secretary.edit_recurring_schedule', ['series' => $scheduleTarget['series_id']]),
          '#attributes' => ['class' => ['ps-action-link']],
        ];
      }
      $seriesActions['recurring_responsibility'] = [
        '#type' => 'link',
        '#title' => $this->t('Change recurring responsibility'),
        '#url' => Url::fromRoute('personal_secretary.edit_recurring_responsibility', ['series' => $scheduleTarget['series_id']]),
        '#attributes' => ['class' => ['ps-action-link']],
      ];
      $seriesActions['time_commitment'] = [
        '#type' => 'link',
        '#title' => $this->t('Change time commitment'),
        '#url' => Url::fromRoute('personal_secretary.edit_time_commitment', ['series' => $scheduleTarget['series_id']]),
        '#attributes' => ['class' => ['ps-action-link']],
      ];
      if ($this->pauseRecurringActivity->canPause((int) $scheduleTarget['series_id'])) {
        $seriesActions['pause'] = [
          '#type' => 'link',
          '#title' => $this->t('Pause recurring activity'),
          '#url' => Url::fromRoute('personal_secretary.pause_recurring_activity', ['series' => $scheduleTarget['series_id']]),
          '#attributes' => ['class' => ['ps-action-link']],
        ];
      }

      $build[$delta]['series_actions'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ps-action-group', 'ps-action-group--series']],
        'title' => ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $this->t('Activity settings')],
        'links' => ['#type' => 'container', '#attributes' => ['class' => ['ps-action-group__links']]] + $seriesActions,
      ];
    }

    return $build;
  }

  /**
   * @param array{series_id: int, original_occurrence_key: string} $target
   *
   * @return array<string, mixed>
   */
  private function detailLink(array $target): array {
    return [
      '#type' => 'link',
      '#title' => $this->t('View details'),
      '#url' => Url::fromRoute('personal_secretary.occurrence_detail', [
        'series' => $target['series_id'],
        'original_occurrence_key' => $target['original_occurrence_key'],
      ]),
      '#attributes' => ['class' => ['ps-action-link', 'ps-action-link--primary']],
    ];
  }

  /**
   * @param array{series_id: int, original_occurrence_key: string} $target
   *
   * @return array<string, mixed>
   */
  private function rescheduleLink(array $target): array {
    return [
      '#type' => 'link',
      '#title' => $this->t('Reschedule occurrence'),
      '#url' => Url::fromRoute(
        'personal_secretary.reschedule_occurrence',
        [
          'series' => $target['series_id'],
          'original_occurrence_key' => $target['original_occurrence_key'],
        ],
      ),
      '#attributes' => ['class' => ['ps-action-link']],
    ];
  }

  private function cancelLink(array $target): array {
    return [
      '#type' => 'link',
      '#title' => $this->t('Cancel occurrence'),
      '#url' => Url::fromRoute(
        'personal_secretary.cancel_occurrence',
        [
          'series' => $target['series_id'],
          'original_occurrence_key' => $target['original_occurrence_key'],
        ],
      ),
      '#attributes' => ['class' => ['ps-action-link', 'ps-action-link--danger']],
    ];
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
  private function myUpcomingLink(): array {
    return [
      '#type' => 'link',
      '#title' => $this->t('My upcoming'),
      '#url' => Url::fromRoute('personal_secretary.my_upcoming'),
    ];
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
