<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Service\DomainMutationService;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Changes only the current-default plain-text activity location.
 */
final class EditActivityLocationForm extends FormBase {

  private const SNAPSHOT_COLLECTION = 'personal_secretary.location_edit';

  public function __construct(
    private readonly DomainMutationService $locationMutations,
    private readonly EntityTypeManagerInterface $locationEntityTypeManager,
    private readonly RouteMatchInterface $locationRouteMatch,
    private readonly RequestStack $locationRequestStack,
    private readonly PrivateTempStoreFactory $locationSnapshotFactory,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.domain_mutation'),
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
      $container->get('request_stack'),
      $container->get('tempstore.private'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_edit_activity_location';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $series = $this->currentSeries();
    // Drupal forbids FormState caching on GET. Store the original rendered
    // snapshot in core's expiring, user-private tempstore instead. Only a
    // random opaque lookup key is submitted; the location stays server-side.
    $snapshotId = '';
    if ($this->locationRequestStack->getCurrentRequest()?->isMethod('GET')) {
      $snapshotId = bin2hex(random_bytes(16));
      $this->locationSnapshotFactory->get(self::SNAPSHOT_COLLECTION)->set($snapshotId, [
        'series_id' => (int) $series->id(),
        'revision_id' => (string) $series->getRevisionId(),
        'location' => (string) ($series->get('location')->value ?? ''),
      ]);
    }
    $form['snapshot_id'] = [
      '#type' => 'hidden',
      '#default_value' => $snapshotId,
    ];
    $form['#action'] = Url::fromRoute(
      'personal_secretary.edit_activity_location',
      ['series' => (int) $series->id()],
      $this->formUrlOptions(),
    )->toString();
    $form['activity'] = [
      '#type' => 'item',
      '#title' => $this->t('Activity'),
      '#markup' => Html::escape((string) $series->label()),
    ];
    $form['location'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Location'),
      '#description' => $this->t('Leave empty to clear the location for all occurrences. This does not change the schedule.'),
      '#maxlength' => 255,
      '#required' => FALSE,
      '#default_value' => (string) ($series->get('location')->value ?? ''),
    ];
    $form['revision_id'] = [
      '#type' => 'hidden',
      '#default_value' => (string) $series->getRevisionId(),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save location'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute($this->returnRoute(), [], $this->returnOptions()),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $location = $form_state->getValue('location');
    if (!is_string($location) || mb_strlen(trim($location)) > 255) {
      $form_state->setErrorByName('location', $this->t('Location must not exceed 255 characters.'));
      return;
    }
    $original = $this->originalSnapshot($form_state);
    $persisted = $this->currentSeries();
    if (
      $original === NULL
      || $original['series_id'] !== (int) $persisted->id()
      || (string) $form_state->getValue('revision_id') !== $original['revision_id']
      || (string) $persisted->getRevisionId() !== $original['revision_id']
      || (string) ($persisted->get('location')->value ?? '') !== $original['location']
    ) {
      $form_state->setErrorByName('location', $this->t('The activity changed while this form was open. Reload before saving.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $original = $this->originalSnapshot($form_state);
      if ($original === NULL) {
        throw new InvalidArgumentException('Original location form snapshot is unavailable.');
      }
      $series = $this->currentSeries();
      if (
        $original['series_id'] !== (int) $series->id()
        || (string) $series->getRevisionId() !== $original['revision_id']
      ) {
        throw new InvalidArgumentException('ActivitySeries revision changed since form render.');
      }
      // The mutation service compares this trusted GET location to persisted
      // state again, catching even changes between validate and submit.
      $series->set('location', $original['location']);
      $this->locationMutations->updateActivitySeriesLocation(
        $series,
        (string) $form_state->getValue('location'),
      );
    }
    catch (InvalidArgumentException | RuntimeException) {
      $this->messenger()->addError($this->t('This activity location can no longer be updated safely.'));
      $form_state->setRebuild(TRUE);
      return;
    }
    $this->messenger()->addStatus($this->t('Activity location updated.'));
    $form_state->setRedirect($this->returnRoute(), [], $this->returnOptions());
  }

  /**
   * Returns only server-owned initial form data; invalid/expired keys fail closed.
   *
   * @return array{series_id: int, revision_id: string, location: string}|null
   */
  private function originalSnapshot(FormStateInterface $form_state): ?array {
    $key = $form_state->getValue('snapshot_id');
    if (!is_string($key) || preg_match('/^[0-9a-f]{32}$/D', $key) !== 1) {
      return NULL;
    }
    $snapshot = $this->locationSnapshotFactory->get(self::SNAPSHOT_COLLECTION)->get($key);
    if (
      !is_array($snapshot)
      || !isset($snapshot['series_id'], $snapshot['revision_id'], $snapshot['location'])
      || !is_int($snapshot['series_id'])
      || !is_string($snapshot['revision_id'])
      || !is_string($snapshot['location'])
    ) {
      return NULL;
    }
    return $snapshot;
  }

  private function currentSeries(): ActivitySeries {
    $id = $this->locationRouteMatch->getParameter('series');
    if (!is_string($id) || !ctype_digit($id) || (int) $id <= 0) {
      throw new NotFoundHttpException('This activity is unavailable.');
    }
    $storage = $this->locationEntityTypeManager->getStorage('personal_sec_activity_series');
    $storage->resetCache([(int) $id]);
    $series = $storage->load((int) $id);
    if (!$series instanceof ActivitySeries) {
      throw new NotFoundHttpException('This activity is unavailable.');
    }
    return $series;
  }

  private function fromMyUpcoming(): bool {
    return ($this->locationRequestStack->getCurrentRequest()?->query->all()['from'] ?? NULL) === 'mine';
  }

  private function returnRoute(): string {
    return $this->fromMyUpcoming()
      ? 'personal_secretary.my_upcoming'
      : 'personal_secretary.upcoming';
  }

  /**
   * Only the pre-existing bounded My upcoming filters can survive navigation.
   *
   * @return array<string, string>
   */
  private function returnQuery(): array {
    if (!$this->fromMyUpcoming()) {
      return [];
    }
    $input = $this->locationRequestStack->getCurrentRequest()?->query->all() ?? [];
    $query = [];
    if (($input['days'] ?? NULL) === '30') {
      $query['days'] = '30';
    }
    $search = $input['q'] ?? NULL;
    if (is_string($search)) {
      $search = trim($search);
      if ($search !== '' && mb_strlen($search) <= 100) {
        $query['q'] = $search;
      }
    }
    if (($input['focus'] ?? NULL) === 'tomorrow') {
      $query['focus'] = 'tomorrow';
    }
    return $query;
  }

  /**
   * @return array<string, mixed>
   */
  private function returnOptions(): array {
    $query = $this->returnQuery();
    return $query === [] ? [] : ['query' => $query];
  }

  /**
   * @return array<string, mixed>
   */
  private function formUrlOptions(): array {
    if (!$this->fromMyUpcoming()) {
      return [];
    }
    return ['query' => ['from' => 'mine'] + $this->returnQuery()];
  }

}
