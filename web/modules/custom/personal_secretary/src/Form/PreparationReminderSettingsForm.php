<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\personal_secretary\Service\PreparationReminderCandidateService;
use Drupal\user\UserInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class PreparationReminderSettingsForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'), $container->get('current_user'));
  }

  public function getFormId(): string {
    return 'personal_secretary_preparation_reminder_settings';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $user = $this->currentUserEntity();
    $form['preparation_reminders'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Email me when a preparation becomes due'),
      '#default_value' => (bool) $user->get(PreparationReminderCandidateService::OPT_IN_FIELD)->value,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save reminder settings'), '#button_type' => 'primary'];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $user = $this->currentUserEntity();
    $user->set(PreparationReminderCandidateService::OPT_IN_FIELD, $form_state->getValue('preparation_reminders') ? 1 : 0);
    $user->save();
    $this->messenger()->addStatus($this->t('Reminder settings saved.'));
  }

  private function currentUserEntity(): UserInterface {
    $user = $this->entityTypeManager->getStorage('user')->load((int) $this->currentUser->id());
    if (!$user instanceof UserInterface || !$user->isActive() || !$user->hasField(PreparationReminderCandidateService::OPT_IN_FIELD)) {
      throw new RuntimeException('Preparation reminder preference is unavailable for this account.');
    }
    return $user;
  }
}
