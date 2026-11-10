<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\personal_secretary\Service\CurrentUserOccurrenceCancellationService;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirms cancellation of one exact upcoming base occurrence.
 */
final class CancelOccurrenceForm extends ConfirmFormBase {

  public function __construct(
    private readonly CurrentUserOccurrenceCancellationService $currentUserCancellation,
    private readonly RouteMatchInterface $cancelRouteMatch,
    private readonly AccountInterface $cancelCurrentUser,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.current_user_occurrence_cancellation'),
      $container->get('current_route_match'),
      $container->get('current_user'),
      $container->get('date.formatter'),
    );
  }

  public function getFormId(): string {
    return 'personal_secretary_cancel_occurrence';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);
    if ($this->fromMyUpcoming()) {
      // Preserve only the bounded product origin on the confirmation POST.
      $form['#action'] = Url::fromRoute('personal_secretary.cancel_occurrence', [
        'series' => $this->seriesId(),
        'original_occurrence_key' => $this->originalOccurrenceKey(),
      ], ['query' => ['from' => 'mine'] + $this->returnQuery()])->toString();
    }
    return $form;
  }

  public function getQuestion(): TranslatableMarkup {
    if ($this->getRequest()->isMethod('POST')) {
      return $this->t('Cancel this occurrence?');
    }

    try {
      $resolved = $this->currentUserCancellation->authorize(
        $this->seriesId(),
        $this->originalOccurrenceKey(),
      );
    }
    catch (InvalidArgumentException $exception) {
      throw new NotFoundHttpException('The requested occurrence cannot be cancelled.', $exception);
    }

    $occurrence = $resolved['occurrence'];
    $sourceStart = new \DateTimeImmutable(
      $occurrence->sourceLocalStart,
      new \DateTimeZone($occurrence->sourceTimezone),
    );

    return $this->t(
      'Cancel occurrence of %activity at %time?',
      [
        '%activity' => (string) $resolved['series']->label(),
        '%time' => $this->dateFormatter->format($sourceStart->getTimestamp(), 'medium', '', $occurrence->sourceTimezone),
      ],
    );
  }

  public function getDescription(): TranslatableMarkup {
    return $this->t('Only this date will be cancelled. The recurring activity and its other occurrences stay unchanged. This cancellation cannot be undone.');
  }

  public function getCancelText(): TranslatableMarkup {
    return $this->t('Keep this occurrence');
  }

  public function getCancelUrl(): Url {
    if ($this->fromMyUpcoming()) {
      return Url::fromRoute('personal_secretary.my_upcoming', [], $this->returnOptions());
    }

    // Admins can manage an occurrence without a current Person bound to them.
    if ($this->cancelCurrentUser->hasPermission(HouseholdAuthorizationService::ADMIN_PERMISSION)) {
      return Url::fromRoute('personal_secretary.upcoming');
    }

    // The detail route independently rechecks the exact occurrence access.
    return Url::fromRoute('personal_secretary.occurrence_detail', [
      'series' => $this->seriesId(),
      'original_occurrence_key' => $this->originalOccurrenceKey(),
    ]);
  }

  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Cancel this occurrence');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->currentUserCancellation->cancel(
        $this->seriesId(),
        $this->originalOccurrenceKey(),
      );
    }
    catch (InvalidArgumentException) {
      $this->messenger()->addError($this->t('This occurrence can no longer be cancelled.'));
    }

    if ($this->fromMyUpcoming()) {
      $form_state->setRedirect('personal_secretary.my_upcoming', [], $this->returnOptions());
      return;
    }
    $form_state->setRedirect($this->returnRoute());
  }

  private function fromMyUpcoming(): bool {
    return ($this->getRequest()->query->all()['from'] ?? NULL) === 'mine';
  }

  /**
   * @return array<string, string>
   */
  private function returnQuery(): array {
    $input = $this->getRequest()->query->all();
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
    return $query;
  }

  /**
   * @return array<string, mixed>
   */
  private function returnOptions(): array {
    $query = $this->returnQuery();
    return $query === [] ? [] : ['query' => $query];
  }

  private function returnRoute(): string {
    return $this->cancelCurrentUser->hasPermission(HouseholdAuthorizationService::ADMIN_PERMISSION)
      ? 'personal_secretary.upcoming'
      : 'personal_secretary.my_upcoming';
  }

  private function seriesId(): int {
    return (int) $this->cancelRouteMatch->getParameter('series');
  }

  private function originalOccurrenceKey(): string {
    return (string) $this->cancelRouteMatch->getParameter('original_occurrence_key');
  }

}
