<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\OAuth2;

use League\OAuth2\Client\Provider\GenericProvider;

/**
 * Adds Google's required offline authorization parameters.
 */
final class GoogleCalendarProvider extends GenericProvider {

  /**
   * {@inheritdoc}
   */
  protected function getAuthorizationParameters(array $options) {
    $options['access_type'] = 'offline';
    $options['prompt'] = 'consent';

    // Google incremental authorization is deliberately not enabled. The
    // requested scope set therefore remains the exact #84 scope boundary.
    unset($options['include_granted_scopes']);

    return parent::getAuthorizationParameters($options);
  }

}
