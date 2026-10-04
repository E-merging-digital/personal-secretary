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
use Drupal\personal_secretary\Service\GoogleCalendarExportService;
use Drupal\personal_secretary\Service\GoogleCalendarProjectionResolver;
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
    protected GoogleCalendarProjectionResolver $projectionResolver,
    protected GoogleCalendarExportService $exportService,
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
      $container->get('personal_secretary.google_calendar_projection_resolver'),
      $container->get('personal_secretary.google_calendar_export'),
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
   * Starts incremental write consent after one explicit CSRF-protected intent.
   */
  public function authorizeWrite(
    string $series,
    string $original_occurrence_key,
  ): RedirectResponse {
    $seriesId = (int) $series;
    $key = (string) $original_occurrence_key;
    $intent = $this->pendingStore()->get('write_intent');
    $this->pendingStore()->delete('write_intent');

    if (
      !is_array($intent)
      || (int) ($intent['uid'] ?? 0) !== (int) $this->currentUserAccount->id()
      || (int) ($intent['series'] ?? 0) !== $seriesId
      || (string) ($intent['key'] ?? '') !== $key
      || (int) ($intent['expires'] ?? 0) < time()
    ) {
      throw new AccessDeniedHttpException(
        'Explicit Google export submission is required.',
      );
    }

    $this->projectionResolver->resolve($seriesId, $key);
    if ($this->exportService->presentationState($seriesId, $key) !== 'NO_WRITE_GRANT') {
      throw new AccessDeniedHttpException(
        'Incremental Google Calendar consent is unavailable.',
      );
    }

    $client = $this->clientPlugin();
    if (
      !$client instanceof GoogleCalendar
      || trim($client->getClientId()) === ''
      || trim($client->getClientSecret()) === ''
    ) {
      $this->messengerService->addError(
        $this->t('Google Calendar connection is not configured yet.'),
      );
      return $this->detailRedirect($seriesId, $key);
    }

    $provider = $client->providerForScopes(
      CalendarAccountConnection::incrementalWriteScopes(),
      TRUE,
    );
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
        'purpose' => 'write_export',
        'series' => $seriesId,
        'key' => $key,
      ],
    );

    $request = $this->requestStack->getCurrentRequest();
    if ($request !== NULL && $request->hasSession()) {
      $request->getSession()->save();
    }

    return new TrustedRedirectResponse($authorizationUrl);
  }

  /**
   * Completes base connection or bounded incremental write consent.
   */
  public function complete(): RedirectResponse {
    $writeTarget = NULL;
    $writeGrantVerified = FALSE;

    try {
      $this->assertPendingContext();
      $pending = $this->pendingStore()->get(self::PENDING_KEY);
      if (!is_array($pending)) {
        throw new AccessDeniedHttpException(
          'Google OAuth pending context is missing.',
        );
      }

      $isWrite = ($pending['purpose'] ?? NULL) === 'write_export';
      if ($isWrite) {
        $seriesId = (int) ($pending['series'] ?? 0);
        $key = $pending['key'] ?? NULL;
        if (
          $seriesId <= 0
          || !is_string($key)
          || $key === ''
          || strlen($key) > 32
        ) {
          throw new AccessDeniedHttpException(
            'Google write target is invalid.',
          );
        }
        $writeTarget = [$seriesId, $key];
      }

      $token = $this->oauth2ClientService->retrieveAccessToken(
        GoogleCalendar::PLUGIN_ID,
      );
      if (
        !$token instanceof AccessTokenInterface
        || $token->getToken() === ''
        || !$token->getExpires()
        || $token->hasExpired()
      ) {
        throw new \RuntimeException(
          'Google OAuth token was not captured or is no longer valid.',
        );
      }

      $scopes = $this->validatedScopes($token, $isWrite);
      $subject = $this->fetchOidcSubject($token);

      if ($isWrite) {
        $this->connectionService->grantWriteAccessSameSubject(
          $subject,
          $scopes,
        );
        $writeGrantVerified = TRUE;

        // Re-resolve current occurrence authority/eligibility/payload only now.
        $result = $this->exportService->create(...$writeTarget);
        if ($result !== 'SUCCESS') {
          throw new \RuntimeException(
            'Google event creation was not accepted.',
          );
        }

        $this->messengerService->addStatus(
          $this->t('Occurrence added to Google Calendar.'),
        );
      }
      else {
        $this->verifyPrimaryCalendar($token);
        $this->connectionService->connect(
          $subject,
          $scopes,
        );
        $this->messengerService->addStatus(
          $this->t('Google Calendar connected.'),
        );
      }
    }
    catch (\Throwable) {
      if (!$writeGrantVerified) {
        // Base connection/reconnect failure and consent identity/scope mismatch
        // fail closed. A provider create failure after verified write consent
        // preserves the otherwise valid connection and grant.
        $this->connectionService->markInvalid();
        try {
          $this->oauth2ClientService->clearAccessToken(
            GoogleCalendar::PLUGIN_ID,
          );
        }
        catch (\Throwable) {
          // Best-effort local token cleanup.
        }
      }

      $this->messengerService->addError(
        $writeGrantVerified
          ? $this->t(
            'Google Calendar export failed. The connection is still available.',
          )
          : $this->t(
            'Google Calendar connection could not be verified.',
          ),
      );
    }
    finally {
      $this->clearAuthorizationContext();
    }

    return $writeTarget === NULL
      ? $this->statusRedirect()
      : $this->detailRedirect(...$writeTarget);
  }

  private function detailRedirect(
    int $series,
    string $key,
  ): RedirectResponse {
    return new RedirectResponse(
      Url::fromRoute(
        'personal_secretary.occurrence_detail',
        [
          'series' => $series,
          'original_occurrence_key' => $key,
        ],
      )->toString(),
    );
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
    bool $write = FALSE,
  ): array {
    $base = CalendarAccountConnection::connectionScopes();

    $values = method_exists($token, 'getValues')
      ? $token->getValues()
      : [];
    $reported = is_array($values)
      ? ($values['scope'] ?? NULL)
      : NULL;

    if ($write && (!is_string($reported) || trim($reported) === '')) {
      throw new \RuntimeException(
        'Google write authorization requires token-reported scopes.',
      );
    }

    if (is_string($reported) && trim($reported) !== '') {
      $actual = preg_split(
        '/\s+/',
        trim($reported),
        -1,
        PREG_SPLIT_NO_EMPTY,
      );
      $actual = array_values(array_unique($actual ?: []));
      sort($actual, SORT_STRING);

      if ($write) {
        $allowed = CalendarAccountConnection::writeScopes();
        if (
          !in_array(
            CalendarAccountConnection::SCOPE_EVENTS_OWNED,
            $actual,
            TRUE,
          )
          || array_diff($actual, $allowed) !== []
        ) {
          throw new \RuntimeException(
            'Google returned an invalid incremental OAuth scope set.',
          );
        }

        // Local authority persists the already-held base scopes plus the newly
        // verified owned-event grant, never any broader provider scope.
        return $allowed;
      }

      if ($actual !== $base) {
        throw new \RuntimeException(
          'Google returned a non-exact OAuth scope set.',
        );
      }
    }

    return $base;
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
        'allow_redirects' => FALSE,
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
        'allow_redirects' => FALSE,
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
