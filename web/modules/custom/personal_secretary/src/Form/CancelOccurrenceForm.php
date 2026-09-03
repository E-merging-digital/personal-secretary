<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\personal_secretary\Service\CancelOccurrenceService;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirms cancellation of one exact upcoming base occurrence.
 */
final class CancelOccurrenceForm extends ConfirmFormBase {

  public function __construct(
    private readonly CancelOccurrenceService $cancelOccurrence,
    private readonly RouteMatchInterface $cancelRouteMatch,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.cancel_occurrence'),
      $container->get('current_route_match'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_cancel_occurrence';
  }

  public function getQuestion(): TranslatableMarkup {
    try {
      $resolved = $this->cancelOccurrence->resolve(
        $this->seriesId(),
        $this->originalOccurrenceKey(),
      );
    }
    catch (InvalidArgumentException $exception) {
      throw new NotFoundHttpException('The requested occurrence cannot be cancelled.', $exception);
    }

    return $this->t(
      'Cancel occurrence of %activity at %time?',
      [
        '%activity' => (string) $resolved['series']->label(),
        '%time' => (new \DateTimeImmutable($resolved['occurrence']->sourceLocalStart))->format('Y-m-d H:i'),
      ],
    );
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('personal_secretary.upcoming');
  }

  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Cancel occurrence');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->cancelOccurrence->cancel(
        $this->seriesId(),
        $this->originalOccurrenceKey(),
      );
    }
    catch (InvalidArgumentException) {
      $this->messenger()->addError($this->t('This occurrence can no longer be cancelled.'));
    }

    $form_state->setRedirect('personal_secretary.upcoming');
  }

  private function seriesId(): int {
    return (int) $this->cancelRouteMatch->getParameter('series');
  }

  private function originalOccurrenceKey(): string {
    return (string) $this->cancelRouteMatch->getParameter('original_occurrence_key');
  }

}
