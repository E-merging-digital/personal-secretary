<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\personal_secretary\Entity\Household;
use Drupal\personal_secretary\Service\DomainMutationService;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Renames an existing Household without changing its domain references.
 */
final class RenameHouseholdForm extends FormBase {

  private const SNAPSHOT_COLLECTION = 'personal_secretary.household_name_edit';

  public function __construct(
    private readonly DomainMutationService $mutations,
    private readonly EntityTypeManagerInterface $entities,
    private readonly PrivateTempStoreFactory $snapshots,
    private readonly RequestStack $requests,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.domain_mutation'),
      $container->get('entity_type.manager'),
      $container->get('tempstore.private'),
      $container->get('request_stack'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_rename_household';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $storage = $this->entities->getStorage('personal_secretary_household');
    $storage->resetCache();
    $households = $storage->loadMultiple();
    if ($households === []) {
      $form['empty_context'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No Household is available to rename.'),
      ];
      $form['next_step'] = [
        '#type' => 'link',
        '#title' => $this->t('Create first activity'),
        '#url' => Url::fromRoute('personal_secretary.setup'),
      ];
      return $form;
    }

    $options = [];
    $original = [];
    foreach ($households as $household) {
      if (!$household instanceof Household) {
        continue;
      }
      $id = (int) $household->id();
      $options[$id] = (string) $household->label();
      $original[$id] = [
        'id' => $id,
        'uuid' => (string) $household->uuid(),
        'name' => (string) $household->get('name')->value,
        'members' => $household->get('members')->getValue(),
      ];
    }
    if ($options === []) {
      return $form;
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);

    $key = '';
    if ($this->requests->getCurrentRequest()?->isMethod('GET')) {
      $key = bin2hex(random_bytes(16));
      $this->snapshots->get(self::SNAPSHOT_COLLECTION)->set($key, $original);
    }
    $form['snapshot_id'] = ['#type' => 'hidden', '#default_value' => $key];

    if (count($options) === 1) {
      $id = (int) array_key_first($options);
      $form['household_context'] = [
        '#type' => 'item',
        '#title' => $this->t('Household'),
        '#markup' => $this->t('@household', ['@household' => (string) reset($options)]),
      ];
      $form['household_id'] = ['#type' => 'value', '#value' => (string) $id];
    }
    else {
      $form['household_id'] = [
        '#type' => 'select',
        '#title' => $this->t('Household'),
        '#options' => $options,
        '#required' => TRUE,
      ];
    }

    $form['new_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('New name'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save household name'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('personal_secretary.upcoming'),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $value = $form_state->getValue('new_name');
    if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 255) {
      $form_state->setErrorByName('new_name', $this->t('Household name must contain between 1 and 255 characters.'));
      return;
    }

    $original = $this->originalSnapshot($form_state);
    $id = $this->selectedId($form_state);
    if ($original === NULL || $id === NULL) {
      $form_state->setErrorByName('household_id', $this->t('The Household changed while this form was open. Reload before saving.'));
      return;
    }
    $storage = $this->entities->getStorage('personal_secretary_household');
    $storage->resetCache([$id]);
    $current = $storage->load($id);
    if (
      !$current instanceof Household
      || $current->uuid() !== $original['uuid']
      || (string) $current->get('name')->value !== $original['name']
      || $current->get('members')->getValue() !== $original['members']
      || !$current->access('update')
    ) {
      $form_state->setErrorByName('household_id', $this->t('The Household changed while this form was open. Reload before saving.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $original = $this->originalSnapshot($form_state);
      $id = $this->selectedId($form_state);
      if ($original === NULL || $id === NULL) {
        throw new InvalidArgumentException('Trusted Household snapshot is unavailable.');
      }
      $unchanged = trim((string) $form_state->getValue('new_name')) === $original['name'];
      $this->mutations->renameHousehold($id, (string) $form_state->getValue('new_name'), $original);
      $this->snapshots->get(self::SNAPSHOT_COLLECTION)->delete((string) $form_state->getValue('snapshot_id'));
    }
    catch (InvalidArgumentException | RuntimeException) {
      $this->messenger()->addError($this->t('This Household name can no longer be updated safely.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    $this->messenger()->addStatus($unchanged ? $this->t('Household name is unchanged.') : $this->t('Household name updated.'));
    $form_state->setRedirect('personal_secretary.upcoming');
  }

  private function selectedId(FormStateInterface $form_state): ?int {
    $value = $form_state->getValue('household_id');
    if (!is_string($value) || !ctype_digit($value) || (int) $value <= 0) {
      return NULL;
    }
    return (int) $value;
  }

  /** @return array{id:int, uuid:string, name:string, members:array}|null */
  private function originalSnapshot(FormStateInterface $form_state): ?array {
    $key = $form_state->getValue('snapshot_id');
    $id = $this->selectedId($form_state);
    if (!is_string($key) || preg_match('/^[0-9a-f]{32}$/D', $key) !== 1 || $id === NULL) {
      return NULL;
    }
    $all = $this->snapshots->get(self::SNAPSHOT_COLLECTION)->get($key);
    if (!is_array($all) || !isset($all[$id]) || !is_array($all[$id])) {
      return NULL;
    }
    $original = $all[$id];
    if (
      !isset($original['id'], $original['uuid'], $original['name'], $original['members'])
      || !is_int($original['id'])
      || $original['id'] !== $id
      || !is_string($original['uuid'])
      || !is_string($original['name'])
      || !is_array($original['members'])
    ) {
      return NULL;
    }
    return $original;
  }

}
