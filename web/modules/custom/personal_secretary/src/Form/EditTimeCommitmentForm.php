<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\personal_secretary\Service\EditTimeCommitmentService;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Edits future series-level time commitment without revising ActivitySeries.
 */
final class EditTimeCommitmentForm extends FormBase {

  public function __construct(
    private readonly EditTimeCommitmentService $timeCommitmentEditor,
    private readonly RouteMatchInterface $timeCommitmentRouteMatch,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.edit_time_commitment'),
      $container->get('current_route_match'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_edit_time_commitment';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $context = $this->resolveContext();

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
    $form['current_mode'] = [
      '#type' => 'item',
      '#title' => $this->t('Time commitment at the next affected occurrence'),
      '#markup' => $context['default_mode'] === EditTimeCommitmentService::MODE_FULL_OCCURRENCE
        ? $this->t('Full occurrence')
        : $this->t('None'),
    ];
    $form['effective_from_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Effective from date'),
      '#default_value' => $context['default_effective_date'],
      '#required' => TRUE,
    ];
    $form['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Time commitment'),
      '#options' => [
        EditTimeCommitmentService::MODE_NONE => $this->t('None'),
        EditTimeCommitmentService::MODE_FULL_OCCURRENCE => $this->t('Full occurrence'),
      ],
      '#default_value' => $context['default_mode'],
      '#required' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save time commitment'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->timeCommitmentEditor->prepare(
        $this->seriesId(),
        (string) $form_state->getValue('effective_from_date'),
        (string) $form_state->getValue('mode'),
      );
    }
    catch (InvalidArgumentException | RuntimeException $exception) {
      $form_state->setErrorByName(
        'effective_from_date',
        $this->t('The time commitment change is not valid: @message', [
          '@message' => $exception->getMessage(),
        ]),
      );
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->timeCommitmentEditor->apply(
        $this->seriesId(),
        (string) $form_state->getValue('effective_from_date'),
        (string) $form_state->getValue('mode'),
      );
    }
    catch (InvalidArgumentException | RuntimeException) {
      $this->messenger()->addError($this->t('This time commitment can no longer be changed safely.'));
    }

    $form_state->setRedirect('personal_secretary.upcoming');
  }

  /**
   * @return array<string, mixed>
   */
  private function resolveContext(): array {
    try {
      return $this->timeCommitmentEditor->context($this->seriesId());
    }
    catch (InvalidArgumentException | RuntimeException $exception) {
      throw new NotFoundHttpException('The requested time commitment cannot be edited.', $exception);
    }
  }

  private function seriesId(): int {
    return (int) $this->timeCommitmentRouteMatch->getParameter('series');
  }

}
