<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\TimeZoneFormHelper;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\personal_secretary\Service\FirstActivitySetupService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the deliberately narrow first-activity onboarding form.
 */
final class FirstActivitySetupForm extends FormBase {

  public function __construct(
    private readonly FirstActivitySetupService $setup,
    private readonly ConfigFactoryInterface $setupConfigFactory,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.first_activity_setup'),
      $container->get('config.factory'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_first_activity_setup';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $timezones = TimeZoneFormHelper::getOptionsList();
    $defaultTimezone = (string) $this->setupConfigFactory
      ->get('system.date')
      ->get('timezone.default');
    if ($defaultTimezone === '' || !isset($timezones[$defaultTimezone])) {
      $defaultTimezone = 'UTC';
    }

    $form['household_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Household name'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['responsible_person_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Responsible Person name'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['activity_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Activity label'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['recurrence'] = [
      '#type' => 'item',
      '#title' => $this->t('Recurrence'),
      '#markup' => $this->t('Weekly'),
    ];
    $form['first_occurrence_date'] = [
      '#type' => 'date',
      '#title' => $this->t('First occurrence date'),
      '#required' => TRUE,
    ];
    $form['start_local_time'] = [
      '#type' => 'date',
      '#title' => $this->t('Start time'),
      '#attributes' => ['type' => 'time'],
      '#required' => TRUE,
    ];
    $form['end_local_time'] = [
      '#type' => 'date',
      '#title' => $this->t('End time'),
      '#attributes' => ['type' => 'time'],
      '#required' => TRUE,
    ];
    $form['source_timezone'] = [
      '#type' => 'select',
      '#title' => $this->t('Source timezone'),
      '#options' => $timezones,
      '#default_value' => $defaultTimezone,
      '#required' => TRUE,
    ];
    $form['preparation_instruction'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Preparation instruction'),
      '#description' => $this->t('Optional.'),
      '#maxlength' => 255,
    ];
    $form['preparation_lead_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Preparation lead time in minutes'),
      '#description' => $this->t('Used only when a preparation instruction is provided.'),
      '#default_value' => 0,
      '#min' => 0,
      '#step' => 1,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create first activity'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $timezoneName = (string) $form_state->getValue('source_timezone');
    if (!isset(TimeZoneFormHelper::getOptionsList()[$timezoneName])) {
      $form_state->setErrorByName('source_timezone', $this->t('Select a valid source timezone.'));
      return;
    }

    $date = (string) $form_state->getValue('first_occurrence_date');
    $startTime = (string) $form_state->getValue('start_local_time');
    $endTime = (string) $form_state->getValue('end_local_time');
    $timezone = new DateTimeZone($timezoneName);
    $localStart = $this->parseLocalDateTime($date, $startTime, $timezone);
    $localEnd = $this->parseLocalDateTime($date, $endTime, $timezone);

    if (!$localStart instanceof DateTimeImmutable) {
      $form_state->setErrorByName('start_local_time', $this->t('Enter a valid start date and time.'));
    }
    if (!$localEnd instanceof DateTimeImmutable) {
      $form_state->setErrorByName('end_local_time', $this->t('Enter a valid end date and time.'));
    }
    if ($localStart instanceof DateTimeImmutable && $localEnd instanceof DateTimeImmutable) {
      if ($localEnd <= $localStart) {
        $form_state->setErrorByName('end_local_time', $this->t('End time must be after start time.'));
      }
      else {
        $form_state->set('personal_secretary_local_start', $localStart);
        $form_state->set('personal_secretary_local_end', $localEnd);
      }
    }

    $instruction = trim((string) $form_state->getValue('preparation_instruction'));
    if ($instruction !== '') {
      $lead = filter_var(
        $form_state->getValue('preparation_lead_minutes'),
        FILTER_VALIDATE_INT,
      );
      if ($lead === FALSE || $lead < 0) {
        $form_state->setErrorByName(
          'preparation_lead_minutes',
          $this->t('Preparation lead time must be zero or greater.'),
        );
      }
      else {
        $form_state->set('personal_secretary_preparation_lead_minutes', $lead);
      }
    }
    else {
      $form_state->set('personal_secretary_preparation_lead_minutes', 0);
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $localStart = $form_state->get('personal_secretary_local_start');
    $localEnd = $form_state->get('personal_secretary_local_end');
    if (!$localStart instanceof DateTimeImmutable || !$localEnd instanceof DateTimeImmutable) {
      throw new \LogicException('Validated setup datetimes are unavailable.');
    }

    $this->setup->createFirstActivity(
      (string) $form_state->getValue('household_name'),
      (string) $form_state->getValue('responsible_person_name'),
      (string) $form_state->getValue('activity_label'),
      $localStart,
      $localEnd,
      (string) $form_state->getValue('preparation_instruction'),
      (int) $form_state->get('personal_secretary_preparation_lead_minutes'),
    );

    $form_state->setRedirect('personal_secretary.upcoming');
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
