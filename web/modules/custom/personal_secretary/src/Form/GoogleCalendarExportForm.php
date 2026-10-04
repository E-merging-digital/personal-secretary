<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\personal_secretary\Service\GoogleCalendarExportService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One explicit, CSRF-protected add/update action for an eligible occurrence.
 */
final class GoogleCalendarExportForm extends FormBase {

  public function __construct(
    private readonly GoogleCalendarExportService $exports,
    private readonly PrivateTempStoreFactory $tempStore,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('personal_secretary.google_calendar_export'), $container->get('tempstore.private'));
  }

  public function getFormId(): string {
    return 'personal_secretary_google_calendar_export';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?string $series = NULL, ?string $original_occurrence_key = NULL): array {
    $seriesId = (int) $series;
    $key = (string) $original_occurrence_key;
    try {
      $state = $this->exports->presentationState($seriesId, $key);
    }
    catch (\InvalidArgumentException $exception) {
      throw new NotFoundHttpException('Google Calendar export is unavailable.', $exception);
    }
    $form_state->set('series', $seriesId)->set('key', $key);
    $form['#cache']['max-age'] = 0;
    $form['#action'] = Url::fromRoute('personal_secretary.google_calendar_export', ['series' => $seriesId, 'original_occurrence_key' => $key])->toString();
    if ($state === 'NOT_CONNECTED') {
      $form['connect'] = ['#type' => 'link', '#title' => $this->t('Connect Google Calendar'), '#url' => Url::fromRoute('personal_secretary.google_calendar_connect')];
    }
    elseif ($state === 'NO_WRITE_GRANT') {
      $form['explanation'] = ['#markup' => $this->t('Authorize Google Calendar event access to add this occurrence to your primary calendar. Only its title, time and location are shared. Updates are always manual.')];
    }
    else {
      $form['state'] = ['#markup' => match ($state) {
        'CURRENT' => $this->t('This occurrence is up to date in Google Calendar.'),
        'STALE' => $this->t('This occurrence has changes to send to Google Calendar.'),
        'CONFLICT' => $this->t('Google Calendar has a conflicting event or account. No overwrite is available.'),
        'REMOTE_MISSING' => $this->t('The Google Calendar event is missing. No recreation is available.'),
        default => $this->t('This occurrence has not been added to Google Calendar.'),
      }];
    }
    $label = match ($state) {
      'NO_WRITE_GRANT' => $this->t('Authorize and add to Google Calendar'),
      'NOT_EXPORTED' => $this->t('Add to Google Calendar'),
      'STALE' => $this->t('Update Google Calendar'),
      default => NULL,
    };
    if ($label !== NULL) {
      // Preserve the operation the browser actually submitted. The current
      // presentation state is revalidated in submitForm() before any action,
      // so stale or tampered input fails closed rather than escalating.
      $form['operation'] = [
        '#type' => 'hidden',
        '#default_value' => $state,
      ];
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['submit'] = ['#type' => 'submit', '#value' => $label];
    }
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $series = (int) $form_state->get('series');
    $key = (string) $form_state->get('key');
    $parameters = ['series' => $series, 'original_occurrence_key' => $key];
    try {
      $state = $this->exports->presentationState($series, $key);
      $operation = $form_state->getValue('operation');
      if ($operation === 'NO_WRITE_GRANT' && $state === 'NO_WRITE_GRANT') {
        $this->tempStore->get('personal_secretary_google_calendar')->set('write_intent', [
          'uid' => (int) $this->currentUser()->id(), 'series' => $series, 'key' => $key, 'expires' => time() + 600,
        ]);
        $form_state->setRedirect('personal_secretary.google_calendar_authorize_write', $parameters);
        return;
      }
      $result = match ($operation) {
        'NOT_EXPORTED' => $this->exports->create($series, $key),
        'STALE' => $this->exports->update($series, $key),
        default => 'FAILED',
      };
      if (in_array($result, ['SUCCESS', 'NOOP'], TRUE)) {
        $this->messenger()->addStatus($this->t('Google Calendar is up to date.'));
      }
      else {
        $this->messenger()->addError($this->t('Google Calendar could not be updated. No retry was made.'));
      }
    }
    catch (\Throwable) {
      $this->messenger()->addError($this->t('Google Calendar could not be updated. No retry was made.'));
    }
    $form_state->setRedirect('personal_secretary.occurrence_detail', $parameters);
  }

}
