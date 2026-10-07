<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\oauth2_client\Service\Oauth2ClientServiceInterface;
use Drupal\personal_secretary\Plugin\Oauth2Client\GoogleCalendar;
use GuzzleHttp\ClientInterface;
use League\OAuth2\Client\Token\AccessTokenInterface;

/**
 * Bounded read-only Google Events.list transport for the primary calendar.
 */
final class GoogleCalendarIngestionTransport {

  private const EVENTS_URI =
    'https://www.googleapis.com/calendar/v3/calendars/primary/events';

  private const FIELDS =
    'items(id,etag,status,summary,start,end,location,transparency,recurringEventId,originalStartTime,updated),nextPageToken,nextSyncToken';

  public function __construct(
    private readonly ?Oauth2ClientServiceInterface $oauth,
    private readonly ClientInterface $httpClient,
  ) {}

  /**
   * Reads one bounded bootstrap page from the primary calendar.
   *
   * @return array{items:array<int,array<string,mixed>>,next_page_token:?string,next_sync_token:?string,token_invalid:bool}
   *   Sanitized page payload and cursors.
   */
  public function bootstrapPage(
    \DateTimeImmutable $timeMin,
    \DateTimeImmutable $timeMax,
    ?string $pageToken = NULL,
  ): array {
    if ($timeMax <= $timeMin) {
      throw new \InvalidArgumentException('Google bootstrap window must be positive.');
    }

    $utc = new \DateTimeZone('UTC');
    $query = $this->baseQuery();
    $query['timeMin'] = $timeMin->setTimezone($utc)->format(\DateTimeInterface::RFC3339);
    $query['timeMax'] = $timeMax->setTimezone($utc)->format(\DateTimeInterface::RFC3339);
    if ($pageToken !== NULL) {
      $query['pageToken'] = $this->boundedToken($pageToken, 'page');
    }

    return $this->requestPage($query);
  }

  /**
   * Reads one incremental page using the exact persisted sync token.
   *
   * @return array{items:array<int,array<string,mixed>>,next_page_token:?string,next_sync_token:?string,token_invalid:bool}
   *   Sanitized page payload and cursors.
   */
  public function incrementalPage(
    string $syncToken,
    ?string $pageToken = NULL,
  ): array {
    $query = $this->baseQuery();
    $query['syncToken'] = $this->boundedToken($syncToken, 'sync');
    if ($pageToken !== NULL) {
      $query['pageToken'] = $this->boundedToken($pageToken, 'page');
    }

    return $this->requestPage($query);
  }

  /**
   * Returns the invariant bounded Google Events.list query shape.
   *
   * @return array<string, string|int>
   *   Provider query parameters shared by bootstrap and incremental reads.
   */
  private function baseQuery(): array {
    return [
      'singleEvents' => 'true',
      'showDeleted' => 'true',
      'eventTypes' => 'default',
      'maxResults' => 2500,
      'fields' => self::FIELDS,
    ];
  }

  /**
   * Executes one bounded, non-redirecting provider page request.
   *
   * @param array<string, string|int> $query
   *   Exact query parameters for this page.
   *
   * @return array{items:array<int,array<string,mixed>>,next_page_token:?string,next_sync_token:?string,token_invalid:bool}
   *   Sanitized page payload and cursor classification.
   */
  private function requestPage(array $query): array {
    if ($this->oauth === NULL) {
      throw new \RuntimeException('Google Calendar OAuth capability is unavailable.');
    }

    $token = $this->oauth->getAccessToken(GoogleCalendar::PLUGIN_ID, NULL);
    if (!$token instanceof AccessTokenInterface || $token->getToken() === ''
      || !$token->getExpires() || $token->hasExpired()) {
      throw new \RuntimeException('Google Calendar requires a valid access token.');
    }

    try {
      $response = $this->httpClient->request('GET', self::EVENTS_URI, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token->getToken(),
          'Accept' => 'application/json',
        ],
        'query' => $query,
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
        'timeout' => 10,
      ]);
    }
    catch (\Throwable $exception) {
      throw new \RuntimeException(
        'Google Calendar planning read failed at transport boundary.',
        0,
        $exception,
      );
    }

    if ($response->getStatusCode() === 410) {
      return [
        'items' => [],
        'next_page_token' => NULL,
        'next_sync_token' => NULL,
        'token_invalid' => TRUE,
      ];
    }
    if ($response->getStatusCode() !== 200) {
      throw new \RuntimeException(
        'Google Calendar planning read returned HTTP ' . $response->getStatusCode() . '.',
      );
    }

    try {
      $payload = json_decode(
        (string) $response->getBody(),
        TRUE,
        512,
        JSON_THROW_ON_ERROR,
      );
    }
    catch (\Throwable $exception) {
      throw new \RuntimeException(
        'Google Calendar planning response was malformed.',
        0,
        $exception,
      );
    }

    if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
      throw new \RuntimeException('Google Calendar planning response has invalid items.');
    }

    $items = [];
    foreach ($payload['items'] as $item) {
      if (!is_array($item)) {
        throw new \RuntimeException('Google Calendar planning item is malformed.');
      }
      $items[] = $item;
    }

    return [
      'items' => $items,
      'next_page_token' => $this->optionalToken($payload['nextPageToken'] ?? NULL),
      'next_sync_token' => $this->optionalToken($payload['nextSyncToken'] ?? NULL),
      'token_invalid' => FALSE,
    ];
  }

  /**
   * Normalizes an optional provider cursor token.
   */
  private function optionalToken(mixed $value): ?string {
    if ($value === NULL) {
      return NULL;
    }
    if (!is_string($value) || trim($value) === '') {
      throw new \RuntimeException('Google Calendar returned an invalid cursor token.');
    }
    return $this->boundedToken($value, 'provider');
  }

  /**
   * Validates one opaque bounded provider cursor token.
   */
  private function boundedToken(string $value, string $type): string {
    $value = trim($value);
    if ($value === '' || strlen($value) > 2048 || preg_match('/[\r\n]/', $value)) {
      throw new \InvalidArgumentException('Google Calendar ' . $type . ' token is invalid.');
    }
    return $value;
  }

}
