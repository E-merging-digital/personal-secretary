<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Session\UserSession;
use Drupal\easy_encryption\Encryption\EncryptedValue;
use Drupal\easy_encryption\Encryption\EncryptorInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\oauth2_client\Plugin\Oauth2Client\Oauth2ClientPluginInterface;
use Drupal\personal_secretary\Entity\CalendarAccountConnection;
use Drupal\personal_secretary\Plugin\Oauth2Client\GoogleCalendar;
use Drupal\personal_secretary\Plugin\Oauth2GrantType\GoogleRefreshToken;
use Drupal\personal_secretary\Service\GoogleCalendarConnectionService;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves bounded User-owned Google Calendar connection contracts.
 *
 * @group personal_secretary
 */
#[RunTestsInSeparateProcesses]
final class GoogleCalendarConnectionKernelTest extends KernelTestBase {

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
  }

  public function testConnectionMetadataCardinalityAndUserIsolation(): void {
    $userA = $this->createSyntheticUser('synthetic-user-a');
    $userB = $this->createSyntheticUser('synthetic-user-b');

    $this->actAs($userA);

    /** @var \Drupal\personal_secretary\Service\GoogleCalendarConnectionService $service */
    $service = $this->container->get(
      'personal_secretary.google_calendar_connection',
    );
    $this->assertInstanceOf(
      GoogleCalendarConnectionService::class,
      $service,
    );

    $connectionA = $service->connect(
      'google-sub-a',
      CalendarAccountConnection::connectionScopes(),
    );

    $this->assertSame(
      CalendarAccountConnection::STATUS_CONNECTED,
      $service->currentState(),
    );
    $this->assertSame(
      (string) $userA,
      (string) $connectionA->get('owner_user')->target_id,
    );
    $this->assertSame(
      CalendarAccountConnection::PROVIDER_GOOGLE,
      (string) $connectionA->get('provider_key')->value,
    );
    $this->assertSame(
      'google-sub-a',
      (string) $connectionA->get('provider_subject_id')->value,
    );

    $storedScopes = [];
    foreach ($connectionA->get('scopes') as $item) {
      $storedScopes[] = (string) $item->value;
    }
    sort($storedScopes, SORT_STRING);

    $this->assertSame(
      CalendarAccountConnection::connectionScopes(),
      $storedScopes,
    );

    foreach ([
      'email',
      'provider_email',
      'display_name',
      'profile',
      'calendar_id',
      'access_token',
      'refresh_token',
      'event_id',
      'person',
    ] as $forbiddenField) {
      $this->assertFalse(
        $connectionA->hasField($forbiddenField),
        sprintf(
          'Forbidden durable field "%s" must not exist.',
          $forbiddenField,
        ),
      );
    }

    $storage = $this->container
      ->get('entity_type.manager')
      ->getStorage(CalendarAccountConnection::ENTITY_TYPE_ID);

    $duplicate = $storage->create([
      'owner_user' => $userA,
      'provider_key' => CalendarAccountConnection::PROVIDER_GOOGLE,
      'provider_subject_id' => 'duplicate-sub',
      'scopes' => array_map(
        static fn(string $scope): array => ['value' => $scope],
        CalendarAccountConnection::connectionScopes(),
      ),
      'status' => CalendarAccountConnection::STATUS_CONNECTED,
      'connected_at' => 1_700_000_000,
    ]);

    try {
      $duplicate->save();
      $this->fail(
        'A second Google Calendar connection for one User must fail.',
      );
    }
    catch (EntityStorageException $exception) {
      $this->assertStringContainsString(
        'Only one Google Calendar connection',
        $exception->getMessage(),
      );
    }

    $this->actAs($userB);

    $this->assertSame(
      GoogleCalendarConnectionService::STATE_NOT_CONNECTED,
      $service->currentState(),
    );
    $this->assertNull($service->currentConnection());

    $connectionB = $service->connect(
      'google-sub-b',
      CalendarAccountConnection::connectionScopes(),
    );

    $this->assertNotSame(
      (string) $connectionA->id(),
      (string) $connectionB->id(),
    );

    $this->actAs($userA);

    $loadedA = $service->currentConnection();
    $this->assertNotNull($loadedA);
    $this->assertSame(
      'google-sub-a',
      (string) $loadedA->get('provider_subject_id')->value,
    );

    $service->markInvalid();
    $this->assertSame(
      CalendarAccountConnection::STATUS_INVALID,
      $service->currentState(),
    );

    $reconnectedA = $service->connect(
      'google-sub-a-reconnected',
      CalendarAccountConnection::connectionScopes(),
    );

    $this->assertSame(
      (string) $connectionA->id(),
      (string) $reconnectedA->id(),
    );
    $this->assertSame(
      CalendarAccountConnection::STATUS_CONNECTED,
      $service->currentState(),
    );

    $service->disconnectLocal();

    $this->assertSame(
      GoogleCalendarConnectionService::STATE_NOT_CONNECTED,
      $service->currentState(),
    );

    $this->actAs($userB);

    $stillConnectedB = $service->currentConnection();
    $this->assertNotNull($stillConnectedB);
    $this->assertSame(
      'google-sub-b',
      (string) $stillConnectedB
        ->get('provider_subject_id')
        ->value,
    );

    $this->assertCount(1, $storage->loadMultiple());
  }

  public function testEncryptedTokenStorageIsMinimalAndUserIsolated(): void {
    $userA = $this->createSyntheticUser('synthetic-token-user-a');
    $userB = $this->createSyntheticUser('synthetic-token-user-b');

    $this->actAs($userA);

    $plaintextByCiphertext = [];

    $encryptor = $this->createMock(EncryptorInterface::class);

    $encryptor
      ->method('encrypt')
      ->willReturnCallback(
        static function (
          string $plaintext,
        ) use (&$plaintextByCiphertext): EncryptedValue {
          $ciphertext = hash('sha256', $plaintext);
          $plaintextByCiphertext[$ciphertext] = $plaintext;

          return EncryptedValue::fromHex(
            $ciphertext,
            'synthetic_key',
          );
        },
      );

    $encryptor
      ->method('decrypt')
      ->willReturnCallback(
        static function (
          EncryptedValue $value,
        ) use (&$plaintextByCiphertext): string {
          $ciphertext = $value->getCiphertextHex();

          if (!isset($plaintextByCiphertext[$ciphertext])) {
            throw new \RuntimeException(
              'Synthetic ciphertext is unknown.',
            );
          }

          return $plaintextByCiphertext[$ciphertext];
        },
      );

    $manager = $this->container->get(
      'oauth2_client.plugin_manager',
    );

    $plugin = $manager->createInstance(
      GoogleCalendar::PLUGIN_ID,
      [
        'uuid' => 'synthetic-google-calendar-client',
        'credentials' => [],
      ],
    );

    $this->assertInstanceOf(GoogleCalendar::class, $plugin);

    $encryptorProperty = new \ReflectionProperty(
      GoogleCalendar::class,
      'encryptor',
    );
    $encryptorProperty->setValue($plugin, $encryptor);

    $initial = new AccessToken([
      'access_token' => 'access_1',
      'refresh_token' => 'refresh_R',
      'expires' => time() + 3600,
      'id_token' => 'synthetic-id-token-must-not-persist',
      'scope' => implode(
        ' ',
        CalendarAccountConnection::connectionScopes(),
      ),
    ]);

    $plugin->storeAccessToken($initial);

    $state = $this->container->get('state');
    $stateKey = GoogleCalendar::tokenStateKey($userA);
    $persisted = $state->get($stateKey);

    $this->assertIsArray($persisted);
    $this->assertArrayHasKey('ciphertext', $persisted);
    $this->assertArrayHasKey('key_id', $persisted);

    $serializedPersisted = json_encode(
      $persisted,
      JSON_THROW_ON_ERROR,
    );

    foreach ([
      'access_1',
      'refresh_R',
      'synthetic-id-token-must-not-persist',
    ] as $plaintextSecret) {
      $this->assertStringNotContainsString(
        $plaintextSecret,
        $serializedPersisted,
      );
    }

    $ciphertext = $persisted['ciphertext'];
    $this->assertIsString($ciphertext);
    $this->assertArrayHasKey($ciphertext, $plaintextByCiphertext);

    $decryptedPayload = json_decode(
      $plaintextByCiphertext[$ciphertext],
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );

    $this->assertIsArray($decryptedPayload);

    $payloadKeys = array_keys($decryptedPayload);
    sort($payloadKeys, SORT_STRING);

    $this->assertSame(
      [
        'access_token',
        'expires',
        'refresh_token',
        'scope',
      ],
      $payloadKeys,
    );

    $this->assertArrayNotHasKey('id_token', $decryptedPayload);
    $this->assertSame('access_1', $decryptedPayload['access_token']);
    $this->assertSame('refresh_R', $decryptedPayload['refresh_token']);
    $this->assertSame(
      implode(' ', CalendarAccountConnection::connectionScopes()),
      $decryptedPayload['scope'],
    );

    $retrieved = $plugin->retrieveAccessToken();
    $this->assertNotNull($retrieved);
    $this->assertSame('access_1', $retrieved->getToken());
    $this->assertSame('refresh_R', $retrieved->getRefreshToken());
    $this->assertNull(
      $retrieved->getValues()['id_token'] ?? NULL,
      'OIDC ID token must not be durably persisted.',
    );

    $this->actAs($userB);

    $this->assertNull(
      $plugin->retrieveAccessToken(),
      'User B must not retrieve User A OAuth token.',
    );

    $this->assertNotSame(
      GoogleCalendar::tokenStateKey($userA),
      GoogleCalendar::tokenStateKey($userB),
    );

    $this->actAs($userA);
    $plugin->clearAccessToken();

    $this->assertNull(
      $state->get($stateKey),
      'Local cleanup must remove token ciphertext.',
    );
  }

  public function testCustomRefreshGrantPreservesRefreshAcrossTwoCycles(): void {
    $currentToken = new AccessToken([
      'access_token' => 'access_1',
      'refresh_token' => 'refresh_R',
      'expires' => time() - 60,
    ]);

    $clientPlugin = $this->createMock(
      Oauth2ClientPluginInterface::class,
    );

    $provider = $this->createMock(AbstractProvider::class);

    $clientPlugin
      ->method('retrieveAccessToken')
      ->willReturnCallback(
        static function () use (&$currentToken): AccessTokenInterface {
          return $currentToken;
        },
      );

    $clientPlugin
      ->method('getProvider')
      ->willReturn($provider);

    $clientPlugin
      ->expects($this->exactly(2))
      ->method('storeAccessToken')
      ->willReturnCallback(
        static function (
          AccessTokenInterface $token,
        ) use (&$currentToken): void {
          $currentToken = $token;
        },
      );

    $refreshRequests = [];

    $provider
      ->expects($this->exactly(2))
      ->method('getAccessToken')
      ->willReturnCallback(
        static function (
          mixed $grant,
          array $options = [],
        ) use (&$refreshRequests): AccessTokenInterface {
          $refreshRequests[] = [
            'grant' => $grant,
            'refresh_token' => $options['refresh_token'] ?? NULL,
          ];

          $cycle = count($refreshRequests);

          return new AccessToken([
            'access_token' => 'access_' . ($cycle + 1),
            'expires' => time() + (3600 * $cycle),
          ]);
        },
      );

    $grantManager = $this->container->get(
      'plugin.manager.oauth2_grant_type',
    );
    $grantManager->clearCachedDefinitions();

    $grant = $grantManager->createInstance('refresh_token');

    $this->assertInstanceOf(GoogleRefreshToken::class, $grant);

    $firstRefresh = $grant->getAccessToken($clientPlugin);
    $this->assertNotNull($firstRefresh);
    $this->assertSame('access_2', $firstRefresh->getToken());
    $this->assertSame(
      'refresh_R',
      $firstRefresh->getRefreshToken(),
    );

    $secondRefresh = $grant->getAccessToken($clientPlugin);
    $this->assertNotNull($secondRefresh);
    $this->assertSame('access_3', $secondRefresh->getToken());
    $this->assertSame(
      'refresh_R',
      $secondRefresh->getRefreshToken(),
    );

    $this->assertSame(
      [
        [
          'grant' => 'refresh_token',
          'refresh_token' => 'refresh_R',
        ],
        [
          'grant' => 'refresh_token',
          'refresh_token' => 'refresh_R',
        ],
      ],
      $refreshRequests,
      'Both refresh cycles must consume the retained refresh_R.',
    );
  }

  private function createSyntheticUser(string $name): int {
    $storage = $this->container
      ->get('entity_type.manager')
      ->getStorage('user');

    $user = $storage->create([
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
