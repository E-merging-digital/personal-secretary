<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\TimeZoneFormHelper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\personal_secretary\Service\AddActivityService;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds a weekly activity to an existing Household and Person context.
 */
final class AddActivityForm extends FormBase {

  public function __construct(
    private readonly AddActivityService $addActivity,
    private readonly EntityTypeManagerInterface $domainEntityTypeManager,
    private readonly ConfigFactoryInterface $addConfigFactory,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.add_activity'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_add_activity';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $households = $this->entityOptions('personal_secretary_household');
    $people = $this->entityOptions('personal_secretary_person');
    if ($households === [] || $people === []) {
      $form['empty_context'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Set up your first household and activity before adding another activity.'),
      ];
      $form['setup'] = [
        '#type' => 'link',
        '#title' => $this->t('Add your first activity'),
        '#url' => Url::fromRoute('personal_secretary.setup'),
      ];
      return $form;
    }

    $timezones = TimeZoneFormHelper::getOptionsList();
    $defaultTimezone = (string) $this->addConfigFactory
      ->get('system.date')
      ->get('timezone.default');
    if ($defaultTimezone === '' || !isset($timezones[$defaultTimezone])) {
      $defaultTimezone = 'UTC';
    }

    $form['household_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Household'),
      '#options' => $households,
      '#required' => TRUE,
    ];
    $form['responsible_person_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Responsible Person'),
      '#options' => $people,
      '#required' => TRUE,
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
      '#value' => $this->t('Add activity'),
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
      throw new \LogicException('Validated activity datetimes are unavailable.');
    }

    try {
      $this->addActivity->addWeeklyActivity(
        (int) $form_state->getValue('household_id'),
        (int) $form_state->getValue('responsible_person_id'),
        (string) $form_state->getValue('activity_label'),
        $localStart,
        $localEnd,
        (string) $form_state->getValue('preparation_instruction'),
        (int) $form_state->get('personal_secretary_preparation_lead_minutes'),
      );
    }
    catch (InvalidArgumentException) {
      $this->messenger()->addError($this->t('The activity could not be created for the selected household and responsible Person.'));
      $form_state->setRedirect('personal_secretary.add_activity');
      return;
    }

    $form_state->setRedirect('personal_secretary.upcoming');
  }

  /**
   * @return array<string, string>
   */
  private function entityOptions(string $entityTypeId): array {
    $options = [];
    foreach ($this->domainEntityTypeManager->getStorage($entityTypeId)->loadMultiple() as $entity) {
      $options[(string) $entity->id()] = (string) $entity->label();
    }
    natcasesort($options);
    return $options;
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
