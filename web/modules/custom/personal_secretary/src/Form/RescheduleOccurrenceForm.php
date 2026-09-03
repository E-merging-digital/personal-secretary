<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\personal_secretary\Service\RescheduleOccurrenceService;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reschedules one exact upcoming base occurrence in its source timezone.
 */
final class RescheduleOccurrenceForm extends FormBase {

  public function __construct(
    private readonly RescheduleOccurrenceService $rescheduleOccurrence,
    private readonly RouteMatchInterface $rescheduleRouteMatch,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.reschedule_occurrence'),
      $container->get('current_route_match'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_reschedule_occurrence';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $resolved = $this->resolveTarget();
    $occurrence = $resolved['occurrence'];
    $sourceStart = new DateTimeImmutable($occurrence->sourceLocalStart);
    $sourceEnd = new DateTimeImmutable($occurrence->sourceLocalEnd);

    $form['activity'] = [
      '#type' => 'item',
      '#title' => $this->t('Activity'),
      '#markup' => Html::escape((string) $resolved['series']->label()),
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

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $resolved = $this->resolveTarget();
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
    $localStart = $form_state->get('personal_secretary_reschedule_local_start');
    $localEnd = $form_state->get('personal_secretary_reschedule_local_end');
    if (!$localStart instanceof DateTimeImmutable || !$localEnd instanceof DateTimeImmutable) {
      throw new \LogicException('Validated reschedule datetimes are unavailable.');
    }

    try {
      $this->rescheduleOccurrence->reschedule(
        $this->seriesId(),
        $this->originalOccurrenceKey(),
        $localStart,
        $localEnd,
      );
    }
    catch (InvalidArgumentException) {
      $this->messenger()->addError($this->t('This occurrence can no longer be rescheduled.'));
    }

    $form_state->setRedirect('personal_secretary.upcoming');
  }

  /**
   * @return array{series: \Drupal\personal_secretary\Entity\ActivitySeries, occurrence: \Drupal\personal_secretary\Value\BaseOccurrence}
   */
  private function resolveTarget(): array {
    try {
      return $this->rescheduleOccurrence->resolve(
        $this->seriesId(),
        $this->originalOccurrenceKey(),
      );
    }
    catch (InvalidArgumentException $exception) {
      throw new NotFoundHttpException('The requested occurrence cannot be rescheduled.', $exception);
    }
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
