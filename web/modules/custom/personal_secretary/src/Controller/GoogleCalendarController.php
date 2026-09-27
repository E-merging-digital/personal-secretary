<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Link;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\oauth2_client\Entity\Oauth2Client;
use Drupal\oauth2_client\Plugin\Oauth2Client\Oauth2ClientPluginInterface;
use Drupal\oauth2_client\Service\Oauth2ClientServiceInterface;
use Drupal\personal_secretary\Entity\CalendarAccountConnection;
use Drupal\personal_secretary\Plugin\Oauth2Client\GoogleCalendar;
use Drupal\personal_secretary\Service\GoogleCalendarConnectionService;
use GuzzleHttp\ClientInterface;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Current-User Google Calendar connection UI.
 */
final class GoogleCalendarController extends ControllerBase {

  private const PENDING_COLLECTION =
    'personal_secretary_google_calendar';

  private const PENDING_KEY = 'pending';

  private const USERINFO_URI =
    'https://openidconnect.googleapis.com/v1/userinfo';

  private const PRIMARY_CALENDAR_URI =
    'https://www.googleapis.com/calendar/v3/calendars/primary';

  public function __construct(
    protected EntityTypeManagerInterface $oauthConfigEntityTypeManager,
    protected Oauth2ClientServiceInterface $oauth2ClientService,
    protected GoogleCalendarConnectionService $connectionService,
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected AccountProxyInterface $currentUserAccount,
    protected ClientInterface $httpClient,
    protected RequestStack $requestStack,
    protected MessengerInterface $messengerService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
  ): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('oauth2_client.service'),
      $container->get('personal_secretary.google_calendar_connection'),
      $container->get('tempstore.private'),
      $container->get('current_user'),
      $container->get('http_client'),
      $container->get('request_stack'),
      $container->get('messenger'),
    );
  }

  /**
   * Displays deterministic current-User connection state.
   */
  public function status(): array {
    $state = $this->connectionService->currentState();

    $build = [
      '#type' => 'container',
      'status' => [
        '#markup' => $this->t(
          'Google Calendar status: @state',
          ['@state' => $state],
        ),
      ],
      'actions' => [
        '#theme' => 'item_list',
        '#items' => [],
      ],
    ];

    if (
      $state === GoogleCalendarConnectionService::STATE_NOT_CONNECTED
    ) {
      $build['actions']['#items'][] = Link::fromTextAndUrl(
        $this->t('Connect Google Calendar'),
        Url::fromRoute(
          'personal_secretary.google_calendar_connect',
        ),
      )->toRenderable();
    }
    elseif (
      $state === CalendarAccountConnection::STATUS_INVALID
    ) {
      $build['actions']['#items'][] = Link::fromTextAndUrl(
        $this->t('Reconnect Google Calendar'),
        Url::fromRoute(
          'personal_secretary.google_calendar_connect',
        ),
      )->toRenderable();

      $build['actions']['#items'][] = Link::fromTextAndUrl(
        $this->t('Disconnect Google Calendar'),
        Url::fromRoute(
          'personal_secretary.google_calendar_disconnect',
        ),
      )->toRenderable();
    }
    else {
      $build['actions']['#items'][] = Link::fromTextAndUrl(
        $this->t('Disconnect Google Calendar'),
        Url::fromRoute(
          'personal_secretary.google_calendar_disconnect',
        ),
      )->toRenderable();
    }

    return $build;
  }

  /**
   * Starts bounded authorization for NOT_CONNECTED or INVALID state.
   */
  public function connect(): RedirectResponse {
    $state = $this->connectionService->currentState();

    if ($state === CalendarAccountConnection::STATUS_CONNECTED) {
      $this->messengerService->addStatus(
        $this->t('Google Calendar is already connected.'),
      );
      return $this->statusRedirect();
    }

    $client = $this->clientPlugin();

    if (
      trim($client->getClientId()) === ''
      || trim($client->getClientSecret()) === ''
    ) {
      $this->messengerService->addError(
        $this->t(
          'Google Calendar connection is not configured yet.',
        ),
      );
      return $this->statusRedirect();
    }

    if ($state === CalendarAccountConnection::STATUS_INVALID) {
      // Reconnect starts from a known local-token-empty state while preserving
      // INVALID connection metadata until a new connection verifies.
      $this->oauth2ClientService->clearAccessToken(
        GoogleCalendar::PLUGIN_ID,
      );
    }

    $provider = $client->getProvider();
    $authorizationUrl = $provider->getAuthorizationUrl();
    $stateValue = (string) $provider->getState();

    if ($authorizationUrl === '' || $stateValue === '') {
      throw new \RuntimeException(
        'Google OAuth authorization state could not be created.',
      );
    }

    $this->tempStoreFactory
      ->get('oauth2_client')
      ->set(
        'oauth2_client_state-' . GoogleCalendar::PLUGIN_ID,
        $stateValue,
      );

    $this->pendingStore()->set(
      self::PENDING_KEY,
      [
        'uid' => (int) $this->currentUserAccount->id(),
        'state_hash' => hash('sha256', $stateValue),
      ],
    );

    $request = $this->requestStack->getCurrentRequest();
    if ($request !== NULL && $request->hasSession()) {
      $request->getSession()->save();
    }

    return new TrustedRedirectResponse($authorizationUrl);
  }

  /**
   * Completes connection after contrib has validated state/exchanged code.
   */
  public function complete(): RedirectResponse {
    try {
      $this->assertPendingContext();

      $token = $this->oauth2ClientService->retrieveAccessToken(
        GoogleCalendar::PLUGIN_ID,
      );
      if (!$token instanceof AccessTokenInterface) {
        throw new \RuntimeException(
          'Google OAuth token was not captured.',
        );
      }

      $scopes = $this->validatedScopes($token);
      $subject = $this->fetchOidcSubject($token);
      $this->verifyPrimaryCalendar($token);

      $this->connectionService->connect(
        $subject,
        $scopes,
      );

      $this->messengerService->addStatus(
        $this->t('Google Calendar connected.'),
      );
    }
    catch (\Throwable $exception) {
      // Existing INVALID metadata remains INVALID on failed reconnect.
      // A first failed connection remains NOT_CONNECTED.
      $this->connectionService->markInvalid();

      try {
        $this->oauth2ClientService->clearAccessToken(
          GoogleCalendar::PLUGIN_ID,
        );
      }
      catch (\Throwable) {
        // Local token cleanup is best effort here; disconnect has its own
        // unconditional local-cleanup path.
      }

      $this->messengerService->addError(
        $this->t(
          'Google Calendar connection could not be verified.',
        ),
      );
    }
    finally {
      $this->clearAuthorizationContext();
    }

    return $this->statusRedirect();
  }

  /**
   * Loads the configured project Google plugin.
   */
  private function clientPlugin(): Oauth2ClientPluginInterface {
    $entity = $this->oauthConfigEntityTypeManager
      ->getStorage('oauth2_client')
      ->load(GoogleCalendar::PLUGIN_ID);

    if (!$entity instanceof Oauth2Client) {
      throw new \RuntimeException(
        'Google Calendar OAuth client configuration is missing.',
      );
    }

    $plugin = $entity->getClient();
    if (!$plugin instanceof Oauth2ClientPluginInterface) {
      throw new \RuntimeException(
        'Google Calendar OAuth client plugin is unavailable.',
      );
    }

    return $plugin;
  }

  /**
   * Validates current User against the pending upstream OAuth state.
   */
  private function assertPendingContext(): void {
    $pending = $this->pendingStore()->get(self::PENDING_KEY);
    if (!is_array($pending)) {
      throw new AccessDeniedHttpException(
        'Google OAuth pending context is missing.',
      );
    }

    $pendingUid = (int) ($pending['uid'] ?? 0);
    $pendingHash = $pending['state_hash'] ?? NULL;

    if (
      $pendingUid <= 0
      || $pendingUid !== (int) $this->currentUserAccount->id()
      || !is_string($pendingHash)
      || $pendingHash === ''
    ) {
      throw new AccessDeniedHttpException(
        'Google OAuth current-User context mismatch.',
      );
    }

    $upstreamState = $this->tempStoreFactory
      ->get('oauth2_client')
      ->get(
        'oauth2_client_state-' . GoogleCalendar::PLUGIN_ID,
      );

    if (
      !is_string($upstreamState)
      || $upstreamState === ''
      || !hash_equals(
        $pendingHash,
        hash('sha256', $upstreamState),
      )
    ) {
      throw new AccessDeniedHttpException(
        'Google OAuth state/current-User context mismatch.',
      );
    }
  }

  /**
   * Returns exact granted/requested scopes with no write/event scope.
   *
   * @return string[]
   *   Deterministic exact connection scopes.
   */
  private function validatedScopes(
    AccessTokenInterface $token,
  ): array {
    $expected = CalendarAccountConnection::connectionScopes();

    $values = method_exists($token, 'getValues')
      ? $token->getValues()
      : [];

    $reported = is_array($values)
      ? ($values['scope'] ?? NULL)
      : NULL;

    if (is_string($reported) && trim($reported) !== '') {
      $actual = preg_split(
        '/\s+/',
        trim($reported),
        -1,
        PREG_SPLIT_NO_EMPTY,
      );
      $actual = array_values(array_unique($actual ?: []));
      sort($actual, SORT_STRING);

      if ($actual !== $expected) {
        throw new \RuntimeException(
          'Google returned a non-exact OAuth scope set.',
        );
      }
    }

    // OAuth scope is permitted to be omitted from the token response when it
    // equals the requested set. This provider never enables incremental scopes.
    return $expected;
  }

  /**
   * Fetches only the Google OIDC subject; other claims are discarded.
   */
  private function fetchOidcSubject(
    AccessTokenInterface $token,
  ): string {
    $response = $this->httpClient->request(
      'GET',
      self::USERINFO_URI,
      [
        'headers' => [
          'Authorization' => 'Bearer ' . $token->getToken(),
          'Accept' => 'application/json',
        ],
        'http_errors' => FALSE,
        'timeout' => 10,
      ],
    );

    if ($response->getStatusCode() !== 200) {
      throw new \RuntimeException(
        'Google OIDC subject verification failed.',
      );
    }

    $payload = json_decode(
      (string) $response->getBody(),
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );

    $subject = is_array($payload)
      ? trim((string) ($payload['sub'] ?? ''))
      : '';

    if ($subject === '') {
      throw new \RuntimeException(
        'Google OIDC subject is missing.',
      );
    }

    return $subject;
  }

  /**
   * Performs only Calendars.get("primary").
   */
  private function verifyPrimaryCalendar(
    AccessTokenInterface $token,
  ): void {
    $response = $this->httpClient->request(
      'GET',
      self::PRIMARY_CALENDAR_URI,
      [
        'headers' => [
          'Authorization' => 'Bearer ' . $token->getToken(),
          'Accept' => 'application/json',
        ],
        'http_errors' => FALSE,
        'timeout' => 10,
      ],
    );

    if (
      $response->getStatusCode() < 200
      || $response->getStatusCode() >= 300
    ) {
      throw new \RuntimeException(
        'Google primary Calendar verification failed.',
      );
    }
  }

  /**
   * Clears project and contrib ephemeral authorization state.
   */
  private function clearAuthorizationContext(): void {
    try {
      $this->pendingStore()->delete(self::PENDING_KEY);
    }
    catch (\Throwable) {
      // Fail closed on the next authorization attempt.
    }

    try {
      $this->tempStoreFactory
        ->get('oauth2_client')
        ->delete(
          'oauth2_client_state-' . GoogleCalendar::PLUGIN_ID,
        );
    }
    catch (\Throwable) {
      // No durable account authority is derived from this context.
    }
  }

  /**
   * Returns project-private OAuth context.
   */
  private function pendingStore() {
    return $this->tempStoreFactory->get(
      self::PENDING_COLLECTION,
    );
  }

  /**
   * Redirects to deterministic status.
   */
  private function statusRedirect(): RedirectResponse {
    return new RedirectResponse(
      Url::fromRoute(
        'personal_secretary.google_calendar_status',
      )->toString(),
    );
  }

}
