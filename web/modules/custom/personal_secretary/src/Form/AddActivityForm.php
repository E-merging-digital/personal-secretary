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
 * Adds an activity to an existing Household context.
 */
final class AddActivityForm extends FormBase {

  private const TYPE_ONE_OFF = 'one_off';

  private const TYPE_WEEKLY = 'weekly';

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
    $form['activity_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Activity type'),
      '#options' => [
        self::TYPE_ONE_OFF => $this->t('One-off'),
        self::TYPE_WEEKLY => $this->t('Weekly'),
      ],
      '#default_value' => self::TYPE_WEEKLY,
      '#required' => TRUE,
    ];
    $form['responsible_person_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Responsible Person'),
      '#options' => ['' => $this->t('- None -')] + $people,
      '#required' => FALSE,
      '#description' => $this->t('Required for weekly activities; optional for one-off activities.'),
    ];
    $form['activity_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Activity label'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['location'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Location'),
      '#description' => $this->t('Optional.'),
      '#required' => FALSE,
      '#maxlength' => 255,
    ];
    $form['first_occurrence_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Activity date'),
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
    $activityType = (string) $form_state->getValue('activity_type');
    if (!in_array($activityType, [self::TYPE_ONE_OFF, self::TYPE_WEEKLY], TRUE)) {
      $form_state->setErrorByName('activity_type', $this->t('Select a valid activity type.'));
    }
    else {
      $form_state->set('personal_secretary_activity_type', $activityType);
    }

    $responsiblePersonId = NULL;
    $responsiblePersonValue = trim((string) $form_state->getValue('responsible_person_id'));
    if ($responsiblePersonValue !== '') {
      $validatedResponsiblePersonId = filter_var($responsiblePersonValue, FILTER_VALIDATE_INT);
      if ($validatedResponsiblePersonId === FALSE || $validatedResponsiblePersonId <= 0) {
        $form_state->setErrorByName('responsible_person_id', $this->t('Select a valid responsible Person.'));
      }
      else {
        $responsiblePersonId = $validatedResponsiblePersonId;
      }
    }
    if ($activityType === self::TYPE_WEEKLY && $responsiblePersonId === NULL) {
      $form_state->setErrorByName('responsible_person_id', $this->t('Select a responsible Person for a weekly activity.'));
    }
    $form_state->set('personal_secretary_responsible_person_id', $responsiblePersonId);

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

    $activityType = $form_state->get('personal_secretary_activity_type');
    $responsiblePersonId = $form_state->get('personal_secretary_responsible_person_id');
    $location = (string) $form_state->getValue('location');

    try {
      if ($activityType === self::TYPE_WEEKLY) {
        if (!is_int($responsiblePersonId)) {
          throw new \LogicException('Validated weekly responsible Person is unavailable.');
        }
        $this->addActivity->addWeeklyActivity(
          (int) $form_state->getValue('household_id'),
          $responsiblePersonId,
          (string) $form_state->getValue('activity_label'),
          $localStart,
          $localEnd,
          (string) $form_state->getValue('preparation_instruction'),
          (int) $form_state->get('personal_secretary_preparation_lead_minutes'),
          $location,
        );
      }
      elseif ($activityType === self::TYPE_ONE_OFF) {
        $this->addActivity->addOneOffActivity(
          (int) $form_state->getValue('household_id'),
          is_int($responsiblePersonId) ? $responsiblePersonId : NULL,
          (string) $form_state->getValue('activity_label'),
          $localStart,
          $localEnd,
          (string) $form_state->getValue('preparation_instruction'),
          (int) $form_state->get('personal_secretary_preparation_lead_minutes'),
          $location,
        );
      }
      else {
        throw new \LogicException('Validated activity type is unavailable.');
      }
    }
    catch (InvalidArgumentException) {
      $this->messenger()->addError($this->t('The activity could not be created for the selected household and options.'));
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
