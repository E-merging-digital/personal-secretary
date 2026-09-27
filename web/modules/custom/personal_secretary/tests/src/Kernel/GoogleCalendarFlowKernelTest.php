<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\key\Entity\Key;
use Drupal\oauth2_client\Entity\Oauth2Client;
use Drupal\oauth2_client\Service\Oauth2ClientServiceInterface;
use Drupal\personal_secretary\Controller\GoogleCalendarController;
use Drupal\personal_secretary\Entity\CalendarAccountConnection;
use Drupal\personal_secretary\Form\GoogleCalendarDisconnectForm;
use Drupal\personal_secretary\Plugin\Oauth2Client\GoogleCalendar;
use Drupal\personal_secretary\Service\GoogleCalendarConnectionService;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the bounded synthetic Google Calendar connection flow.
 *
 * @group personal_secretary
 */
#[RunTestsInSeparateProcesses]
final class GoogleCalendarFlowKernelTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'datetime',
    'datetime_range',
    'date_recur',
    'key',
    'oauth2_client',
    'easy_encryption',
    'personal_secretary',
  ];

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema(
      CalendarAccountConnection::ENTITY_TYPE_ID,
    );

    // Controller and provider redirects use named routes. Rebuild only the
    // synthetic Kernel routing table; no external request is performed.
    $this->container->get('router.builder')->rebuild();
  }

  public function testInvalidConnectionCanBeginReconnect(): void {
    $uid = $this->createSyntheticUser('reconnect-user');
    $this->actAs($uid);

    $this->createSyntheticCredentialKeys();
    $this->createSyntheticOauthClient();

    /** @var \Drupal\personal_secretary\Service\GoogleCalendarConnectionService $connections */
    $connections = $this->container->get(
      'personal_secretary.google_calendar_connection',
    );

    $connections->connect(
      'reconnect-google-sub',
      CalendarAccountConnection::connectionScopes(),
    );
    $connections->markInvalid();

    $this->assertSame(
      CalendarAccountConnection::STATUS_INVALID,
      $connections->currentState(),
    );

    $tokenStateKey = GoogleCalendar::tokenStateKey($uid);

    // The INVALID reconnect path must clear any stale delegated token before
    // creating a new authorization context.
    $this->container->get('state')->set(
      $tokenStateKey,
      [
        'ciphertext' => 'synthetic-stale-ciphertext',
        'key_id' => 'synthetic_key',
      ],
    );

    $controller = GoogleCalendarController::create(
      $this->container,
    );

    $response = $controller->connect();

    $this->assertNull(
      $this->container->get('state')->get($tokenStateKey),
      'Reconnect must clear the stale local token first.',
    );

    $parts = parse_url($response->getTargetUrl());

    $this->assertIsArray($parts);
    $this->assertSame(
      'accounts.google.com',
      $parts['host'] ?? NULL,
    );

    parse_str((string) ($parts['query'] ?? ''), $query);

    $this->assertSame(
      'offline',
      $query['access_type'] ?? NULL,
    );
    $this->assertSame(
      'consent',
      $query['prompt'] ?? NULL,
    );

    $requestedScopes = preg_split(
      '/\s+/',
      (string) ($query['scope'] ?? ''),
      -1,
      PREG_SPLIT_NO_EMPTY,
    );
    $requestedScopes = array_values(
      array_unique($requestedScopes ?: []),
    );
    sort($requestedScopes, SORT_STRING);

    $this->assertSame(
      CalendarAccountConnection::connectionScopes(),
      $requestedScopes,
    );

    $oauthState = $this->container
      ->get('tempstore.private')
      ->get('oauth2_client')
      ->get(
        'oauth2_client_state-' . GoogleCalendar::PLUGIN_ID,
      );

    $pending = $this->container
      ->get('tempstore.private')
      ->get('personal_secretary_google_calendar')
      ->get('pending');

    $this->assertIsString($oauthState);
    $this->assertNotSame('', $oauthState);

    $this->assertIsArray($pending);
    $this->assertSame($uid, (int) ($pending['uid'] ?? 0));
    $this->assertSame(
      hash('sha256', $oauthState),
      $pending['state_hash'] ?? NULL,
    );

    // Reconnect does not claim CONNECTED until the callback verifies Google.
    $this->assertSame(
      CalendarAccountConnection::STATUS_INVALID,
      $connections->currentState(),
    );
  }

  public function testCallbackRequiresBoundUserAndVerifiesOnlyPrimary(): void {
    $uid = $this->createSyntheticUser('callback-user');
    $this->actAs($uid);

    $oauth = $this->createMock(
      Oauth2ClientServiceInterface::class,
    );

    $oauth
      ->expects($this->once())
      ->method('retrieveAccessToken')
      ->with(GoogleCalendar::PLUGIN_ID)
      ->willReturn(
        new AccessToken([
          'access_token' => 'synthetic_access',
          'refresh_token' => 'synthetic_refresh',
          'expires' => time() + 3600,
          'scope' => implode(
            ' ',
            CalendarAccountConnection::connectionScopes(),
          ),
        ]),
      );

    $oauth
      ->expects($this->never())
      ->method('clearAccessToken');

    $requests = [];

    $http = $this->createMock(ClientInterface::class);
    $http
      ->expects($this->exactly(2))
      ->method('request')
      ->willReturnCallback(
        function (
          string $method,
          mixed $uri,
          array $options = [],
        ) use (&$requests): Response {
          $requests[] = [
            'method' => $method,
            'uri' => (string) $uri,
            'options' => $options,
          ];

          if (count($requests) === 1) {
            return new Response(
              200,
              ['Content-Type' => 'application/json'],
              json_encode(
                [
                  'sub' => 'verified-google-sub',
                  'email' => 'must-not-persist@example.test',
                  'name' => 'Must Not Persist',
                ],
                JSON_THROW_ON_ERROR,
              ),
            );
          }

          return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(
              [
                'id' => 'real-calendar-id-must-not-persist',
                'summary' => 'Private calendar',
              ],
              JSON_THROW_ON_ERROR,
            ),
          );
        },
      );

    $stateValue = 'synthetic-oauth-state';

    $this->container
      ->get('tempstore.private')
      ->get('oauth2_client')
      ->set(
        'oauth2_client_state-' . GoogleCalendar::PLUGIN_ID,
        $stateValue,
      );

    $this->container
      ->get('tempstore.private')
      ->get('personal_secretary_google_calendar')
      ->set(
        'pending',
        [
          'uid' => $uid,
          'state_hash' => hash('sha256', $stateValue),
        ],
      );

    $controller = $this->controller($oauth, $http);
    $controller->complete();

    $this->assertCount(2, $requests);
    $this->assertSame('GET', $requests[0]['method']);
    $this->assertSame('GET', $requests[1]['method']);

    $firstPath = parse_url(
      $requests[0]['uri'],
      PHP_URL_PATH,
    );
    $secondPath = parse_url(
      $requests[1]['uri'],
      PHP_URL_PATH,
    );

    $this->assertSame(
      '/v1/userinfo',
      $firstPath,
    );
    $this->assertSame(
      '/calendar/v3/calendars/primary',
      $secondPath,
    );

    foreach ($requests as $request) {
      $this->assertSame(
        'Bearer synthetic_access',
        $request['options']['headers']['Authorization'] ?? NULL,
      );
    }

    /** @var \Drupal\personal_secretary\Service\GoogleCalendarConnectionService $connections */
    $connections = $this->container->get(
      'personal_secretary.google_calendar_connection',
    );

    $connection = $connections->currentConnection();

    $this->assertNotNull($connection);
    $this->assertSame(
      CalendarAccountConnection::STATUS_CONNECTED,
      $connections->currentState(),
    );
    $this->assertSame(
      'verified-google-sub',
      (string) $connection
        ->get('provider_subject_id')
        ->value,
    );

    foreach ([
      'email',
      'profile',
      'display_name',
      'calendar_id',
    ] as $forbiddenField) {
      $this->assertFalse(
        $connection->hasField($forbiddenField),
      );
    }

    $this->assertNull(
      $this->container
        ->get('tempstore.private')
        ->get('oauth2_client')
        ->get(
          'oauth2_client_state-' . GoogleCalendar::PLUGIN_ID,
        ),
    );

    $this->assertNull(
      $this->container
        ->get('tempstore.private')
        ->get('personal_secretary_google_calendar')
        ->get('pending'),
    );
  }

  public function testStateUserMismatchFailsClosed(): void {
    $uid = $this->createSyntheticUser('mismatch-user');
    $otherUid = $this->createSyntheticUser('other-user');
    $this->actAs($uid);

    $oauth = $this->createMock(
      Oauth2ClientServiceInterface::class,
    );

    $oauth
      ->expects($this->never())
      ->method('retrieveAccessToken');

    $oauth
      ->expects($this->once())
      ->method('clearAccessToken')
      ->with(GoogleCalendar::PLUGIN_ID);

    $http = $this->createMock(ClientInterface::class);
    $http
      ->expects($this->never())
      ->method('request');

    $stateValue = 'state-bound-to-current-private-store';

    $this->container
      ->get('tempstore.private')
      ->get('oauth2_client')
      ->set(
        'oauth2_client_state-' . GoogleCalendar::PLUGIN_ID,
        $stateValue,
      );

    $this->container
      ->get('tempstore.private')
      ->get('personal_secretary_google_calendar')
      ->set(
        'pending',
        [
          'uid' => $otherUid,
          'state_hash' => hash('sha256', $stateValue),
        ],
      );

    $controller = $this->controller($oauth, $http);
    $controller->complete();

    /** @var \Drupal\personal_secretary\Service\GoogleCalendarConnectionService $connections */
    $connections = $this->container->get(
      'personal_secretary.google_calendar_connection',
    );

    $this->assertSame(
      GoogleCalendarConnectionService::STATE_NOT_CONNECTED,
      $connections->currentState(),
    );

    $this->assertNull(
      $this->container
        ->get('tempstore.private')
        ->get('personal_secretary_google_calendar')
        ->get('pending'),
    );

    $this->assertNull(
      $this->container
        ->get('tempstore.private')
        ->get('oauth2_client')
        ->get(
          'oauth2_client_state-' . GoogleCalendar::PLUGIN_ID,
        ),
    );
  }

  public function testDisconnectAlwaysClearsLocalStateWhenRevocationUnknown(): void {
    $uid = $this->createSyntheticUser('disconnect-user');
    $this->actAs($uid);

    /** @var \Drupal\personal_secretary\Service\GoogleCalendarConnectionService $connections */
    $connections = $this->container->get(
      'personal_secretary.google_calendar_connection',
    );

    $connections->connect(
      'disconnect-google-sub',
      CalendarAccountConnection::connectionScopes(),
    );

    $oauth = $this->createMock(
      Oauth2ClientServiceInterface::class,
    );

    $oauth
      ->expects($this->once())
      ->method('retrieveAccessToken')
      ->with(GoogleCalendar::PLUGIN_ID)
      ->willReturn(
        new AccessToken([
          'access_token' => 'disconnect-access',
          'refresh_token' => 'disconnect-refresh',
          'expires' => time() + 3600,
        ]),
      );

    $oauth
      ->expects($this->once())
      ->method('clearAccessToken')
      ->with(GoogleCalendar::PLUGIN_ID);

    $revocationRequests = [];

    $http = $this->createMock(ClientInterface::class);
    $http
      ->expects($this->once())
      ->method('request')
      ->willReturnCallback(
        function (
          string $method,
          mixed $uri,
          array $options = [],
        ) use (&$revocationRequests): Response {
          $revocationRequests[] = [
            'method' => $method,
            'uri' => (string) $uri,
            'options' => $options,
          ];

          // Explicitly unconfirmed remote revocation.
          return new Response(503);
        },
      );

    $this->container
      ->get('tempstore.private')
      ->get('personal_secretary_google_calendar')
      ->set('pending', ['uid' => $uid]);

    $this->container
      ->get('tempstore.private')
      ->get('oauth2_client')
      ->set(
        'oauth2_client_state-' . GoogleCalendar::PLUGIN_ID,
        'disconnect-state',
      );

    $form = new GoogleCalendarDisconnectForm(
      $oauth,
      $connections,
      $this->container->get('tempstore.private'),
      $http,
      $this->container->get('messenger'),
    );

    $formArray = [];
    $formState = new FormState();

    $form->submitForm($formArray, $formState);

    $this->assertCount(1, $revocationRequests);
    $this->assertSame(
      'POST',
      $revocationRequests[0]['method'],
    );

    $this->assertStringContainsString(
      'oauth2.googleapis.com/revoke',
      $revocationRequests[0]['uri'],
    );

    $this->assertSame(
      'disconnect-refresh',
      $revocationRequests[0]['options']['form_params']['token']
        ?? NULL,
    );

    $this->assertSame(
      GoogleCalendarConnectionService::STATE_NOT_CONNECTED,
      $connections->currentState(),
    );

    $this->assertNull(
      $this->container
        ->get('tempstore.private')
        ->get('personal_secretary_google_calendar')
        ->get('pending'),
    );

    $this->assertNull(
      $this->container
        ->get('tempstore.private')
        ->get('oauth2_client')
        ->get(
          'oauth2_client_state-' . GoogleCalendar::PLUGIN_ID,
        ),
    );

    $messages = $this->container->get('messenger')->all();

    $statusText = implode(
      "\n",
      array_map(
        static fn(mixed $message): string => (string) $message,
        $messages['status'] ?? [],
      ),
    );

    $this->assertStringContainsString(
      'disconnected locally',
      $statusText,
    );
    $this->assertStringContainsString(
      'could not be confirmed',
      $statusText,
    );
  }

  private function controller(
    Oauth2ClientServiceInterface $oauth,
    ClientInterface $http,
  ): GoogleCalendarController {
    return new GoogleCalendarController(
      $this->container->get('entity_type.manager'),
      $oauth,
      $this->container->get(
        'personal_secretary.google_calendar_connection',
      ),
      $this->container->get('tempstore.private'),
      $this->container->get('current_user'),
      $http,
      $this->container->get('request_stack'),
      $this->container->get('messenger'),
    );
  }

  private function createSyntheticCredentialKeys(): void {
    $clientIdVariable =
      'PERSONAL_SECRETARY_TEST_GOOGLE_CLIENT_ID';
    $clientSecretVariable =
      'PERSONAL_SECRETARY_TEST_GOOGLE_CLIENT_SECRET';

    putenv($clientIdVariable . '=synthetic-client-id');
    putenv($clientSecretVariable . '=synthetic-client-secret');

    foreach ([
      GoogleCalendar::CLIENT_ID_KEY => [
        'label' => 'Synthetic Google OAuth client ID',
        'env' => $clientIdVariable,
      ],
      GoogleCalendar::CLIENT_SECRET_KEY => [
        'label' => 'Synthetic Google OAuth client secret',
        'env' => $clientSecretVariable,
      ],
    ] as $id => $definition) {
      Key::create([
        'id' => $id,
        'label' => $definition['label'],
        'description' => 'Synthetic Kernel-only credential.',
        'key_type' => 'authentication',
        'key_type_settings' => [],
        'key_provider' => 'env',
        'key_provider_settings' => [
          'env_variable' => $definition['env'],
          'base64_encoded' => FALSE,
          'strip_line_breaks' => TRUE,
        ],
        'key_input' => 'none',
        'key_input_settings' => [],
      ])->save();
    }
  }

  private function createSyntheticOauthClient(): void {
    $client = Oauth2Client::create([
      'id' => GoogleCalendar::PLUGIN_ID,
      'label' => 'Synthetic Google Calendar',
      'description' => 'Synthetic Kernel-only OAuth client.',
      'status' => TRUE,
      'oauth2_client_plugin_id' => GoogleCalendar::PLUGIN_ID,
      'credential_provider' => '',
      'credential_storage_key' => '',
    ]);
    $client->save();

    $this->assertTrue(
      $client->status(),
      'Synthetic OAuth client must match enabled production config.',
    );
  }

  private function createSyntheticUser(string $name): int {
    $user = $this->container
      ->get('entity_type.manager')
      ->getStorage('user')
      ->create([
        'name' => $name,
        'status' => 1,
      ]);

    $user->save();

    return (int) $user->id();
  }

  private function actAs(int $uid): void {
    $this->container
      ->get('current_user')
      ->setAccount(
        new UserSession([
          'uid' => $uid,
          'name' => 'synthetic-user-' . $uid,
          'roles' => ['authenticated'],
        ]),
      );
  }

}
