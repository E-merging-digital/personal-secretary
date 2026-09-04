<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\personal_secretary\Service\RenameHouseholdMemberService;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renames one existing Person currently referenced by a Household.
 */
final class RenameHouseholdMemberForm extends FormBase {

  public function __construct(
    private readonly RenameHouseholdMemberService $renameHouseholdMember,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.rename_household_member'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_rename_household_member';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $people = $this->renameHouseholdMember->personOptions();
    if ($people === []) {
      $form['empty_context'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No Household member is available to rename.'),
      ];
      return $form;
    }

    $form['person_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Household member'),
      '#options' => $people,
      '#required' => TRUE,
    ];
    $form['new_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('New name'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Rename household member'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->renameHouseholdMember->prepare(
        (int) $form_state->getValue('person_id'),
        (string) $form_state->getValue('new_name'),
      );
    }
    catch (InvalidArgumentException | RuntimeException) {
      $form_state->setErrorByName(
        'new_name',
        $this->t('Select a current Household member and enter a valid name.'),
      );
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->renameHouseholdMember->renameMember(
        (int) $form_state->getValue('person_id'),
        (string) $form_state->getValue('new_name'),
      );
    }
    catch (InvalidArgumentException | RuntimeException) {
      $this->messenger()->addError($this->t('The Household member could not be renamed safely.'));
      $form_state->setRedirect('personal_secretary.rename_household_member');
      return;
    }

    $form_state->setRedirect('personal_secretary.upcoming');
  }

}
