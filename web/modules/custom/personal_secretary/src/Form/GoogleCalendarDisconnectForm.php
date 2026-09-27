<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\oauth2_client\Service\Oauth2ClientServiceInterface;
use Drupal\personal_secretary\Plugin\Oauth2Client\GoogleCalendar;
use Drupal\personal_secretary\Service\GoogleCalendarConnectionService;
use GuzzleHttp\ClientInterface;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Disconnects the current User's Google Calendar account.
 */
final class GoogleCalendarDisconnectForm extends ConfirmFormBase {

  private const REVOCATION_URI =
    'https://oauth2.googleapis.com/revoke';

  public function __construct(
    protected Oauth2ClientServiceInterface $oauth2ClientService,
    protected GoogleCalendarConnectionService $connectionService,
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected ClientInterface $httpClient,
    protected MessengerInterface $messengerService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
  ): self {
    return new self(
      $container->get('oauth2_client.service'),
      $container->get('personal_secretary.google_calendar_connection'),
      $container->get('tempstore.private'),
      $container->get('http_client'),
      $container->get('messenger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'personal_secretary_google_calendar_disconnect';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Disconnect Google Calendar?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute(
      'personal_secretary.google_calendar_status',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Disconnect');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    $remoteRevocationConfirmed = FALSE;

    try {
      $token = $this->oauth2ClientService->retrieveAccessToken(
        GoogleCalendar::PLUGIN_ID,
      );

      if ($token instanceof AccessTokenInterface) {
        $revocationToken = $token->getRefreshToken()
          ?: $token->getToken();

        if (is_string($revocationToken) && $revocationToken !== '') {
          $response = $this->httpClient->request(
            'POST',
            self::REVOCATION_URI,
            [
              'form_params' => ['token' => $revocationToken],
              'http_errors' => FALSE,
              'timeout' => 10,
            ],
          );

          $remoteRevocationConfirmed =
            $response->getStatusCode() === 200;
        }
      }
    }
    catch (\Throwable) {
      $remoteRevocationConfirmed = FALSE;
    }
    finally {
      try {
        $this->oauth2ClientService->clearAccessToken(
          GoogleCalendar::PLUGIN_ID,
        );
      }
      catch (\Throwable) {
        // Continue with connection/context cleanup.
      }

      $this->connectionService->disconnectLocal();

      foreach ([
        ['personal_secretary_google_calendar', 'pending'],
        [
          'oauth2_client',
          'oauth2_client_state-' . GoogleCalendar::PLUGIN_ID,
        ],
      ] as [$collection, $key]) {
        try {
          $this->tempStoreFactory
            ->get($collection)
            ->delete($key);
        }
        catch (\Throwable) {
          // Ephemeral cleanup remains best effort after durable local cleanup.
        }
      }
    }

    if ($remoteRevocationConfirmed) {
      $this->messengerService->addStatus(
        $this->t(
          'Google Calendar disconnected and provider revocation confirmed.',
        ),
      );
    }
    else {
      $this->messengerService->addStatus(
        $this->t(
          'Google Calendar disconnected locally. Provider revocation could not be confirmed.',
        ),
      );
    }

    $form_state->setRedirect(
      'personal_secretary.google_calendar_status',
    );
  }

}
