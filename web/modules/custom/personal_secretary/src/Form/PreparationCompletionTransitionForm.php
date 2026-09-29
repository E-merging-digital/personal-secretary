<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\personal_secretary\Service\PreparationCompletionService;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Performs one reversible preparation completion transition via Form API POST.
 */
final class PreparationCompletionTransitionForm extends FormBase {

  public function __construct(
    private readonly PreparationCompletionService $completionService,
    private readonly RouteMatchInterface $currentRouteMatch,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.preparation_completion'),
      $container->get('current_route_match'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_preparation_completion_transition';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?string $explicitAction = NULL,
    ?int $explicitSeriesId = NULL,
    ?string $explicitOriginalOccurrenceKey = NULL,
    ?int $explicitRequirementId = NULL,
    ?string $explicitReturnSurface = NULL,
  ): array {
    $action = $explicitAction ?? $this->action();
    $seriesId = $explicitSeriesId ?? $this->seriesId();
    $originalOccurrenceKey = $explicitOriginalOccurrenceKey ?? $this->originalOccurrenceKey();
    $requirementId = $explicitRequirementId ?? $this->requirementId();
    $returnSurface = $explicitReturnSurface ?? $this->returnSurface();

    $route = $this->routeForAction($action);
    $this->returnRouteName($returnSurface);

    if ($seriesId <= 0 || $requirementId <= 0 || trim($originalOccurrenceKey) === '') {
      throw new NotFoundHttpException('The requested preparation is unavailable.');
    }

    try {
      $this->completionService->describeCurrentPreparation(
        $seriesId,
        $originalOccurrenceKey,
        $requirementId,
      );
    }
    catch (InvalidArgumentException|RuntimeException $exception) {
      throw new NotFoundHttpException('The requested preparation is unavailable.', $exception);
    }

    $form['#action'] = Url::fromRoute($route, [
      'series' => $seriesId,
      'original_occurrence_key' => $originalOccurrenceKey,
      'preparation_requirement' => $requirementId,
      'return_surface' => $returnSurface,
    ])->toString();

    $form['#attributes']['class'][] = 'ps-preparation-action-form';
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => match ($action) {
        'prepared' => $this->t('Mark prepared'),
        'not_prepared' => $this->t('Mark not prepared'),
        default => throw new NotFoundHttpException('Unknown preparation completion action.'),
      },
      '#button_type' => 'primary',
      '#attributes' => [
        'class' => ['ps-preparation-action-form__submit'],
      ],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      if ($this->action() === 'prepared') {
        $created = $this->completionService->markPrepared(
          $this->seriesId(),
          $this->originalOccurrenceKey(),
          $this->requirementId(),
        );
        $this->messenger()->addStatus($created
          ? $this->t('Preparation marked prepared.')
          : $this->t('Preparation was already marked prepared.'));
        $form_state->setRedirect($this->returnRoute());
        return;
      }

      if ($this->action() === 'not_prepared') {
        $deleted = $this->completionService->markNotPrepared(
          $this->seriesId(),
          $this->originalOccurrenceKey(),
          $this->requirementId(),
        );
        $this->messenger()->addStatus($deleted
          ? $this->t('Preparation marked not prepared.')
          : $this->t('Preparation was already not prepared.'));
        $form_state->setRedirect($this->returnRoute());
        return;
      }
    }
    catch (InvalidArgumentException|RuntimeException) {
      $this->messenger()->addError($this->t('This preparation action is no longer authorized or valid.'));
      $form_state->setRedirect($this->returnRoute());
      return;
    }

    throw new NotFoundHttpException('Unknown preparation completion action.');
  }

  private function action(): string {
    return (string) ($this->currentRouteMatch->getRouteObject()?->getDefault('_preparation_completion_action') ?? '');
  }

  /**
   * Resolves the mutation route for a completion action.
   *
   * @param string $action
   *   Completion action identifier.
   *
   * @return string
   *   Route name for the requested action.
   */
  private function routeForAction(string $action): string {
    return match ($action) {
      'prepared' => 'personal_secretary.mark_preparation_prepared',
      'not_prepared' => 'personal_secretary.mark_preparation_not_prepared',
      default => throw new NotFoundHttpException('Unknown preparation completion action.'),
    };
  }

  private function seriesId(): int {
    return (int) $this->currentRouteMatch->getParameter('series');
  }

  private function originalOccurrenceKey(): string {
    return (string) $this->currentRouteMatch->getParameter('original_occurrence_key');
  }

  private function requirementId(): int {
    return (int) $this->currentRouteMatch->getParameter('preparation_requirement');
  }

  /**
   * Reads the requested product return surface.
   *
   * @return string
   *   Product return surface identifier.
   */
  private function returnSurface(): string {
    return (string) $this->currentRouteMatch->getParameter('return_surface');
  }

  /**
   * Resolves the return route for a product surface.
   *
   * @param string $returnSurface
   *   Product return surface identifier.
   *
   * @return string
   *   Route name for the requested product surface.
   */
  private function returnRouteName(string $returnSurface): string {
    return match ($returnSurface) {
      'mine' => 'personal_secretary.my_preparations',
      'today' => 'personal_secretary.today',
      default => throw new NotFoundHttpException('Unknown preparation return surface.'),
    };
  }

  /**
   * Resolves the current request return route.
   *
   * @return string
   *   Route name for the current return surface.
   */
  private function returnRoute(): string {
    return $this->returnRouteName($this->returnSurface());
  }

}
