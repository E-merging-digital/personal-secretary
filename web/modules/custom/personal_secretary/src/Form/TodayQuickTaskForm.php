<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\personal_secretary\Entity\PersonalTask;
use Drupal\personal_secretary\Service\PersonalTaskMutationService;
use Drupal\personal_secretary\Service\TodayService;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creates one self-owned task without leaving the Today surface.
 */
final class TodayQuickTaskForm extends FormBase {

  public function __construct(
    private readonly PersonalTaskMutationService $taskMutations,
    private readonly TodayService $todayService,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.personal_task_mutation'),
      $container->get('personal_secretary.today'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_today_quick_task';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    try {
      $eligible = $this->taskMutations->eligibleHouseholds();
    }
    catch (InvalidArgumentException) {
      $eligible = [];
    }

    if ($eligible === []) {
      return [
        'remediation' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Link an authorized Household member to add tasks.'),
        ],
      ];
    }

    $form['#attributes']['class'][] = 'ps-today-quick-task';
    $form['quick_task_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('New task'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#size' => 36,
      '#attributes' => ['autocomplete' => 'off'],
    ];
    $form['quick_task_due'] = [
      '#type' => 'select',
      '#title' => $this->t('When'),
      '#required' => TRUE,
      '#options' => [
        'today' => $this->t('Today'),
        'tomorrow' => $this->t('Tomorrow'),
        'none' => $this->t('No due date'),
      ],
      '#default_value' => 'today',
    ];

    if (count($eligible) === 1) {
      $household = reset($eligible);
      $form['household_context'] = [
        '#type' => 'item',
        '#title' => $this->t('Household', [], ['context' => 'PersonalTask']),
        '#markup' => $this->t('@household', ['@household' => (string) $household->label()]),
      ];
    }
    else {
      $options = [];
      foreach ($eligible as $id => $household) {
        $options[$id] = (string) $household->label();
      }
      $form['quick_task_household'] = [
        '#type' => 'select',
        '#title' => $this->t('Household', [], ['context' => 'PersonalTask']),
        '#required' => TRUE,
        '#empty_option' => $this->t('- Select -'),
        '#options' => $options,
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add task now'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $title = trim((string) $form_state->getValue('quick_task_title'));
    if ($title === '' || mb_strlen($title) > 255) {
      $form_state->setErrorByName('quick_task_title', $this->t('Enter a task of at most 255 characters.'));
    }

    if (!in_array($form_state->getValue('quick_task_due'), ['today', 'tomorrow', 'none'], TRUE)) {
      $form_state->setErrorByName('quick_task_due', $this->t('Select a valid due date.'));
    }

    try {
      $this->selectedHouseholdId($form_state);
    }
    catch (InvalidArgumentException) {
      $form_state->setErrorByName('quick_task_household', $this->t('The selected Household is not available.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $when = (string) $form_state->getValue('quick_task_due');
      $dueMode = $when === 'none' ? PersonalTask::DUE_NONE : PersonalTask::DUE_DATE;
      $dueDate = NULL;
      if ($dueMode === PersonalTask::DUE_DATE) {
        // Resolve at POST time, never from a stale pre-rendered date or UTC day.
        $localDate = (string) $this->todayService->today()['local_date'];
        $date = new DateTimeImmutable($localDate, new DateTimeZone('UTC'));
        $dueDate = $when === 'tomorrow'
          ? $date->modify('+1 day')->format('Y-m-d')
          : $localDate;
      }
      $this->taskMutations->createTask(
        (string) $form_state->getValue('quick_task_title'),
        $this->selectedHouseholdId($form_state),
        $dueMode,
        $dueDate,
      );
      $this->messenger()->addStatus($this->t('Task added.'));
    }
    catch (InvalidArgumentException) {
      $this->messenger()->addError($this->t('The task could not be added because its authorization or data changed.'));
    }

    // PRG ensures that revisiting or refreshing Today never replays the POST.
    $form_state->setRedirect('personal_secretary.today');
  }

  private function selectedHouseholdId(FormStateInterface $form_state): int {
    $eligible = $this->taskMutations->eligibleHouseholds();
    if (count($eligible) === 1) {
      return (int) array_key_first($eligible);
    }

    $selected = (int) $form_state->getValue('quick_task_household');
    if ($selected <= 0 || !isset($eligible[$selected])) {
      throw new InvalidArgumentException('Selected Household is no longer eligible.');
    }
    return $selected;
  }

}
