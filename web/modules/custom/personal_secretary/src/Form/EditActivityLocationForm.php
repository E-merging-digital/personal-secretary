<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
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

  public function __construct(
    private readonly DomainMutationService $mutations,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requestStack,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.domain_mutation'),
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
      $container->get('request_stack'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_edit_activity_location';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $series = $this->currentSeries();
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
    if ((string) $form_state->getValue('revision_id') !== (string) $this->currentSeries()->getRevisionId()) {
      $form_state->setErrorByName('location', $this->t('The activity changed while this form was open. Reload before saving.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->mutations->updateActivitySeriesLocation(
        $this->currentSeries(),
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

  private function currentSeries(): ActivitySeries {
    $id = $this->routeMatch->getParameter('series');
    if (!is_string($id) || !ctype_digit($id) || (int) $id <= 0) {
      throw new NotFoundHttpException('This activity is unavailable.');
    }
    $storage = $this->entityTypeManager->getStorage('personal_sec_activity_series');
    $series = $storage->load((int) $id);
    if (!$series instanceof ActivitySeries) {
      throw new NotFoundHttpException('This activity is unavailable.');
    }
    return $series;
  }

  private function fromMyUpcoming(): bool {
    return ($this->requestStack->getCurrentRequest()?->query->all()['from'] ?? NULL) === 'mine';
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
    $input = $this->requestStack->getCurrentRequest()?->query->all() ?? [];
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
