<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\PreparationRequirement;
use Drupal\personal_secretary\Service\PreparationRequirementMutationService;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirms retirement of one existing activity preparation without deleting it.
 */
final class RetireActivityPreparationForm extends ConfirmFormBase {

  private const SNAPSHOT_COLLECTION = 'personal_secretary.retire_activity_preparation';

  public function __construct(
    private readonly PreparationRequirementMutationService $mutations,
    private readonly EntityTypeManagerInterface $entities,
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requests,
    private readonly PrivateTempStoreFactory $snapshots,
    private readonly TimeInterface $time,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.preparation_requirement_mutation'),
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
      $container->get('request_stack'),
      $container->get('tempstore.private'),
      $container->get('datetime.time'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_retire_activity_preparation';
  }

  public function getQuestion() {
    return $this->t('Retire preparation: @instruction?', [
      '@instruction' => (string) $this->currentRequirement()->get('label')->value,
    ]);
  }

  public function getDescription() {
    return $this->t('This stops the preparation for future activity occurrences. Its history is kept.');
  }

  public function getConfirmText() {
    return $this->t('Retire preparation');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('personal_secretary.add_activity_preparation', [
      'series' => (int) $this->currentSeries()->id(),
    ], $this->urlOptions());
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $series = $this->currentSeries();
    $requirement = $this->currentRequirement();
    $key = '';
    if ($this->requests->getCurrentRequest()?->isMethod('GET')) {
      if (!$this->isRetirable($requirement)) {
        throw new NotFoundHttpException('This preparation cannot be retired.');
      }
      $key = bin2hex(random_bytes(16));
      $this->snapshots->get(self::SNAPSHOT_COLLECTION)->set($key, [
        'series_id' => (int) $series->id(),
        'series_revision' => (string) $series->getRevisionId(),
        'requirement_id' => (int) $requirement->id(),
        'requirement_revision' => (string) $requirement->getRevisionId(),
      ]);
    }
    $form = parent::buildForm($form, $form_state);
    $form['#action'] = Url::fromRoute('personal_secretary.retire_activity_preparation', [
      'series' => (int) $series->id(),
      'preparation_requirement' => (int) $requirement->id(),
    ], $this->urlOptions())->toString();
    $form['snapshot_id'] = ['#type' => 'hidden', '#default_value' => $key];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->unchanged($this->originalSnapshot($form_state))) {
      $form_state->setErrorByName('snapshot_id', $this->t('The preparation changed while this confirmation was open. Reload before trying again.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $snapshot = $this->originalSnapshot($form_state);
      if (!$this->unchanged($snapshot)) {
        throw new InvalidArgumentException('Preparation retirement confirmation is stale.');
      }
      $requirement = $this->currentRequirement();
      $key = (string) $form_state->getValue('snapshot_id');
      // Consume the trusted confirmation before the single lifecycle mutation.
      $this->snapshots->get(self::SNAPSHOT_COLLECTION)->delete($key);
      $effectiveFrom = new DateTimeImmutable(
        (string) $requirement->get('effective_from')->value,
        new DateTimeZone('UTC'),
      );
      $cutoff = new DateTimeImmutable('@' . max(
        $this->time->getCurrentTime(),
        $effectiveFrom->getTimestamp() + 1,
      ));
      $this->mutations->retirePreparationRequirement($requirement, $cutoff);
    }
    catch (InvalidArgumentException | RuntimeException) {
      $this->messenger()->addError($this->t('This preparation could not be retired safely. Reload before trying again.'));
      $form_state->setRebuild(TRUE);
      return;
    }
    $this->messenger()->addStatus($this->t('Preparation retired for future activity occurrences.'));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * @return array{series_id:int,series_revision:string,requirement_id:int,requirement_revision:string}|null
   */
  private function originalSnapshot(FormStateInterface $form_state): ?array {
    $key = $form_state->getValue('snapshot_id');
    if (!is_string($key) || preg_match('/^[0-9a-f]{32}$/D', $key) !== 1) {
      return NULL;
    }
    $data = $this->snapshots->get(self::SNAPSHOT_COLLECTION)->get($key);
    if (
      !is_array($data)
      || !isset($data['series_id'], $data['series_revision'], $data['requirement_id'], $data['requirement_revision'])
      || !is_int($data['series_id'])
      || !is_string($data['series_revision'])
      || !is_int($data['requirement_id'])
      || !is_string($data['requirement_revision'])
    ) {
      return NULL;
    }
    return $data;
  }

  /**
   * @param array{series_id:int,series_revision:string,requirement_id:int,requirement_revision:string}|null $snapshot
   */
  private function unchanged(?array $snapshot): bool {
    if ($snapshot === NULL) {
      return FALSE;
    }
    $series = $this->currentSeries();
    $requirement = $this->currentRequirement();
    $seriesStorage = $this->entities->getStorage('personal_sec_activity_series');
    $requirementStorage = $this->entities->getStorage('personal_sec_prep_req');
    return $snapshot['series_id'] === (int) $series->id()
      && $snapshot['series_revision'] === (string) $series->getRevisionId()
      && $snapshot['series_revision'] === (string) $seriesStorage->getLatestRevisionId($series->id())
      && $snapshot['requirement_id'] === (int) $requirement->id()
      && $snapshot['requirement_revision'] === (string) $requirement->getRevisionId()
      && $snapshot['requirement_revision'] === (string) $requirementStorage->getLatestRevisionId($requirement->id())
      && $series->access('update')
      && $requirement->access('update')
      && $this->isRetirable($requirement);
  }

  private function isRetirable(PreparationRequirement $requirement): bool {
    return $requirement->get('effective_until')->isEmpty()
      && (string) $requirement->get('effective_from')->value <= gmdate('Y-m-d\TH:i:s', $this->time->getCurrentTime());
  }

  private function currentSeries(): ActivitySeries {
    $id = $this->routeMatch->getParameter('series');
    if (!is_string($id) || !ctype_digit($id) || (int) $id <= 0) {
      throw new NotFoundHttpException('This activity is unavailable.');
    }
    $storage = $this->entities->getStorage('personal_sec_activity_series');
    $storage->resetCache([(int) $id]);
    $series = $storage->load((int) $id);
    if (!$series instanceof ActivitySeries) {
      throw new NotFoundHttpException('This activity is unavailable.');
    }
    return $series;
  }

  private function currentRequirement(): PreparationRequirement {
    $id = $this->routeMatch->getParameter('preparation_requirement');
    if (!is_string($id) || !ctype_digit($id) || (int) $id <= 0) {
      throw new NotFoundHttpException('This preparation is unavailable.');
    }
    $storage = $this->entities->getStorage('personal_sec_prep_req');
    $storage->resetCache([(int) $id]);
    $requirement = $storage->load((int) $id);
    if (
      !$requirement instanceof PreparationRequirement
      || (int) $requirement->get('series')->target_id !== (int) $this->currentSeries()->id()
    ) {
      throw new NotFoundHttpException('This preparation is unavailable.');
    }
    return $requirement;
  }

  /** @return array<string, mixed> */
  private function urlOptions(): array {
    $input = $this->requests->getCurrentRequest()?->query->all() ?? [];
    if (($input['from'] ?? NULL) !== 'mine') {
      return [];
    }
    $query = ['from' => 'mine'];
    if (($input['days'] ?? NULL) === '30') {
      $query['days'] = '30';
    }
    if (is_string($input['q'] ?? NULL)) {
      $q = trim($input['q']);
      if ($q !== '' && mb_strlen($q) <= 100) {
        $query['q'] = $q;
      }
    }
    if (in_array($input['focus'] ?? NULL, ['today', 'tomorrow'], TRUE)) {
      $query['focus'] = $input['focus'];
    }
    return ['query' => $query];
  }

}
