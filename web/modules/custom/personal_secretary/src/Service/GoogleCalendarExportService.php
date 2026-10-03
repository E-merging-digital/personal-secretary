<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\personal_secretary\Entity\CalendarAccountConnection;
use Drupal\personal_secretary\Entity\GoogleCalendarProjection;

/**
 * Explicit manual create/update; mappings never confer product authority.
 */
final class GoogleCalendarExportService {

  public function __construct(
    private readonly GoogleCalendarProjectionResolver $resolver,
    private readonly GoogleCalendarConnectionService $connections,
    private readonly GoogleCalendarPayloadBuilder $payloads,
    private readonly GoogleCalendarProjectionRepository $mappings,
    private readonly GoogleCalendarEventTransport $transport,
  ) {}

  public function presentationState(int $seriesId, string $key): string {
    $resolved = $this->resolver->resolve($seriesId, $key);
    $connection = $this->connections->currentConnection();
    if ($connection === NULL || $connection->get('status')->value !== CalendarAccountConnection::STATUS_CONNECTED) {
      return 'NOT_CONNECTED';
    }
    $mapping = $this->mappings->find($resolved['series']->uuid(), $key);
    if ($mapping !== NULL) {
      try {
        $this->mappings->requireSubject($mapping, (string) $connection->get('provider_subject_id')->value);
      }
      catch (\InvalidArgumentException) {
        return 'CONFLICT';
      }
      if ($mapping->get('state')->value !== GoogleCalendarProjection::ACTIVE) {
        return (string) $mapping->get('state')->value;
      }
    }
    if (!$connection->hasWriteScope()) {
      // Incremental consent is only an add action; never recreate a mapping.
      return $mapping === NULL ? 'NO_WRITE_GRANT' : 'CONFLICT';
    }
    if ($mapping === NULL) {
      return 'NOT_EXPORTED';
    }
    return hash_equals((string) $mapping->get('payload_fingerprint')->value, $this->payloads->fingerprint($this->payloads->build($resolved))) ? 'CURRENT' : 'STALE';
  }

  public function create(int $seriesId, string $key): string {
    [$resolved, $subject] = $this->authorized($seriesId, $key);
    $uuid = $resolved['series']->uuid();
    if ($this->mappings->find($uuid, $key) !== NULL) {
      throw new \InvalidArgumentException('This occurrence already has a Google mapping.');
    }
    $payload = $this->payloads->build($resolved);
    $result = $this->transport->insert($this->payloads->eventId($uuid, $key), $payload);
    if ($result['status'] === 'SUCCESS') {
      $this->mappings->create($uuid, $key, $subject, $result['event_id'], $result['etag'], $this->payloads->fingerprint($payload));
    }
    return $result['status'];
  }

  public function update(int $seriesId, string $key): string {
    [$resolved, $subject] = $this->authorized($seriesId, $key);
    $mapping = $this->mappings->find($resolved['series']->uuid(), $key);
    if ($mapping === NULL || $mapping->get('state')->value !== GoogleCalendarProjection::ACTIVE) {
      throw new \InvalidArgumentException('Google update requires an active mapping.');
    }
    $this->mappings->requireSubject($mapping, $subject);
    $payload = $this->payloads->build($resolved);
    $fingerprint = $this->payloads->fingerprint($payload);
    if (hash_equals((string) $mapping->get('payload_fingerprint')->value, $fingerprint)) {
      return 'NOOP';
    }
    $result = $this->transport->update((string) $mapping->get('event_id')->value, (string) $mapping->get('etag')->value, $payload);
    if ($result['status'] === 'SUCCESS') {
      $this->mappings->updated($mapping, $subject, $result['etag'], $fingerprint);
    }
    elseif (in_array($result['status'], [GoogleCalendarProjection::CONFLICT, GoogleCalendarProjection::REMOTE_MISSING], TRUE)) {
      $this->mappings->mark($mapping, $subject, $result['status']);
    }
    return $result['status'];
  }

  private function authorized(int $seriesId, string $key): array {
    $resolved = $this->resolver->resolve($seriesId, $key);
    $connection = $this->connections->currentConnection();
    if ($connection === NULL || !$connection->hasWriteScope()) {
      throw new \InvalidArgumentException('Google export requires a connected write grant.');
    }
    return [$resolved, (string) $connection->get('provider_subject_id')->value];
  }

}
