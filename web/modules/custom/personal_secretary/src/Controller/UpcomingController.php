<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\personal_secretary\Form\GoogleCalendarExportForm;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\EditTimeCommitmentService;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\personal_secretary\Service\PauseRecurringActivityService;
use Drupal\personal_secretary\Service\UpcomingActivityService;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
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
    private readonly EditTimeCommitmentService $timeCommitmentEditor,
    private readonly PauseRecurringActivityService $pauseRecurringActivity,
    private readonly RequestStack $requestStack,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.upcoming_activity'),
      $container->get('entity_type.manager'),
      $container->get('personal_secretary.current_person'),
      $container->get('personal_secretary.household_authorization'),
      $container->get('personal_secretary.edit_time_commitment'),
      $container->get('personal_secretary.pause_recurring_activity'),
      $container->get('request_stack'),
    );
  }

  public function build(): array {
    $items = $this->upcomingActivities->upcoming();
    $hasExistingContext = $this->hasExistingContext();
    $build = $this->windowBuild(
      (string) $this->t('Showing upcoming activities for the next 7 days.'),
    );

    if ($hasExistingContext) {
      $build['add_activity'] = $this->addActivityLink();
    }

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

    $days = $this->selectedHorizonDays();
    $query = $this->selectedSearchQuery();
    // Filter only the already-authorized, horizon-bounded projection.
    $items = $this->upcomingActivities->upcomingForPersonInHouseholds(
      $person,
      $householdIds,
      $days,
    );
    if ($query !== '') {
      $items = array_values(array_filter(
        $items,
        static fn(array $item): bool => mb_stripos((string) $item['activity_label'], $query) !== FALSE,
      ));
    }

    $build = $this->windowBuild(
      (string) $this->t('Showing My upcoming activities for the next @days days.', ['@days' => $days]),
    );
    $build['horizon'] = $this->horizonLinks($days, $query);
    $build['search'] = $this->searchForm($days, $query);
    $build['add_activity'] = $this->addActivityLink();

    if ($items === []) {
      $build['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $query !== ''
          ? $this->t('No upcoming activities match this search.')
          : $this->t('No upcoming activities are assigned to you in the next @days days.', ['@days' => $days]),
      ];
      return $build;
    }

    $isAdmin = $this->currentUser()->hasPermission(HouseholdAuthorizationService::ADMIN_PERMISSION);
    $build['items'] = $this->buildItems($items, $isAdmin, !$isAdmin, TRUE, $days, $query);
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
    $occurrence = $this->buildItems([$item], $isAdmin, !$isAdmin, FALSE);
    try {
      $google = $this->formBuilder()->getForm(GoogleCalendarExportForm::class, $series, $original_occurrence_key);
      $occurrence[0]['occurrence_actions'] ??= [
        '#type' => 'container',
        '#attributes' => ['class' => ['ps-action-group', 'ps-action-group--occurrence']],
        'title' => ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $this->t('This occurrence')],
      ];
      $occurrence[0]['occurrence_actions']['google_calendar'] = $google;
    }
    catch (InvalidArgumentException|\RuntimeException) {
      // Explain necessary calendar eligibility without changing the domain rules.
      $occurrence[0]['occurrence_actions'] ??= [
        '#type' => 'container',
        '#attributes' => ['class' => ['ps-action-group', 'ps-action-group--occurrence']],
        'title' => ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $this->t('This occurrence')],
      ];
      $occurrence[0]['occurrence_actions']['calendar_availability'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['ps-calendar-availability']],
        '#value' => $this->t('Google Calendar is only available for one-off activities you are responsible for and that occupy your time. If you manage this activity, check its time settings under Activity settings.'),
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ps-occurrence-detail']],
      '#cache' => ['max-age' => 0],
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('Back to My upcoming'),
        '#url' => Url::fromRoute(
          'personal_secretary.my_upcoming',
          [],
          $this->upcomingUrlOptions($this->selectedHorizonDays(), $this->selectedSearchQuery()),
        ),
        '#attributes' => ['class' => ['ps-occurrence-detail__back']],
      ],
      'occurrence' => $occurrence,
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
    int $days = 7,
    string $query = '',
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
        $occurrenceActions['detail'] = $this->detailLink($responsibilityTarget, $days, $query);
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

      $isRecurring = $this->pauseRecurringActivity->canPause((int) $scheduleTarget['series_id']);
      $seriesActions = [];
      if ($isRecurring && !$allDay) {
        $seriesActions['schedule'] = [
          '#type' => 'link',
          '#title' => $this->t('Change recurring schedule'),
          '#url' => Url::fromRoute('personal_secretary.edit_recurring_schedule', ['series' => $scheduleTarget['series_id']]),
          '#attributes' => ['class' => ['ps-action-link']],
        ];
      }
      if ($isRecurring) {
        $seriesActions['recurring_responsibility'] = [
          '#type' => 'link',
          '#title' => $this->t('Change recurring responsibility'),
          '#url' => Url::fromRoute('personal_secretary.edit_recurring_responsibility', ['series' => $scheduleTarget['series_id']]),
          '#attributes' => ['class' => ['ps-action-link']],
        ];
      }
      if ($this->timeCommitmentEditor->canEdit((int) $scheduleTarget['series_id'])) {
        $seriesActions['time_commitment'] = [
          '#type' => 'link',
          '#title' => $this->t('Change time commitment'),
          '#url' => Url::fromRoute('personal_secretary.edit_time_commitment', ['series' => $scheduleTarget['series_id']]),
          '#attributes' => ['class' => ['ps-action-link']],
        ];
      }
      if ($isRecurring) {
        $seriesActions['pause'] = [
          '#type' => 'link',
          '#title' => $this->t('Pause recurring activity'),
          '#url' => Url::fromRoute('personal_secretary.pause_recurring_activity', ['series' => $scheduleTarget['series_id']]),
          '#attributes' => ['class' => ['ps-action-link']],
        ];
      }

      if ($seriesActions !== []) {
        $build[$delta]['series_actions'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['ps-action-group', 'ps-action-group--series']],
          'title' => ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $this->t('Activity settings')],
          'links' => ['#type' => 'container', '#attributes' => ['class' => ['ps-action-group__links']]] + $seriesActions,
        ];
      }
    }

    return $build;
  }

  /**
   * @param array{series_id: int, original_occurrence_key: string} $target
   *
   * @return array<string, mixed>
   */
  private function detailLink(array $target, int $days, string $query): array {
    return [
      '#type' => 'link',
      '#title' => $this->t('View details'),
      '#url' => Url::fromRoute('personal_secretary.occurrence_detail', [
        'series' => $target['series_id'],
        'original_occurrence_key' => $target['original_occurrence_key'],
      ], $this->upcomingUrlOptions($days, $query)),
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

  /**
   * Only two bounded, read-only horizons are supported.
   */
  private function selectedHorizonDays(): int {
    $days = $this->requestStack->getCurrentRequest()?->query->all()['days'] ?? NULL;
    return $days === '30' ? 30 : 7;
  }

  /**
   * @return array<string, mixed>
   */
  private function horizonLinks(int $activeDays, string $query): array {
    $links = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ps-upcoming-horizon'],
        'role' => 'navigation',
        'aria-label' => (string) $this->t('Choose the upcoming period'),
      ],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->t('Show upcoming for:'),
      ],
    ];

    foreach ([7, 30] as $days) {
      $selected = $activeDays === $days;
      $attributes = ['class' => ['button', 'ps-upcoming-horizon__choice']];
      if ($selected) {
        $attributes['class'][] = 'button--primary';
        $attributes['aria-current'] = 'page';
      }
      $links['days_' . $days] = [
        '#type' => 'link',
        '#title' => $this->t('@days days', ['@days' => $days]),
        '#url' => Url::fromRoute(
          'personal_secretary.my_upcoming',
          [],
          $this->upcomingUrlOptions($days, $query),
        ),
        '#attributes' => $attributes,
      ];
    }

    return $links;
  }

  /**
   * Treat non-scalar or overly long GET input as an empty, safe search.
   */
  private function selectedSearchQuery(): string {
    $raw = $this->requestStack->getCurrentRequest()?->query->all()['q'] ?? '';
    $query = is_string($raw) ? trim($raw) : '';
    return mb_strlen($query) <= 100 ? $query : '';
  }

  /**
   * Keep the historic seven-day URL canonical when no search is active.
   *
   * @return array<string, string>
   */
  private function upcomingQuery(int $days, string $query): array {
    $options = [];
    if ($days === 30) {
      $options['days'] = '30';
    }
    if ($query !== '') {
      $options['q'] = $query;
    }
    return $options;
  }

  /**
   * @return array<string, mixed>
   */
  private function upcomingUrlOptions(int $days, string $query): array {
    $options = $this->upcomingQuery($days, $query);
    return $options === [] ? [] : ['query' => $options];
  }

  /**
   * A plain GET form requires no JavaScript or state-changing Form API submit.
   *
   * @return array<string, mixed>
   */
  private function searchForm(int $days, string $query): array {
    $form = [
      '#type' => 'html_tag',
      '#tag' => 'form',
      '#attributes' => [
        'method' => 'get',
        'action' => Url::fromRoute('personal_secretary.my_upcoming')->toString(),
        'role' => 'search',
        'class' => ['ps-upcoming-search'],
      ],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'label',
        '#value' => $this->t('Search for an activity'),
        '#attributes' => ['for' => 'ps-upcoming-search-q'],
      ],
      'query' => [
        '#type' => 'html_tag',
        '#tag' => 'input',
        '#attributes' => [
          'id' => 'ps-upcoming-search-q',
          'type' => 'search',
          'name' => 'q',
          'value' => $query,
          'maxlength' => '100',
          'size' => '32',
        ],
      ],
      'submit' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => $this->t('Search activities'),
        '#attributes' => ['type' => 'submit'],
      ],
    ];

    if ($days === 30) {
      $form['days'] = [
        '#type' => 'html_tag',
        '#tag' => 'input',
        '#attributes' => [
          'type' => 'hidden',
          'name' => 'days',
          'value' => '30',
        ],
      ];
    }
    if ($query !== '') {
      $form['clear'] = [
        '#type' => 'link',
        '#title' => $this->t('Clear search'),
        '#url' => Url::fromRoute(
          'personal_secretary.my_upcoming',
          [],
          $this->upcomingUrlOptions($days, ''),
        ),
      ];
    }

    return $form;
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
      '#type' => 'container',
      '#attributes' => ['class' => ['ps-upcoming-actions']],
      'add_activity' => [
        '#type' => 'link',
        '#title' => $this->t('Add activity'),
        '#url' => Url::fromRoute('personal_secretary.add_activity'),
        '#attributes' => ['class' => ['ps-upcoming-actions__add']],
      ],
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
