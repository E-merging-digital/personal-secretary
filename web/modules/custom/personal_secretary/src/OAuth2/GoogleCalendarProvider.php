<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\OAuth2;

use Drupal\personal_secretary\Entity\CalendarAccountConnection;
use League\OAuth2\Client\Provider\GenericProvider;

/**
 * Google offline consent with an explicit, bounded incremental-write path.
 */
final class GoogleCalendarProvider extends GenericProvider {

  public function __construct(array $options = [], array $collaborators = [], private readonly bool $incremental = FALSE) {
    $expected = $incremental ? CalendarAccountConnection::incrementalWriteScopes() : CalendarAccountConnection::connectionScopes();
    $scopes = $options['scopes'] ?? $expected;
    sort($scopes, SORT_STRING);
    if ($scopes !== $expected) {
      throw new \InvalidArgumentException('Google authorization requires exact explicit scopes.');
    }
    $options['scopes'] = $expected;
    parent::__construct($options, $collaborators);
  }

  protected function getAuthorizationParameters(array $options) {
    $options['access_type'] = 'offline';
    $options['prompt'] = 'consent';
    $options['scope'] = $this->incremental ? CalendarAccountConnection::incrementalWriteScopes() : CalendarAccountConnection::connectionScopes();
    unset($options['include_granted_scopes']);
    if ($this->incremental) {
      $options['include_granted_scopes'] = 'true';
    }
    $options = parent::getAuthorizationParameters($options);
    unset($options['approval_prompt']);

    return $options;
  }

}
