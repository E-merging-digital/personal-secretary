<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use DateTimeImmutable;
use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\personal_secretary\Service\OccurrenceResponsibilityService;
use Drupal\personal_secretary\Value\EffectiveResponsibility;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Changes responsibility for one current effective occurrence.
 */
final class OccurrenceResponsibilityForm extends FormBase {

  public function __construct(
    private readonly OccurrenceResponsibilityService $occurrenceResponsibility,
    private readonly RouteMatchInterface $responsibilityRouteMatch,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.occurrence_responsibility'),
      $container->get('current_route_match'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_occurrence_responsibility';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $context = $this->resolveContext();
    $occurrence = $context['occurrence'];
    $responsibility = $context['responsibility'];
    $members = $context['members'];

    $currentResponsibility = (string) $this->t('Unassigned');
    if ($responsibility->state === EffectiveResponsibility::STATE_ASSIGNED) {
      $personId = $responsibility->responsiblePersonId;
      if ($personId === NULL || !isset($members[$personId])) {
        throw new NotFoundHttpException('The current responsibility context is invalid.');
      }
      $currentResponsibility = (string) $members[$personId]->label();
    }

    $effectiveStart = new DateTimeImmutable($occurrence->effectiveSourceLocalStart);
    $effectiveEnd = new DateTimeImmutable($occurrence->effectiveSourceLocalEnd);

    $form['activity'] = [
      '#type' => 'item',
      '#title' => $this->t('Activity'),
      '#markup' => Html::escape((string) $context['series']->label()),
    ];
    $form['effective_time'] = [
      '#type' => 'item',
      '#title' => $this->t('Effective date and time'),
      '#markup' => Html::escape(sprintf(
        '%s – %s',
        $effectiveStart->format('Y-m-d H:i'),
        $effectiveEnd->format('Y-m-d H:i'),
      )),
    ];
    $form['source_timezone'] = [
      '#type' => 'item',
      '#title' => $this->t('Source timezone'),
      '#markup' => Html::escape($occurrence->sourceTimezone),
    ];
    $form['current_responsibility'] = [
      '#type' => 'item',
      '#title' => $this->t('Current responsibility'),
      '#markup' => Html::escape($currentResponsibility),
    ];

    $options = [
      OccurrenceResponsibilityService::CHOICE_USE_RECURRING => $this->t('Use recurring responsibility'),
    ];
    foreach ($members as $personId => $member) {
      $options[OccurrenceResponsibilityService::CHOICE_PERSON_PREFIX . $personId] = $this->t(
        '@person',
        ['@person' => (string) $member->label()],
      );
    }
    $options[OccurrenceResponsibilityService::CHOICE_CLEAR] = $this->t('No one for this occurrence');

    $form['responsibility_choice'] = [
      '#type' => 'radios',
      '#title' => $this->t('Responsibility for this occurrence'),
      '#options' => $options,
      '#default_value' => $this->occurrenceResponsibility->suggestedChoice($responsibility),
      '#required' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save responsibility'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->occurrenceResponsibility->apply(
        $this->seriesId(),
        $this->originalOccurrenceKey(),
        (string) $form_state->getValue('responsibility_choice'),
      );
    }
    catch (InvalidArgumentException | RuntimeException) {
      $this->messenger()->addError($this->t('This occurrence responsibility can no longer be changed.'));
    }

    $form_state->setRedirect('personal_secretary.upcoming');
  }

  /**
   * @return array{
   *   series: \Drupal\personal_secretary\Entity\ActivitySeries,
   *   occurrence: \Drupal\personal_secretary\Value\EffectiveOccurrence,
   *   responsibility: \Drupal\personal_secretary\Value\EffectiveResponsibility,
   *   members: array<int, \Drupal\personal_secretary\Entity\Person>
   * }
   */
  private function resolveContext(): array {
    try {
      return $this->occurrenceResponsibility->context(
        $this->seriesId(),
        $this->originalOccurrenceKey(),
      );
    }
    catch (InvalidArgumentException | RuntimeException $exception) {
      throw new NotFoundHttpException('The requested occurrence responsibility cannot be resolved.', $exception);
    }
  }

  private function seriesId(): int {
    return (int) $this->responsibilityRouteMatch->getParameter('series');
  }

  private function originalOccurrenceKey(): string {
    return (string) $this->responsibilityRouteMatch->getParameter('original_occurrence_key');
  }

}
