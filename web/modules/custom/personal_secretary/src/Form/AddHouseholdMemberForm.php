<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\personal_secretary\Service\AddHouseholdMemberService;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds one new Person to one selected existing Household.
 */
final class AddHouseholdMemberForm extends FormBase {

  public function __construct(
    private readonly AddHouseholdMemberService $addHouseholdMember,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.add_household_member'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_add_household_member';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $households = $this->addHouseholdMember->householdOptions();
    if ($households === []) {
      $form['empty_context'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No Household is available yet.'),
      ];
      return $form;
    }

    $form['household_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Household'),
      '#options' => $households,
      '#required' => TRUE,
    ];
    $form['person_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Person name'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add household member'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->addHouseholdMember->prepare(
        (int) $form_state->getValue('household_id'),
        (string) $form_state->getValue('person_name'),
      );
    }
    catch (InvalidArgumentException | RuntimeException) {
      $form_state->setErrorByName(
        'household_id',
        $this->t('Select an existing Household and enter a valid Person name.'),
      );
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->addHouseholdMember->addNewMember(
        (int) $form_state->getValue('household_id'),
        (string) $form_state->getValue('person_name'),
      );
    }
    catch (InvalidArgumentException | RuntimeException) {
      $this->messenger()->addError($this->t('The Household member could not be added safely.'));
      $form_state->setRedirect('personal_secretary.add_household_member');
      return;
    }

    $form_state->setRedirect('personal_secretary.upcoming');
  }

}
