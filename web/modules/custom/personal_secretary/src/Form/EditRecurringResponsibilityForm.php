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
use Drupal\personal_secretary\Service\EditRecurringResponsibilityService;
use Drupal\personal_secretary\Value\EffectiveResponsibility;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Changes the future recurring responsible Person for one ActivitySeries.
 */
final class EditRecurringResponsibilityForm extends FormBase {

  public function __construct(
    private readonly EditRecurringResponsibilityService $responsibilityEditor,
    private readonly RouteMatchInterface $responsibilityRouteMatch,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.edit_recurring_responsibility'),
      $container->get('current_route_match'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_edit_recurring_responsibility';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $context = $this->resolveContext();
    $currentStart = $context['current_local_start'];
    $currentEnd = $context['current_local_end'];

    $form['activity'] = [
      '#type' => 'item',
      '#title' => $this->t('Activity'),
      '#markup' => Html::escape((string) $context['series']->label()),
    ];
    $form['current_responsibility'] = [
      '#type' => 'item',
      '#title' => $this->t('Current recurring responsible Person'),
      '#markup' => Html::escape((string) $context['responsible_person']->label()),
    ];
    $form['current_schedule'] = [
      '#type' => 'item',
      '#title' => $this->t('Current weekly schedule'),
      '#markup' => $this->t(
        'Weekly on @weekday, @start–@end',
        [
          '@weekday' => $currentStart->format('l'),
          '@start' => $currentStart->format('H:i'),
          '@end' => $currentEnd->format('H:i'),
        ],
      ),
    ];
    $form['source_timezone'] = [
      '#type' => 'item',
      '#title' => $this->t('Source timezone'),
      '#markup' => Html::escape($context['source_timezone']),
    ];

    $form['effective_from_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Effective from date'),
      '#default_value' => $context['default_effective_date'],
      '#required' => TRUE,
      '#description' => $this->t('The change starts with the first normal recurring session on or after this date.'),
    ];
    $form['responsible_person_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Recurring responsible Person'),
      '#options' => $context['member_options'],
      '#default_value' => (string) $context['responsible_person']->id(),
      '#required' => TRUE,
    ];

    $snapshot = $this->responsibilityEditor->currentPreview($this->seriesId());
    $rows = [];
    foreach ($snapshot['rows'] as $entry) {
      $source = match ($entry['source']) {
        EffectiveResponsibility::SOURCE_OVERRIDE => $entry['state'] === EffectiveResponsibility::STATE_NONE
          ? $this->t('Occurrence exception: no responsible Person')
          : $this->t('Occurrence exception: assigned Person'),
        EffectiveResponsibility::SOURCE_RULE => $this->t('Usual recurring rule'),
        default => $this->t('No usual responsibility'),
      };
      $rows[] = [
        ['data' => ['#plain_text' => $this->formatPreviewTime($entry)]],
        ['data' => ['#plain_text' => $entry['person_label'] ?? (string) $this->t('No responsible Person')]],
        ['data' => ['#plain_text' => (string) $source]],
      ];
    }
    $form['preview_explanation'] = [
      '#type' => 'item',
      '#markup' => $this->t('This read-only preview shows up to six future occurrences starting within the next 35 days in the activity timezone. Other occurrences may exist outside this window. It does not reflect your unsaved choices.'),
    ];
    $form['preview'] = [
      '#type' => 'table',
      '#caption' => $this->t('Upcoming responsibility: current saved state'),
      '#header' => [
        $this->t('Date and time'),
        $this->t('Responsible Person'),
        $this->t('Responsibility source'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No future occurrences start within the next 35 days.'),
      '#attributes' => ['data-ps-preview' => 'recurring-responsibility'],
    ];
    if ($snapshot['has_more']) {
      $form['preview_more'] = [
        '#type' => 'item',
        '#markup' => $this->t('More occurrences exist within this 35-day window; only the first six are shown.'),
      ];
    }
    $form['preview_consequences'] = [
      '#type' => 'item',
      '#markup' => $this->t('Changing the usual responsible Person starts at the first eligible recurring occurrence on or after the selected date. Existing occurrence-specific exceptions remain unchanged. This preview shows saved state, not the proposed change.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save recurring responsibility'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('personal_secretary.upcoming'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->responsibilityEditor->prepare(
        $this->seriesId(),
        (string) $form_state->getValue('effective_from_date'),
        (int) $form_state->getValue('responsible_person_id'),
      );
    }
    catch (InvalidArgumentException | RuntimeException) {
      $form_state->setErrorByName(
        'effective_from_date',
        $this->t('The recurring responsibility change is not valid.'),
      );
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $result = $this->responsibilityEditor->apply(
        $this->seriesId(),
        (string) $form_state->getValue('effective_from_date'),
        (int) $form_state->getValue('responsible_person_id'),
      );
      if ($result['noop']) {
        $this->messenger()->addStatus($this->t('Recurring responsibility is already assigned to that Person.'));
      }
    }
    catch (InvalidArgumentException | RuntimeException) {
      $this->messenger()->addError($this->t('This recurring responsibility can no longer be changed safely.'));
    }

    $form_state->setRedirect('personal_secretary.upcoming');
  }

  /**
   * Displays source-local dates without modifying any occurrence.
   *
   * @param array<string, mixed> $entry
   */
  private function formatPreviewTime(array $entry): string {
    $timezone = new DateTimeZone($entry['timezone']);
    $start = (new DateTimeImmutable($entry['start']))->setTimezone($timezone);
    $end = (new DateTimeImmutable($entry['end']))->setTimezone($timezone);
    if ($entry['time_mode'] === ActivitySeries::TIME_MODE_ALL_DAY) {
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
   * @return array<string, mixed>
   */
  private function resolveContext(): array {
    try {
      return $this->responsibilityEditor->context($this->seriesId());
    }
    catch (InvalidArgumentException | RuntimeException $exception) {
      throw new NotFoundHttpException('The requested recurring responsibility cannot be edited.', $exception);
    }
  }

  private function seriesId(): int {
    return (int) $this->responsibilityRouteMatch->getParameter('series');
  }

}
