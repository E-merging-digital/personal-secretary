<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\personal_secretary\Service\GoogleCalendarPlanningSyncService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Explicit current-user bounded read-only Google planning refresh.
 */
final class GoogleCalendarRefreshForm extends FormBase {

  public function __construct(
    protected readonly GoogleCalendarPlanningSyncService $sync,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('personal_secretary.google_calendar_planning_sync'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'personal_secretary_google_calendar_refresh';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#action'] = Url::fromRoute(
      'personal_secretary.google_calendar_refresh',
    )->toString();

    $form['explanation'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t(
        'Refresh the read-only Google Calendar planning view. Personal Secretary activities are not changed.',
      ),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['refresh'] = [
      '#type' => 'submit',
      '#value' => $this->t('Refresh now'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->sync->refreshCurrentUser();
      $this->messenger()->addStatus(
        $this->t('Google Calendar planning was refreshed.'),
      );
    }
    catch (\Throwable) {
      $this->messenger()->addError(
        $this->t(
          'Google Calendar planning could not be refreshed. The last known planning data was kept.',
        ),
      );
    }

    $form_state->setRedirect('personal_secretary.google_calendar_status');
  }

}
