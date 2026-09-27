<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Plugin\Oauth2Client;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\easy_encryption\Encryption\EncryptedValue;
use Drupal\easy_encryption\Encryption\EncryptorInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\oauth2_client\Attribute\Oauth2Client;
use Drupal\oauth2_client\Plugin\Oauth2Client\Oauth2ClientPluginAccessInterface;
use Drupal\oauth2_client\Plugin\Oauth2Client\Oauth2ClientPluginBase;
use Drupal\oauth2_client\Plugin\Oauth2Client\Oauth2ClientPluginInterface;
use Drupal\oauth2_client\Plugin\Oauth2Client\Oauth2ClientPluginRedirectInterface;
use Drupal\personal_secretary\Entity\CalendarAccountConnection;
use Drupal\personal_secretary\OAuth2\GoogleCalendarProvider;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;
use League\OAuth2\Client\Token\SettableRefreshTokenInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * User-owned Google Calendar OAuth client.
 */
#[Oauth2Client(
  id: self::PLUGIN_ID,
  name: new TranslatableMarkup('Google Calendar'),
  grant_type: 'authorization_code',
  authorization_uri: 'https://accounts.google.com/o/oauth2/v2/auth',
  token_uri: 'https://oauth2.googleapis.com/token',
  resource_owner_uri: 'https://openidconnect.googleapis.com/v1/userinfo',
  scopes: [
    'openid',
    'https://www.googleapis.com/auth/calendar.calendars.readonly',
  ],
  scope_separator: ' ',
  success_message: FALSE,
)]
final class GoogleCalendar extends Oauth2ClientPluginBase implements
  Oauth2ClientPluginAccessInterface,
  Oauth2ClientPluginRedirectInterface {

  public const PLUGIN_ID = 'personal_secretary_google_calendar';

  public const CLIENT_ID_KEY =
    'personal_secretary_google_oauth_client_id';

  public const CLIENT_SECRET_KEY =
    'personal_secretary_google_oauth_client_secret';

  private const TOKEN_STATE_PREFIX =
    'personal_secretary.google_calendar.oauth_token.';

  protected EncryptorInterface $encryptor;

  protected AccountProxyInterface $currentUser;

  protected KeyRepositoryInterface $keyRepository;

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): Oauth2ClientPluginInterface {
    $instance = parent::create(
      $container,
      $configuration,
      $plugin_id,
      $plugin_definition,
    );

    if (!$instance instanceof self) {
      throw new \LogicException('Unexpected Google Calendar OAuth plugin.');
    }

    $instance->encryptor = $container->get(EncryptorInterface::class);
    $instance->currentUser = $container->get('current_user');
    $instance->keyRepository = $container->get('key.repository');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getClientId(): string {
    return $this->keyValue(self::CLIENT_ID_KEY);
  }

  /**
   * {@inheritdoc}
   */
  public function getClientSecret(): string {
    return $this->keyValue(self::CLIENT_SECRET_KEY);
  }

  /**
   * {@inheritdoc}
   */
  public function getProvider(): AbstractProvider {
    return new GoogleCalendarProvider(
      [
        'clientId' => $this->getClientId(),
        'clientSecret' => $this->getClientSecret(),
        'redirectUri' => $this->getRedirectUri(),
        'urlAuthorize' => $this->getAuthorizationUri(),
        'urlAccessToken' => $this->getTokenUri(),
        'urlResourceOwnerDetails' => $this->getResourceUri(),
        'scopes' => $this->getScopes(),
        'scopeSeparator' => $this->getScopeSeparator(),
      ],
      $this->getCollaborators(),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function codeRouteAccess(
    AccountInterface $account,
  ): AccessResultInterface {
    return AccessResult::allowedIf(
      $account->isAuthenticated()
      && $account->hasPermission('use personal secretary'),
    )->addCacheContexts(['user', 'user.permissions']);
  }

  /**
   * {@inheritdoc}
   */
  public function getPostCaptureRedirect(): RedirectResponse {
    return new LocalRedirectResponse(
      Url::fromRoute(
        'personal_secretary.google_calendar_complete',
      )->toString(),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function storeAccessToken(
    AccessTokenInterface $accessToken,
  ): void {
    $uid = $this->currentUserId();

    $previousRefreshToken = NULL;
    $stored = $this->retrieveAccessToken();
    if ($stored instanceof AccessTokenInterface) {
      $previousRefreshToken = $stored->getRefreshToken();
    }

    if (
      empty($accessToken->getRefreshToken())
      && !empty($previousRefreshToken)
    ) {
      if (!$accessToken instanceof SettableRefreshTokenInterface) {
        throw new \LogicException(
          'OAuth token cannot preserve an existing refresh token.',
        );
      }

      $accessToken->setRefreshToken($previousRefreshToken);
    }

    $payloadValues = [
      'access_token' => $accessToken->getToken(),
    ];

    $refreshToken = $accessToken->getRefreshToken();
    if (is_string($refreshToken) && $refreshToken !== '') {
      $payloadValues['refresh_token'] = $refreshToken;
    }

    $expires = $accessToken->getExpires();
    if (is_int($expires) && $expires > 0) {
      $payloadValues['expires'] = $expires;
    }

    $values = method_exists($accessToken, 'getValues')
      ? $accessToken->getValues()
      : [];
    $scope = is_array($values) ? ($values['scope'] ?? NULL) : NULL;
    if (is_string($scope) && trim($scope) !== '') {
      $payloadValues['scope'] = trim($scope);
    }

    $payload = json_encode(
      $payloadValues,
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    );

    $encrypted = $this->encryptor->encrypt($payload);

    $this->state->set(
      self::tokenStateKey($uid),
      [
        'ciphertext' => $encrypted->getCiphertextHex(),
        'key_id' => $encrypted->keyId->value,
      ],
    );
  }

  /**
   * {@inheritdoc}
   */
  public function retrieveAccessToken(): ?AccessTokenInterface {
    $uid = $this->currentUserId();

    $stored = $this->state->get(self::tokenStateKey($uid));
    if (!is_array($stored)) {
      return NULL;
    }

    $ciphertext = $stored['ciphertext'] ?? NULL;
    $keyId = $stored['key_id'] ?? NULL;

    if (!is_string($ciphertext) || $ciphertext === '') {
      throw new \UnexpectedValueException(
        'Encrypted Google OAuth token ciphertext is invalid.',
      );
    }

    if (!is_string($keyId) || $keyId === '') {
      throw new \UnexpectedValueException(
        'Encrypted Google OAuth token key ID is invalid.',
      );
    }

    $plaintext = $this->encryptor->decrypt(
      EncryptedValue::fromHex($ciphertext, $keyId),
    );

    $payload = json_decode(
      $plaintext,
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );

    if (!is_array($payload)) {
      throw new \UnexpectedValueException(
        'Decrypted Google OAuth token payload is invalid.',
      );
    }

    return new AccessToken($payload);
  }

  /**
   * {@inheritdoc}
   */
  public function clearAccessToken(): void {
    $this->state->delete(
      self::tokenStateKey($this->currentUserId()),
    );
  }

  /**
   * Returns the ciphertext State key for one Drupal User.
   */
  public static function tokenStateKey(int $uid): string {
    if ($uid <= 0) {
      throw new \InvalidArgumentException(
        'Google OAuth token storage requires an authenticated User.',
      );
    }

    return self::TOKEN_STATE_PREFIX . $uid;
  }

  /**
   * Returns one Key-backed credential without persisting it.
   */
  private function keyValue(string $keyId): string {
    $key = $this->keyRepository->getKey($keyId);
    if ($key === NULL) {
      return '';
    }

    $value = $key->getKeyValue();
    return is_scalar($value) ? trim((string) $value) : '';
  }

  /**
   * Returns the current authenticated Drupal User ID.
   */
  private function currentUserId(): int {
    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      throw new \LogicException(
        'Google OAuth token storage requires an authenticated User.',
      );
    }

    return $uid;
  }

}
