<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\personal_secretary\Service\PreparationCompletionService;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirms mark-prepared or mark-not-prepared for one exact preparation.
 */
final class PreparationCompletionTransitionForm extends ConfirmFormBase {

  public function __construct(
    private readonly PreparationCompletionService $completionService,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.preparation_completion'),
      $container->get('current_route_match'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_preparation_' . $this->action();
  }

  public function getQuestion(): TranslatableMarkup {
    try {
      $state = $this->completionService->describeCurrentPreparation(
        $this->seriesId(),
        $this->originalOccurrenceKey(),
        $this->requirementId(),
      );
    }
    catch (InvalidArgumentException|RuntimeException $exception) {
      throw new NotFoundHttpException('The requested preparation is unavailable.', $exception);
    }

    return match ($this->action()) {
      'prepared' => $this->t('Mark %preparation prepared?', ['%preparation' => $state['instruction']]),
      'not_prepared' => $this->t('Mark %preparation not prepared?', ['%preparation' => $state['instruction']]),
      default => throw new NotFoundHttpException('Unknown preparation completion action.'),
    };
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute($this->returnRoute());
  }

  public function getConfirmText(): TranslatableMarkup {
    return match ($this->action()) {
      'prepared' => $this->t('Mark prepared'),
      'not_prepared' => $this->t('Mark not prepared'),
      default => $this->t('Confirm'),
    };
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
    return (string) ($this->routeMatch->getRouteObject()?->getDefault('_preparation_completion_action') ?? '');
  }

  private function seriesId(): int {
    return (int) $this->routeMatch->getParameter('series');
  }

  private function originalOccurrenceKey(): string {
    return (string) $this->routeMatch->getParameter('original_occurrence_key');
  }

  private function requirementId(): int {
    return (int) $this->routeMatch->getParameter('preparation_requirement');
  }

  private function returnRoute(): string {
    return match ((string) $this->routeMatch->getParameter('return_surface')) {
      'mine' => 'personal_secretary.my_preparations',
      'today' => 'personal_secretary.today',
      default => throw new NotFoundHttpException('Unknown preparation return surface.'),
    };
  }

}
