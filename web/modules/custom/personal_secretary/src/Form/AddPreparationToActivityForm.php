<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use DateTimeImmutable;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Service\PreparationRequirementMutationService;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Adds one preparation to an unchanged existing activity.
 */
final class AddPreparationToActivityForm extends FormBase {

  private const SNAPSHOT_COLLECTION = 'personal_secretary.add_activity_preparation';

  // Drupal Core integer fields use signed 32-bit storage for lead_time_seconds.
  private const MAX_LEAD_MINUTES = 35791394;

  public function __construct(
    private readonly PreparationRequirementMutationService $preparations,
    private readonly EntityTypeManagerInterface $entities,
    private readonly RouteMatchInterface $activityRouteMatch,
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
    return 'personal_secretary_add_preparation_to_activity';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $series = $this->currentSeries();
    $token = '';
    if ($this->requests->getCurrentRequest()?->isMethod('GET')) {
      $token = bin2hex(random_bytes(16));
      $this->snapshots->get(self::SNAPSHOT_COLLECTION)->set($token, [
        'series_id' => (int) $series->id(),
        'uuid' => (string) $series->uuid(),
        'revision_id' => (string) $series->getRevisionId(),
        'household_id' => (int) $series->get('household')->target_id,
        'name' => (string) $series->get('name')->value,
      ]);
    }
    $form['snapshot_id'] = ['#type' => 'hidden', '#default_value' => $token];
    $form['#action'] = Url::fromRoute(
      'personal_secretary.add_activity_preparation',
      ['series' => (int) $series->id()],
      $this->formUrlOptions(),
    )->toString();
    $form['activity'] = [
      '#type' => 'item',
      '#title' => $this->t('Activity'),
      '#markup' => $this->t('@activity', ['@activity' => (string) $series->label()]),
    ];
    // Show active and historical requirements of this same activity.
    $preparationLists = $this->preparationInstructions($series);
    $activePreparations = $preparationLists['active'];
    $form['active_preparations'] = $activePreparations === []
      ? [
        '#type' => 'item',
        '#title' => $this->t('Active preparations'),
        '#markup' => $this->t('No active preparations for this activity.'),
      ]
      : [
        '#theme' => 'item_list',
        '#title' => $this->t('Active preparations'),
        '#items' => $activePreparations,
      ];
    if ($preparationLists['past'] !== []) {
      $form['past_preparations'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Past preparations'),
        '#items' => $preparationLists['past'],
      ];
    }
    $form['preparation_instruction'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Preparation instruction'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['preparation_lead_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('How many minutes before the activity?'),
      '#default_value' => 0,
      '#min' => 0,
      '#max' => self::MAX_LEAD_MINUTES,
      '#step' => 1,
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add preparation'),
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
    $instruction = $form_state->getValue('preparation_instruction');
    if (!is_string($instruction) || trim($instruction) === '' || mb_strlen(trim($instruction)) > 255) {
      $form_state->setErrorByName('preparation_instruction', $this->t('Enter a preparation instruction of 1 to 255 characters.'));
    }
    if ($this->leadMinutes($form_state) === NULL) {
      $form_state->setErrorByName('preparation_lead_minutes', $this->t('Enter a whole number of minutes between 0 and 35791394.'));
    }
    if (!$this->unchangedSeries($this->originalSnapshot($form_state))) {
      $form_state->setErrorByName('snapshot_id', $this->t('The activity changed while this form was open. Reload before saving.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $original = $this->originalSnapshot($form_state);
      $minutes = $this->leadMinutes($form_state);
      $instruction = $form_state->getValue('preparation_instruction');
      if (
        !$this->unchangedSeries($original)
        || $minutes === NULL
        || !is_string($instruction)
        || trim($instruction) === ''
        || mb_strlen(trim($instruction)) > 255
      ) {
        throw new InvalidArgumentException('The trusted activity preparation request is stale or invalid.');
      }
      $series = $this->currentSeries();
      $token = (string) $form_state->getValue('snapshot_id');
      // Consume before the one additive mutation: a repeated POST cannot create twice.
      $this->snapshots->get(self::SNAPSHOT_COLLECTION)->delete($token);
      $effectiveFrom = new DateTimeImmutable('@' . $this->time->getCurrentTime());
      $this->preparations->createPreparationRequirement(
        $series,
        trim($instruction),
        $minutes * 60,
        $effectiveFrom,
      );
    }
    catch (InvalidArgumentException | RuntimeException) {
      $this->messenger()->addError($this->t('This preparation could not be added safely. Reload before trying again.'));
      $form_state->setRebuild(TRUE);
      return;
    }
    $this->messenger()->addStatus($this->t('Preparation added to this activity.'));
    $form_state->setRedirect($this->returnRoute(), [], $this->returnOptions());
  }

  /**
   * Returns active and ended preparations for the current activity only.
   *
   * @return array{active: array, past: array}
   *   Drupal render items with escaped instruction labels.
   */
  private function preparationInstructions(ActivitySeries $series): array {
    $storage = $this->entities->getStorage('personal_sec_prep_req');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('series', (int) $series->id())
      ->execute();
    $requirements = $storage->loadMultiple($ids);
    ksort($requirements, SORT_NUMERIC);
    $now = gmdate('Y-m-d\\TH:i:s', $this->time->getCurrentTime());
    $active = [];
    $past = [];
    foreach ($requirements as $requirement) {
      $from = (string) $requirement->get('effective_from')->value;
      $until = (string) ($requirement->get('effective_until')->value ?? '');
      if ($from > $now) {
        continue;
      }
      $instruction = (string) $requirement->get('label')->value;
      $item = [
        '#type' => 'container',
        'instruction' => ['#markup' => $this->t('@instruction', ['@instruction' => $instruction])],
      ];
      if ($until !== '' && $until <= $now) {
        $past[] = $item;
        continue;
      }
      // Only unbounded current requirements may be retired.
      if ($until === '') {
        $item['retire'] = [
          '#type' => 'link',
          '#title' => $this->t('Retire'),
          '#url' => Url::fromRoute('personal_secretary.retire_activity_preparation', [
            'series' => (int) $series->id(),
            'preparation_requirement' => (int) $requirement->id(),
          ], $this->formUrlOptions()),
          '#prefix' => ' — ',
          '#attributes' => [
            'aria-label' => $this->t('Retire preparation: @instruction', ['@instruction' => $instruction]),
          ],
        ];
      }
      $active[] = $item;
    }
    return ['active' => $active, 'past' => $past];
  }

  private function leadMinutes(FormStateInterface $form_state): ?int {
    $value = filter_var(
      $form_state->getValue('preparation_lead_minutes'),
      FILTER_VALIDATE_INT,
      ['options' => ['min_range' => 0, 'max_range' => self::MAX_LEAD_MINUTES]],
    );
    return $value === FALSE ? NULL : $value;
  }

  /** @return array{series_id:int,uuid:string,revision_id:string,household_id:int,name:string}|null */
  private function originalSnapshot(FormStateInterface $form_state): ?array {
    $token = $form_state->getValue('snapshot_id');
    if (!is_string($token) || preg_match('/^[0-9a-f]{32}$/D', $token) !== 1) {
      return NULL;
    }
    $data = $this->snapshots->get(self::SNAPSHOT_COLLECTION)->get($token);
    if (
      !is_array($data)
      || !isset($data['series_id'], $data['uuid'], $data['revision_id'], $data['household_id'], $data['name'])
      || !is_int($data['series_id'])
      || !is_string($data['uuid'])
      || !is_string($data['revision_id'])
      || !is_int($data['household_id'])
      || !is_string($data['name'])
    ) {
      return NULL;
    }
    return $data;
  }

  /** @param array{series_id:int,uuid:string,revision_id:string,household_id:int,name:string}|null $original */
  private function unchangedSeries(?array $original): bool {
    if ($original === NULL) {
      return FALSE;
    }
    $series = $this->currentSeries();
    $storage = $this->entities->getStorage('personal_sec_activity_series');
    return $original['series_id'] === (int) $series->id()
      && $original['uuid'] === $series->uuid()
      && $original['revision_id'] === (string) $series->getRevisionId()
      && $original['revision_id'] === (string) $storage->getLatestRevisionId($series->id())
      && $original['household_id'] === (int) $series->get('household')->target_id
      && $original['name'] === (string) $series->get('name')->value
      && $series->access('update');
  }

  private function currentSeries(): ActivitySeries {
    $id = $this->activityRouteMatch->getParameter('series');
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
