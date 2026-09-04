<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\personal_secretary\Service\EditRecurringScheduleService;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Edits the future weekly schedule of one ActivitySeries.
 */
final class EditRecurringScheduleForm extends FormBase {

  public function __construct(
    private readonly EditRecurringScheduleService $scheduleEditor,
    private readonly RouteMatchInterface $scheduleRouteMatch,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.edit_recurring_schedule'),
      $container->get('current_route_match'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_edit_recurring_schedule';
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
    $form['recurrence'] = [
      '#type' => 'item',
      '#title' => $this->t('Recurrence'),
      '#markup' => $this->t('Weekly'),
    ];
    $form['current_responsibility'] = [
      '#type' => 'item',
      '#title' => $this->t('Current recurring responsible Person'),
      '#markup' => Html::escape((string) $context['responsible_person']->label()),
    ];
    $form['warning'] = [
      '#type' => 'item',
      '#title' => $this->t('Occurrence-specific adjustments'),
      '#markup' => $this->t('Future cancellations, reschedules, or responsibility changes tied to the old audited schedule may need to be redone after this change.'),
    ];

    $form['effective_from_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Effective from date'),
      '#default_value' => $context['default_effective_date'],
      '#required' => TRUE,
    ];
    $form['new_start_local_time'] = [
      '#type' => 'date',
      '#title' => $this->t('New start time'),
      '#attributes' => ['type' => 'time'],
      '#default_value' => $currentStart->format('H:i'),
      '#required' => TRUE,
    ];
    $form['new_end_local_time'] = [
      '#type' => 'date',
      '#title' => $this->t('New end time'),
      '#attributes' => ['type' => 'time'],
      '#default_value' => $currentEnd->format('H:i'),
      '#required' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save recurring schedule'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->scheduleEditor->prepare(
        $this->seriesId(),
        (string) $form_state->getValue('effective_from_date'),
        (string) $form_state->getValue('new_start_local_time'),
        (string) $form_state->getValue('new_end_local_time'),
      );
    }
    catch (InvalidArgumentException | RuntimeException $exception) {
      $form_state->setErrorByName(
        'effective_from_date',
        $this->t('The recurring schedule change is not valid: @message', [
          '@message' => $exception->getMessage(),
        ]),
      );
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->scheduleEditor->apply(
        $this->seriesId(),
        (string) $form_state->getValue('effective_from_date'),
        (string) $form_state->getValue('new_start_local_time'),
        (string) $form_state->getValue('new_end_local_time'),
      );
    }
    catch (InvalidArgumentException | RuntimeException) {
      $this->messenger()->addError($this->t('This recurring schedule can no longer be changed safely.'));
    }

    $form_state->setRedirect('personal_secretary.upcoming');
  }

  /**
   * @return array<string, mixed>
   */
  private function resolveContext(): array {
    try {
      return $this->scheduleEditor->context($this->seriesId());
    }
    catch (InvalidArgumentException | RuntimeException $exception) {
      throw new NotFoundHttpException('The requested recurring schedule cannot be edited.', $exception);
    }
  }

  private function seriesId(): int {
    return (int) $this->scheduleRouteMatch->getParameter('series');
  }

}
