<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Plugin\Oauth2GrantType;

use Drupal\oauth2_client\Plugin\Oauth2Client\Oauth2ClientPluginInterface;
use Drupal\oauth2_client\Plugin\Oauth2GrantType\RefreshToken;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessTokenInterface;
use League\OAuth2\Client\Token\SettableRefreshTokenInterface;

/**
 * Preserves Google's existing refresh token when refresh omits replacement.
 */
final class GoogleRefreshToken extends RefreshToken {

  /**
   * {@inheritdoc}
   */
  public function getAccessToken(
    Oauth2ClientPluginInterface $clientPlugin,
  ): ?AccessTokenInterface {
    $existingToken = $clientPlugin->retrieveAccessToken();
    if (!$existingToken instanceof AccessTokenInterface) {
      return NULL;
    }

    $existingRefreshToken = $existingToken->getRefreshToken();
    if (
      !is_string($existingRefreshToken)
      || $existingRefreshToken === ''
    ) {
      return NULL;
    }

    try {
      $newAccessToken = $clientPlugin
        ->getProvider()
        ->getAccessToken(
          'refresh_token',
          ['refresh_token' => $existingRefreshToken],
        );
    }
    catch (IdentityProviderException) {
      return NULL;
    }

    if (empty($newAccessToken->getRefreshToken())) {
      if (!$newAccessToken instanceof SettableRefreshTokenInterface) {
        throw new \LogicException(
          'OAuth token cannot preserve the existing Google refresh token.',
        );
      }

      $newAccessToken->setRefreshToken($existingRefreshToken);
    }

    $clientPlugin->storeAccessToken($newAccessToken);

    return $newAccessToken;
  }

}
