<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\personal_secretary\Service\CurrentUserOccurrenceRescheduleService;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reschedules one authorized exact occurrence in its source timezone.
 */
final class RescheduleOccurrenceForm extends FormBase {

  public function __construct(
    private readonly CurrentUserOccurrenceRescheduleService $currentUserReschedule,
    private readonly RouteMatchInterface $rescheduleRouteMatch,
    private readonly AccountInterface $rescheduleCurrentUser,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.current_user_occurrence_reschedule'),
      $container->get('current_route_match'),
      $container->get('current_user'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_reschedule_occurrence';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    try {
      $resolved = $this->currentUserReschedule->authorize(
        $this->seriesId(),
        $this->originalOccurrenceKey(),
      );
    }
    catch (InvalidArgumentException $exception) {
      if ($this->getRequest()->isMethod('POST')) {
        return $this->stalePostForm($form);
      }
      throw new NotFoundHttpException('The requested occurrence cannot be rescheduled.', $exception);
    }

    $occurrence = $resolved['occurrence'];
    $sourceStart = new DateTimeImmutable($occurrence->sourceLocalStart);
    $sourceEnd = new DateTimeImmutable($occurrence->sourceLocalEnd);

    $form['activity'] = [
      '#type' => 'item',
      '#title' => $this->t('Activity'),
      '#markup' => Html::escape((string) $resolved['series']->label()),
    ];
    $form['scope_notice'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Only this date will be rescheduled. The recurring activity and its other occurrences stay unchanged.'),
    ];
    $form['source_timezone'] = [
      '#type' => 'item',
      '#title' => $this->t('Source timezone'),
      '#markup' => Html::escape($occurrence->sourceTimezone),
    ];
    $form['new_date'] = [
      '#type' => 'date',
      '#title' => $this->t('New date'),
      '#default_value' => $sourceStart->format('Y-m-d'),
      '#required' => TRUE,
    ];
    $form['new_local_start_time'] = [
      '#type' => 'date',
      '#title' => $this->t('New start time'),
      '#attributes' => ['type' => 'time'],
      '#default_value' => $sourceStart->format('H:i'),
      '#required' => TRUE,
    ];
    $form['new_local_end_time'] = [
      '#type' => 'date',
      '#title' => $this->t('New end time'),
      '#attributes' => ['type' => 'time'],
      '#default_value' => $sourceEnd->format('H:i'),
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reschedule occurrence'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $this->fromMyUpcoming()
        ? Url::fromRoute('personal_secretary.my_upcoming', [], $this->returnOptions())
        : Url::fromRoute('personal_secretary.occurrence_detail', [
          'series' => $this->seriesId(),
          'original_occurrence_key' => $this->originalOccurrenceKey(),
        ]),
      '#attributes' => ['class' => ['button']],
    ];

    if ($this->fromMyUpcoming()) {
      // Preserve only a bounded navigation origin across the confirmation POST.
      $form['#action'] = Url::fromRoute('personal_secretary.reschedule_occurrence', [
        'series' => $this->seriesId(),
        'original_occurrence_key' => $this->originalOccurrenceKey(),
      ], ['query' => ['from' => 'mine'] + $this->returnQuery()])->toString();
    }

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->getResponse() !== NULL) {
      return;
    }

    try {
      $resolved = $this->currentUserReschedule->authorize(
        $this->seriesId(),
        $this->originalOccurrenceKey(),
      );
    }
    catch (InvalidArgumentException) {
      $form_state->set('personal_secretary_reschedule_stale', TRUE);
      return;
    }

    $timezone = new DateTimeZone($resolved['occurrence']->sourceTimezone);
    $date = (string) $form_state->getValue('new_date');
    $startTime = (string) $form_state->getValue('new_local_start_time');
    $endTime = (string) $form_state->getValue('new_local_end_time');
    $localStart = $this->parseLocalDateTime($date, $startTime, $timezone);
    $localEnd = $this->parseLocalDateTime($date, $endTime, $timezone);

    if (!$localStart instanceof DateTimeImmutable) {
      $form_state->setErrorByName('new_local_start_time', $this->t('Enter a valid new date and start time.'));
    }
    if (!$localEnd instanceof DateTimeImmutable) {
      $form_state->setErrorByName('new_local_end_time', $this->t('Enter a valid new date and end time.'));
    }
    if ($localStart instanceof DateTimeImmutable && $localEnd instanceof DateTimeImmutable) {
      if ($localEnd <= $localStart) {
        $form_state->setErrorByName('new_local_end_time', $this->t('New end time must be after new start time.'));
      }
      else {
        $form_state->set('personal_secretary_reschedule_local_start', $localStart);
        $form_state->set('personal_secretary_reschedule_local_end', $localEnd);
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->get('personal_secretary_reschedule_stale') === TRUE) {
      $this->staleResponse($form_state);
      return;
    }

    $localStart = $form_state->get('personal_secretary_reschedule_local_start');
    $localEnd = $form_state->get('personal_secretary_reschedule_local_end');
    if (!$localStart instanceof DateTimeImmutable || !$localEnd instanceof DateTimeImmutable) {
      throw new \LogicException('Validated reschedule datetimes are unavailable.');
    }

    try {
      $this->currentUserReschedule->reschedule(
        $this->seriesId(),
        $this->originalOccurrenceKey(),
        $localStart,
        $localEnd,
      );
    }
    catch (InvalidArgumentException) {
      $this->messenger()->addError($this->t('This occurrence can no longer be rescheduled.'));
    }

    $form_state->setRedirect($this->returnRoute(), [], $this->returnOptions());
  }

  private function stalePostForm(array $form): array {
    $form['new_date'] = [
      '#type' => 'date',
      '#title' => $this->t('New date'),
      '#required' => TRUE,
    ];
    foreach (['new_local_start_time' => 'New start time', 'new_local_end_time' => 'New end time'] as $name => $title) {
      $form[$name] = [
        '#type' => 'date',
        '#title' => $this->t($title),
        '#attributes' => ['type' => 'time'],
        '#required' => TRUE,
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reschedule occurrence'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  private function staleResponse(FormStateInterface $form_state): void {
    $this->messenger()->addError($this->t('This occurrence can no longer be rescheduled.'));
    $url = Url::fromRoute($this->returnRoute(), [], $this->returnOptions())->toString();
    $form_state->setResponse(new RedirectResponse($url));
  }

  private function fromMyUpcoming(): bool {
    return ($this->getRequest()->query->all()['from'] ?? NULL) === 'mine';
  }

  /**
   * @return array<string, string>
   */
  private function returnQuery(): array {
    $input = $this->getRequest()->query->all();
    $query = [];
    if (($input['days'] ?? NULL) === '30') {
      $query['days'] = '30';
    }
    $search = $input['q'] ?? NULL;
    if (is_string($search)) {
      $search = trim($search);
      if ($search !== '' && mb_strlen($search) <= 100) {
        $query['q'] = $search;
      }
    }
    return $query;
  }

  /**
   * @return array<string, mixed>
   */
  private function returnOptions(): array {
    if (!$this->fromMyUpcoming()) {
      return [];
    }
    $query = $this->returnQuery();
    return $query === [] ? [] : ['query' => $query];
  }

  private function returnRoute(): string {
    if ($this->fromMyUpcoming()) {
      return 'personal_secretary.my_upcoming';
    }
    return $this->rescheduleCurrentUser->hasPermission(HouseholdAuthorizationService::ADMIN_PERMISSION)
      ? 'personal_secretary.upcoming'
      : 'personal_secretary.my_upcoming';
  }

  private function seriesId(): int {
    return (int) $this->rescheduleRouteMatch->getParameter('series');
  }

  private function originalOccurrenceKey(): string {
    return (string) $this->rescheduleRouteMatch->getParameter('original_occurrence_key');
  }

  private function parseLocalDateTime(
    string $date,
    string $time,
    DateTimeZone $timezone,
  ): ?DateTimeImmutable {
    $value = DateTimeImmutable::createFromFormat(
      '!Y-m-d H:i',
      $date . ' ' . $time,
      $timezone,
    );
    if (!$value instanceof DateTimeImmutable) {
      return NULL;
    }

    return $value->format('Y-m-d H:i') === $date . ' ' . $time ? $value : NULL;
  }

}
