<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\oauth2_client\Service\Oauth2ClientServiceInterface;
use Drupal\personal_secretary\Plugin\Oauth2Client\GoogleCalendar;
use GuzzleHttp\ClientInterface;
use League\OAuth2\Client\Token\AccessTokenInterface;

/**
 * One bounded Events request, with no retries or redirected credentials.
 */
final class GoogleCalendarEventTransport {

  private const EVENTS_URI = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

  public function __construct(
    private readonly ?Oauth2ClientServiceInterface $oauth,
    private readonly ClientInterface $httpClient,
  ) {}

  public function insert(string $eventId, array $payload): array {
    return $this->request('POST', $eventId, $payload);
  }

  public function update(string $eventId, string $etag, array $payload): array {
    if ($etag === '' || $etag === '*' || preg_match('/[\r\n]/', $etag)) {
      throw new \InvalidArgumentException('Google update requires a stored ETag.');
    }
    // Explicitly clear an owned location removed from the current series.
    $payload += ['location' => NULL];
    return $this->request('PATCH', $eventId, $payload, $etag);
  }

  private function request(string $method, string $eventId, array $payload, ?string $etag = NULL): array {
    if (!preg_match('/^ps[0-9a-f]{64}$/D', $eventId)
      || array_diff(array_keys($payload), ['summary', 'location', 'start', 'end']) !== []) {
      throw new \InvalidArgumentException('Invalid Google owned event payload.');
    }
    if ($this->oauth === NULL) {
      throw new \\RuntimeException('Google Calendar OAuth capability is unavailable.');
    }
    $token = $this->oauth->getAccessToken(GoogleCalendar::PLUGIN_ID, NULL);
    if (!$token instanceof AccessTokenInterface || $token->getToken() === ''
      || !$token->getExpires() || $token->hasExpired()) {
      throw new \RuntimeException('Google Calendar requires a valid access token.');
    }
    $headers = ['Authorization' => 'Bearer ' . $token->getToken(), 'Accept' => 'application/json'];
    if ($etag !== NULL) {
      $headers['If-Match'] = $etag;
    }
    else {
      $payload['id'] = $eventId;
    }
    $response = $this->httpClient->request($method, self::EVENTS_URI . ($etag === NULL ? '' : '/' . rawurlencode($eventId)), [
      'headers' => $headers, 'json' => $payload, 'http_errors' => FALSE,
      'allow_redirects' => FALSE, 'timeout' => 10,
    ]);
    $status = $response->getStatusCode();
    if (($method === 'POST' && $status >= 200 && $status < 300) || ($method === 'PATCH' && $status === 200)) {
      $data = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
      $newEtag = is_array($data) ? ($data['etag'] ?? NULL) : NULL;
      if (!is_string($newEtag) || $newEtag === '' || $newEtag === '*' || strlen($newEtag) > 255 || preg_match('/[\r\n]/', $newEtag)
        || ($method === 'POST' && ($data['id'] ?? NULL) !== $eventId)) {
        throw new \RuntimeException('Google returned invalid event metadata.');
      }
      return ['status' => 'SUCCESS', 'event_id' => $eventId, 'etag' => $newEtag];
    }
    return ['status' => match (TRUE) {
      $method === 'POST' && $status === 409 => 'DUPLICATE',
      $method === 'PATCH' && $status === 412 => 'CONFLICT',
      $method === 'PATCH' && $status === 404 => 'REMOTE_MISSING',
      default => 'FAILED',
    }];
  }

}
