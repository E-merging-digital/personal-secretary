<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\personal_secretary\Controller\PreparationController;
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
    private readonly ClassResolverInterface $classResolver,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.preparation_completion'),
      $container->get('current_route_match'),
      $container->get('class_resolver'),
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
    $returnOptions = $this->returnOptions($returnSurface);

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

    $transitionUrl = Url::fromRoute($route, [
      'series' => $seriesId,
      'original_occurrence_key' => $originalOccurrenceKey,
      'preparation_requirement' => $requirementId,
      'return_surface' => $returnSurface,
    ], $returnOptions);
    $form['#action'] = $transitionUrl->toString();

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

    if ($returnSurface === 'mine') {
      // Progressive enhancement: Today and non-JavaScript POST/PRG stay intact.
      $form['submit']['#ajax'] = [
        'callback' => '::ajaxTransition',
        // Forms rendered inside mine() must submit to the protected
        // transition form route, not the read-only page controller.
        'url' => $transitionUrl,
        // An explicit #ajax URL bypasses Drupal's automatic ajax_form query.
        // Preserve that marker and only the canonical 30-day horizon:
        // preRenderAjaxForm() replaces URL options with these options.
        'options' => [
          'query' => ($returnOptions['query'] ?? []) + [
            FormBuilderInterface::AJAX_FORM_REQUEST => TRUE,
          ],
        ],
        'wrapper' => 'ps-preparation-live',
        'progress' => ['type' => 'throbber'],
      ];
    }

    return $form;
  }

  /**
   * Rebuilds only the authorized current-user lists after an AJAX POST.
   */
  public function ajaxTransition(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $result = $form_state->get('preparation_transition_result');
    if (!is_array($result) || empty($result['ok'])) {
      $response->addCommand(new HtmlCommand('#ps-preparation-feedback', [
        '#plain_text' => (string) ($result['message'] ?? $this->t('This preparation action is no longer authorized or valid.')),
      ]));
      return $response;
    }

    // Re-read the domain model with the active identity, including the bounded
    // ?days=30 query; never reconstruct a row from a browser-provided state.
    $controller = $this->classResolver->getInstanceFromDefinition(PreparationController::class);
    $surface = $controller->mine();
    if (!isset($surface['sections'])) {
      $response->addCommand(new HtmlCommand('#ps-preparation-feedback', [
        '#plain_text' => (string) $this->t('This preparation action is no longer authorized or valid.'),
      ]));
      return $response;
    }

    $response->addCommand(new ReplaceCommand('#ps-preparation-live', $surface['sections']));
    $response->addCommand(new HtmlCommand('#ps-preparation-feedback', [
      '#plain_text' => (string) $result['message'],
    ]));
    $rowSelector = '#' . self::rowId($this->seriesId(), $this->originalOccurrenceKey(), $this->requirementId());
    // The inverse action is the next keyboard target after a successful move.
    $response->addCommand(new InvokeCommand($rowSelector . ' .ps-preparation-action-form__submit', 'focus'));
    return $response;
  }

  /**
   * A stable opaque DOM target shared with the current-user list renderer.
   */
  public static function rowId(int $seriesId, string $occurrenceKey, int $requirementId): string {
    return 'ps-preparation-row-' . substr(hash('sha256', $seriesId . "\0" . $occurrenceKey . "\0" . $requirementId), 0, 16);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $returnSurface = $this->returnSurface();
    $isAjax = $returnSurface === 'mine' && $this->getRequest()->isXmlHttpRequest();

    try {
      if ($this->action() === 'prepared') {
        $created = $this->completionService->markPrepared(
          $this->seriesId(),
          $this->originalOccurrenceKey(),
          $this->requirementId(),
        );
        $message = $created
          ? $this->t('Preparation marked prepared.')
          : $this->t('Preparation was already marked prepared.');
      }
      elseif ($this->action() === 'not_prepared') {
        $deleted = $this->completionService->markNotPrepared(
          $this->seriesId(),
          $this->originalOccurrenceKey(),
          $this->requirementId(),
        );
        $message = $deleted
          ? $this->t('Preparation marked not prepared.')
          : $this->t('Preparation was already not prepared.');
      }
      else {
        throw new NotFoundHttpException('Unknown preparation completion action.');
      }

      if ($isAjax) {
        $form_state->set('preparation_transition_result', [
          'ok' => TRUE,
          'message' => (string) $message,
        ]);
      }
      else {
        $this->messenger()->addStatus($message);
        $form_state->setRedirect($this->returnRoute(), [], $this->returnOptions($returnSurface));
      }
    }
    catch (InvalidArgumentException|RuntimeException) {
      $message = $this->t('This preparation action is no longer authorized or valid.');
      if ($isAjax) {
        $form_state->set('preparation_transition_result', [
          'ok' => FALSE,
          'message' => (string) $message,
        ]);
      }
      else {
        $this->messenger()->addError($message);
        $form_state->setRedirect($this->returnRoute(), [], $this->returnOptions($returnSurface));
      }
    }
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
   * Preserves only the canonical optional horizon for My preparations.
   *
   * @return array<string, mixed>
   *   Route options, without arbitrary request parameters.
   */
  private function returnOptions(string $returnSurface): array {
    $query = $this->getRequest()->query->all();
    return $returnSurface === 'mine' && ($query['days'] ?? NULL) === '30'
      ? ['query' => ['days' => 30]]
      : [];
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
