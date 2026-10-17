<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Service\PauseRecurringActivityService;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Previews and confirms one bounded recurring-activity pause.
 */
final class PauseRecurringActivityForm extends FormBase {

  public function __construct(
    protected readonly PauseRecurringActivityService $pause,
    protected readonly RouteMatchInterface $pauseRouteMatch,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.pause_recurring_activity'),
      $container->get('current_route_match'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_pause_recurring_activity';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $context = $this->resolveContext();
    $form['#cache']['max-age'] = 0;
    $form['activity'] = [
      '#type' => 'item',
      '#title' => $this->t('Activity'),
      '#markup' => Html::escape((string) $context['series']->label()),
    ];
    $form['source_timezone'] = [
      '#type' => 'item',
      '#title' => $this->t('Source timezone'),
      '#markup' => Html::escape($context['source_timezone']),
    ];

    if ($form_state->get('pause_step') === 'confirm') {
      return $this->buildConfirmation($form, $form_state);
    }

    $form['pause_start_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Pause start date'),
      '#required' => TRUE,
      '#default_value' => (string) ($form_state->get('pause_start_date') ?? ''),
    ];
    $form['pause_end_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Pause end date'),
      '#required' => TRUE,
      '#default_value' => (string) ($form_state->get('pause_end_date') ?? ''),
    ];
    $form['date_help'] = [
      '#type' => 'item',
      '#markup' => $this->t('Both dates are inclusive and use the activity source timezone.'),
    ];
    $form['pause_explanation'] = $this->pauseExplanation();
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['preview'] = [
      '#type' => 'submit',
      '#value' => $this->t('Preview pause'),
      '#button_type' => 'primary',
      '#validate' => ['::validatePreview'],
      '#submit' => ['::previewSubmit'],
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('personal_secretary.upcoming'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  public function validatePreview(array &$form, FormStateInterface $form_state): void {
    $startDate = trim((string) $form_state->getValue('pause_start_date'));
    $endDate = trim((string) $form_state->getValue('pause_end_date'));

    try {
      $preview = $this->pause->preview($this->seriesId(), $startDate, $endDate);
    }
    catch (InvalidArgumentException | RuntimeException) {
      $form_state->setErrorByName(
        'pause_end_date',
        $this->t('This pause range is not valid.'),
      );
      return;
    }

    $form_state->set('pause_start_date', $startDate);
    $form_state->set('pause_end_date', $endDate);
    $form_state->set('pause_preview', $preview);
  }

  public function previewSubmit(array &$form, FormStateInterface $form_state): void {
    $form_state->set('pause_step', 'confirm');
    $form_state->setRebuild(TRUE);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $startDate = (string) $form_state->get('pause_start_date');
    $endDate = (string) $form_state->get('pause_end_date');

    try {
      $result = $this->pause->apply($this->seriesId(), $startDate, $endDate);
    }
    catch (InvalidArgumentException | RuntimeException) {
      $this->messenger()->addError($this->t('This pause can no longer be applied safely. No partial pause was created.'));
      $form_state->setRedirect('personal_secretary.upcoming');
      return;
    }

    $this->messenger()->addStatus($this->t(
      'Pause applied: @cancelled occurrence(s) cancelled; @already already cancelled.',
      [
        '@cancelled' => $result['cancelled_count'],
        '@already' => $result['already_cancelled_count'],
      ],
    ));
    $form_state->setRedirect('personal_secretary.upcoming');
  }

  public function changeDatesSubmit(array &$form, FormStateInterface $form_state): void {
    $form_state->set('pause_step', 'range');
    $form_state->set('pause_preview', NULL);
    $form_state->setRebuild(TRUE);
  }

  private function buildConfirmation(array $form, FormStateInterface $form_state): array {
    $preview = $form_state->get('pause_preview');
    $startDate = (string) $form_state->get('pause_start_date');
    $endDate = (string) $form_state->get('pause_end_date');
    if (!is_array($preview) || $startDate === '' || $endDate === '') {
      $form_state->set('pause_step', 'range');
      $form_state->setRebuild(TRUE);
      return $form;
    }

    $form['range'] = [
      '#type' => 'item',
      '#title' => $this->t('Requested pause'),
      '#markup' => Html::escape($startDate . ' – ' . $endDate),
    ];
    $form['preview_cancel'] = [
      '#type' => 'item',
      '#title' => $this->t('Occurrences to cancel'),
      '#markup' => (string) (int) $preview['occurrences_to_cancel'],
    ];
    $form['preview_existing'] = [
      '#type' => 'item',
      '#title' => $this->t('Already cancelled'),
      '#markup' => (string) (int) $preview['already_cancelled'],
    ];
    $form['pause_explanation'] = $this->pauseExplanation();
    $rows = [];
    foreach ($preview['eligible_occurrences'] as $target) {
      $rows[] = [
        'data' => [
          ['data' => ['#plain_text' => $this->formatPreviewOccurrence($target, $preview['source_timezone'])]],
        ],
      ];
    }
    if (count($rows) !== (int) $preview['occurrences_to_cancel']) {
      throw new RuntimeException('Pause preview row count does not match eligible occurrences.');
    }
    $form['preview_notice'] = [
      '#type' => 'item',
      '#markup' => $this->t('Preview only: no occurrence has been cancelled. Confirmation will recheck the saved activity and targets.'),
    ];
    $form['eligible_occurrences'] = [
      '#type' => 'table',
      '#caption' => $this->t('Eligible occurrences in the selected range (not yet cancelled)'),
      '#header' => [$this->t('Date and time')],
      '#rows' => $rows,
      '#empty' => $this->t('No eligible occurrences to cancel in this range.'),
      '#attributes' => ['data-ps-pause-targets' => 'eligible'],
    ];

    if (($preview['conflict'] ?? '') === 'rescheduled') {
      $form['conflict'] = [
        '#type' => 'item',
        '#title' => $this->t('Conflict'),
        '#markup' => $this->t('@count rescheduled occurrence(s) currently start inside this pause range. The whole pause is blocked.', [
          '@count' => (int) $preview['conflict_count'],
        ]),
      ];
      $form['conflict_note'] = [
        '#type' => 'item',
        '#markup' => $this->t('This conflict blocks every cancellation. Listed dates are informational only; none will be cancelled.'),
      ];
    }
    else {
      $form['confirmation'] = [
        '#type' => 'item',
        '#markup' => $this->t('Confirming will recheck the persisted activity and every exact occurrence target before creating any cancellation.'),
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    if (($preview['conflict'] ?? '') !== 'rescheduled') {
      $form['actions']['confirm'] = [
        '#type' => 'submit',
        '#value' => $this->t('Confirm pause'),
        '#button_type' => 'primary',
        '#submit' => ['::submitForm'],
      ];
    }
    $form['actions']['change'] = [
      '#type' => 'submit',
      '#value' => $this->t('Change dates'),
      '#limit_validation_errors' => [],
      '#submit' => ['::changeDatesSubmit'],
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('personal_secretary.upcoming'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * Formats one target from its source-local effective dates, without IDs.
   *
   * @param array{start: string, end: string, time_mode: string} $target
   */
  private function formatPreviewOccurrence(array $target, string $timezoneName): string {
    $timezone = new DateTimeZone($timezoneName);
    $start = (new DateTimeImmutable($target['start']))->setTimezone($timezone);
    $end = (new DateTimeImmutable($target['end']))->setTimezone($timezone);
    if ($target['time_mode'] === ActivitySeries::TIME_MODE_ALL_DAY) {
      $lastDay = $end->modify('-1 day');
      return $start->format('Y-m-d') === $lastDay->format('Y-m-d')
        ? (string) $this->t('All day on @date', ['@date' => $start->format('Y-m-d')])
        : (string) $this->t('All day from @start to @end', [
          '@start' => $start->format('Y-m-d'),
          '@end' => $lastDay->format('Y-m-d'),
        ]);
    }
    return $start->format('Y-m-d H:i') . ' – ' . $end->format('Y-m-d H:i');
  }

  /**
   * Explains the bounded, non-persistent pause on both form steps.
   */
  private function pauseExplanation(): array {
    return [
      '#type' => 'item',
      '#markup' => $this->t('Previewing does not save changes. If confirmed, this pause cancels eligible occurrences whose effective start falls within the inclusive dates. The recurring activity is not deleted; occurrences outside the range keep their existing schedule. Future changes to the recurring schedule are not automatically paused.'),
    ];
  }

  /**
   * @return array{series: \Drupal\personal_secretary\Entity\ActivitySeries, source_timezone: string}
   */
  private function resolveContext(): array {
    try {
      return $this->pause->context($this->seriesId());
    }
    catch (InvalidArgumentException | RuntimeException $exception) {
      throw new NotFoundHttpException('The requested recurring activity cannot be paused.', $exception);
    }
  }

  private function seriesId(): int {
    return (int) $this->pauseRouteMatch->getParameter('series');
  }

}
