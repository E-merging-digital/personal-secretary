<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\personal_secretary\Service\EditRecurringResponsibilityService;
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

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save recurring responsibility'),
      '#button_type' => 'primary',
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
    catch (InvalidArgumentException | RuntimeException $exception) {
      $form_state->setErrorByName(
        'effective_from_date',
        $this->t('The recurring responsibility change is not valid: @message', [
          '@message' => $exception->getMessage(),
        ]),
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
