<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\personal_secretary\Entity\Household;
use Drupal\personal_secretary\Service\ManageHouseholdAuthorizationService;
use Drupal\user\UserInterface;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Administrative bootstrap for explicit User -> Household authorization.
 */
final class HouseholdAccessForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ManageHouseholdAuthorizationService $manageAuthorization,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('personal_secretary.manage_household_authorization'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_household_access';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['target_user'] = [
      '#type' => 'select',
      '#title' => $this->t('Drupal user'),
      '#options' => $this->userOptions(),
      '#required' => TRUE,
    ];

    $form['household_ids'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Authorized Households'),
      '#description' => $this->t('This complete selection replaces the target user’s current Household authorization set.'),
      '#options' => $this->householdOptions(),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Household access'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $selectedHouseholds = [];
    foreach ((array) $form_state->getValue('household_ids', []) as $householdId => $selected) {
      if ($selected !== 0 && $selected !== '0' && $selected !== NULL) {
        $selectedHouseholds[] = (int) $householdId;
      }
    }

    try {
      $changed = $this->manageAuthorization->replaceAuthorizationSet(
        (int) $form_state->getValue('target_user'),
        $selectedHouseholds,
      );
    }
    catch (InvalidArgumentException | AccessDeniedHttpException) {
      $this->messenger()->addError($this->t('Household access could not be updated safely.'));
      $form_state->setRedirect('personal_secretary.household_access_admin');
      return;
    }

    $this->messenger()->addStatus(
      $changed
        ? $this->t('Household access was updated.')
        : $this->t('Household access was already unchanged.'),
    );
    $form_state->setRedirect('personal_secretary.household_access_admin');
  }

  /**
   * @return array<int, string>
   */
  private function userOptions(): array {
    $storage = $this->entityTypeManager->getStorage('user');
    $ids = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', 0, '>')
      ->sort('uid')
      ->execute();

    $options = [];
    foreach ($storage->loadMultiple($ids) as $user) {
      if (!$user instanceof UserInterface || $user->id() === NULL) {
        continue;
      }
      $label = trim($user->getDisplayName());
      $options[(int) $user->id()] = (string) $this->t('@name (UID @uid)', [
        '@name' => $label !== '' ? $label : (string) $this->t('Unnamed user'),
        '@uid' => (string) $user->id(),
      ]);
    }

    return $options;
  }

  /**
   * @return array<int, string>
   */
  private function householdOptions(): array {
    $storage = $this->entityTypeManager->getStorage('personal_secretary_household');
    $ids = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->sort('id')
      ->execute();

    $options = [];
    foreach ($storage->loadMultiple($ids) as $household) {
      if (!$household instanceof Household || $household->id() === NULL) {
        continue;
      }
      $label = trim((string) $household->label());
      $options[(int) $household->id()] = (string) $this->t('@name (ID @id)', [
        '@name' => $label !== '' ? $label : (string) $this->t('Unnamed Household'),
        '@id' => (string) $household->id(),
      ]);
    }

    return $options;
  }

}
