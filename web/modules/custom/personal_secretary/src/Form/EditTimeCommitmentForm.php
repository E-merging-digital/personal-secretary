<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
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
      '#title' => $this->t('Current setting for next affected occurrence'),
      '#markup' => $context['default_mode'] === EditTimeCommitmentService::MODE_FULL_OCCURRENCE
        ? $this->t('Yes, for the full activity')
        : $this->t('No'),
    ];
    $form['effective_from_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Effective from date'),
      '#description' => $this->t('Changes take effect from the first future occurrence on or after this date in the activity\'s timezone. Earlier occurrences stay unchanged.'),
      '#default_value' => $context['default_effective_date'],
      '#required' => TRUE,
    ];
    $form['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Does this activity occupy your time?'),
      '#description' => $this->t('Being responsible for an activity does not necessarily mean it occupies your time. No leaves your time free; Yes, for the full activity marks the entire occurrence as occupied only when you are responsible. This does not send an event to an external calendar.'),
      '#options' => [
        EditTimeCommitmentService::MODE_NONE => $this->t('No'),
        EditTimeCommitmentService::MODE_FULL_OCCURRENCE => $this->t('Yes, for the full activity'),
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
      $this->timeCommitmentEditor->prepare(
        $this->seriesId(),
        (string) $form_state->getValue('effective_from_date'),
        (string) $form_state->getValue('mode'),
      );
    }
    catch (InvalidArgumentException | RuntimeException) {
      $form_state->setErrorByName(
        'effective_from_date',
        $this->t('The time commitment change is not valid.'),
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
