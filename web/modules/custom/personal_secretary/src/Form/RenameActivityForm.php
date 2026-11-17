<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

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
 * Corrects the current activity title without a semantic series revision.
 */
final class RenameActivityForm extends FormBase {

  private const SNAPSHOT_COLLECTION = 'personal_secretary.name_edit';

  public function __construct(
    private readonly DomainMutationService $mutations,
    private readonly EntityTypeManagerInterface $entities,
    private readonly RouteMatchInterface $nameRouteMatch,
    private readonly RequestStack $requests,
    private readonly PrivateTempStoreFactory $snapshots,
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
    return 'personal_secretary_rename_activity';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $series = $this->currentSeries();
    $key = '';
    if ($this->requests->getCurrentRequest()?->isMethod('GET')) {
      $key = bin2hex(random_bytes(16));
      $this->snapshots->get(self::SNAPSHOT_COLLECTION)->set($key, [
        'series_id' => (int) $series->id(),
        'uuid' => (string) $series->uuid(),
        'revision_id' => (string) $series->getRevisionId(),
        'name' => (string) $series->get('name')->value,
      ]);
    }
    $form['snapshot_id'] = ['#type' => 'hidden', '#default_value' => $key];
    $form['#action'] = Url::fromRoute(
      'personal_secretary.rename_activity',
      ['series' => (int) $series->id()],
      $this->formUrlOptions(),
    )->toString();
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Activity name'),
      '#maxlength' => 255,
      '#required' => TRUE,
      '#default_value' => (string) $series->get('name')->value,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save name'),
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
    $value = $form_state->getValue('name');
    if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 255) {
      $form_state->setErrorByName('name', $this->t('Activity name must contain between 1 and 255 characters.'));
      return;
    }
    $original = $this->originalSnapshot($form_state);
    $series = $this->currentSeries();
    $storage = $this->entities->getStorage('personal_sec_activity_series');
    if (
      $original === NULL
      || $original['series_id'] !== (int) $series->id()
      || $original['uuid'] !== $series->uuid()
      || $original['revision_id'] !== (string) $series->getRevisionId()
      || $original['revision_id'] !== (string) $storage->getLatestRevisionId($series->id())
      || $original['name'] !== (string) $series->get('name')->value
      || !$series->access('update')
    ) {
      $form_state->setErrorByName('name', $this->t('The activity changed while this form was open. Reload before saving.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $snapshot = $this->originalSnapshot($form_state);
      if ($snapshot === NULL) {
        throw new InvalidArgumentException('Trusted activity name snapshot is unavailable.');
      }
      $series = $this->currentSeries();
      if (
        (int) $series->id() !== $snapshot['series_id']
        || $series->uuid() !== $snapshot['uuid']
        || (string) $series->getRevisionId() !== $snapshot['revision_id']
        || !$series->access('update')
      ) {
        throw new InvalidArgumentException('Activity series changed since name edit opened.');
      }
      $unchanged = trim((string) $form_state->getValue('name')) === $snapshot['name'];
      $this->mutations->renameActivitySeries($series, (string) $form_state->getValue('name'), $snapshot['name']);
    }
    catch (InvalidArgumentException | RuntimeException) {
      $this->messenger()->addError($this->t('This activity name can no longer be updated safely.'));
      $form_state->setRebuild(TRUE);
      return;
    }
    $this->messenger()->addStatus($unchanged ? $this->t('Activity name is unchanged.') : $this->t('Activity name updated.'));
    $form_state->setRedirect($this->returnRoute(), [], $this->returnOptions());
  }

  /**
   * @return array{series_id:int, uuid:string, revision_id:string, name:string}|null
   */
  private function originalSnapshot(FormStateInterface $form_state): ?array {
    $key = $form_state->getValue('snapshot_id');
    if (!is_string($key) || preg_match('/^[0-9a-f]{32}$/D', $key) !== 1) {
      return NULL;
    }
    $data = $this->snapshots->get(self::SNAPSHOT_COLLECTION)->get($key);
    if (
      !is_array($data)
      || !isset($data['series_id'], $data['uuid'], $data['revision_id'], $data['name'])
      || !is_int($data['series_id'])
      || !is_string($data['uuid'])
      || !is_string($data['revision_id'])
      || !is_string($data['name'])
    ) {
      return NULL;
    }
    return $data;
  }

  private function currentSeries(): ActivitySeries {
    $id = $this->nameRouteMatch->getParameter('series');
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

  private function fromMyUpcoming(): bool {
    return ($this->requests->getCurrentRequest()?->query->all()['from'] ?? NULL) === 'mine';
  }

  private function returnRoute(): string {
    return $this->fromMyUpcoming() ? 'personal_secretary.my_upcoming' : 'personal_secretary.upcoming';
  }

  /** @return array<string, string> */
  private function returnQuery(): array {
    if (!$this->fromMyUpcoming()) {
      return [];
    }
    $input = $this->requests->getCurrentRequest()?->query->all() ?? [];
    $query = [];
    if (($input['days'] ?? NULL) === '30') {
      $query['days'] = '30';
    }
    if (is_string($input['q'] ?? NULL)) {
      $search = trim($input['q']);
      if ($search !== '' && mb_strlen($search) <= 100) {
        $query['q'] = $search;
      }
    }
    if (in_array($input['focus'] ?? NULL, ['today', 'tomorrow'], TRUE)) {
      $query['focus'] = $input['focus'];
    }
    return $query;
  }

  /** @return array<string, mixed> */
  private function returnOptions(): array {
    $query = $this->returnQuery();
    return $query === [] ? [] : ['query' => $query];
  }

  /** @return array<string, mixed> */
  private function formUrlOptions(): array {
    return $this->fromMyUpcoming() ? ['query' => ['from' => 'mine'] + $this->returnQuery()] : [];
  }

}
