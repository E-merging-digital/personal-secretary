<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\personal_secretary\Service\LinkCurrentUserToPersonService;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Links the current Drupal account to one current Household member Person.
 */
final class LinkCurrentUserToPersonForm extends FormBase {

  public function __construct(
    private readonly LinkCurrentUserToPersonService $linkCurrentUserToPerson,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.link_current_user_to_person'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_link_current_user_to_person';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $people = $this->linkCurrentUserToPerson->personOptions();
    if ($people === []) {
      $form['empty_context'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No Household member is available to link.'),
      ];
      return $form;
    }

    $form['person_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Household member'),
      '#options' => $people,
      '#required' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Link my account to household member'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->linkCurrentUserToPerson->prepare(
        (int) $form_state->getValue('person_id'),
      );
    }
    catch (InvalidArgumentException | RuntimeException) {
      $form_state->setErrorByName(
        'person_id',
        $this->t('Select a current Household member that can be linked safely.'),
      );
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->linkCurrentUserToPerson->link(
        (int) $form_state->getValue('person_id'),
      );
    }
    catch (InvalidArgumentException | RuntimeException) {
      $this->messenger()->addError($this->t('The account could not be linked safely.'));
      $form_state->setRedirect('personal_secretary.link_current_user_to_person');
      return;
    }

    $form_state->setRedirect('personal_secretary.upcoming');
  }

}
