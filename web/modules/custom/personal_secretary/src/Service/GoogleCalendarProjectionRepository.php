<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\personal_secretary\Entity\GoogleCalendarProjection;

/**
 * Current-User-only mapping persistence; no caller-supplied owner is accepted.
 */
final class GoogleCalendarProjectionRepository {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  public function find(string $uuid, string $key): ?GoogleCalendarProjection {
    $storage = $this->entityTypeManager->getStorage(GoogleCalendarProjection::ENTITY_TYPE_ID);
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('owner_user', $this->uid())->condition('provider', 'google')
      ->condition('series_uuid', $uuid)->condition('original_occurrence_key', $key)
      ->range(0, 2)->execute();
    if (count($ids) > 1) {
      throw new \LogicException('Duplicate Google occurrence mapping.');
    }
    $storage->resetCache($ids);
    $mapping = $ids === [] ? NULL : $storage->load(reset($ids));
    return $mapping instanceof GoogleCalendarProjection ? $mapping : NULL;
  }

  public function create(string $uuid, string $key, string $subject, string $eventId, string $etag, string $providerLink, string $fingerprint): GoogleCalendarProjection {
    $mapping = $this->entityTypeManager->getStorage(GoogleCalendarProjection::ENTITY_TYPE_ID)->create([
      'owner_user' => $this->uid(), 'provider' => 'google', 'target' => 'primary',
      'series_uuid' => $uuid, 'original_occurrence_key' => $key,
      'provider_subject_id' => $subject, 'event_id' => $eventId, 'etag' => $etag,
      'provider_link' => $providerLink, 'payload_fingerprint' => $fingerprint, 'state' => GoogleCalendarProjection::ACTIVE,
    ]);
    $mapping->save();
    return $mapping;
  }

  public function requireSubject(GoogleCalendarProjection $mapping, string $subject): void {
    if ((int) $mapping->get('owner_user')->target_id !== $this->uid()
      || !hash_equals((string) $mapping->get('provider_subject_id')->value, $subject)) {
      throw new \InvalidArgumentException('Google mapping owner or subject mismatch.');
    }
  }

  public function updated(GoogleCalendarProjection $mapping, string $subject, string $etag, string $providerLink, string $fingerprint): void {
    $this->requireSubject($mapping, $subject);
    $mapping->set('etag', $etag)->set('provider_link', $providerLink)->set('payload_fingerprint', $fingerprint)
      ->set('state', GoogleCalendarProjection::ACTIVE)->save();
  }

  public function mark(GoogleCalendarProjection $mapping, string $subject, string $state): void {
    $this->requireSubject($mapping, $subject);
    if (!in_array($state, [GoogleCalendarProjection::CONFLICT, GoogleCalendarProjection::REMOTE_MISSING], TRUE)) {
      throw new \InvalidArgumentException('Invalid Google failure state.');
    }
    $mapping->set('state', $state)->save();
  }

  private function uid(): int {
    $uid = (int) $this->currentUser->id();
    if ($uid <= 0 || !$this->currentUser->isAuthenticated()) {
      throw new \LogicException('Google mapping requires an authenticated User.');
    }
    return $uid;
  }

}
