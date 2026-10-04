<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Value\CalendarProjectionCandidate;

/**
 * Re-derives write eligibility from current product authority on every action.
 */
final class GoogleCalendarProjectionResolver {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly CurrentPersonResolver $currentPerson,
    private readonly HouseholdAuthorizationService $households,
    private readonly CurrentEffectiveOccurrenceResolver $occurrences,
    private readonly CalendarEligibilityService $eligibility,
    private readonly OccurrenceProjectionService $projection,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns the current series, governing revision, occurrence and candidate.
   */
  public function resolve(int $seriesId, string $key): array {
    if (!$this->currentUser->isAuthenticated() || !$this->currentUser->hasPermission('use personal secretary')) {
      throw new \InvalidArgumentException('Google export requires product access.');
    }
    $series = $this->entityTypeManager->getStorage('personal_sec_activity_series')->load($seriesId);
    if (!$series instanceof ActivitySeries) {
      throw new \InvalidArgumentException('Occurrence is unavailable.');
    }
    $this->households->requireAuthorized($this->currentUser, (int) $series->get('household')->target_id);
    $person = $this->currentPerson->resolve($this->currentUser);
    $resolved = $this->occurrences->resolve($seriesId, $key);
    $occurrence = $resolved['occurrence'];
    $candidate = $this->eligibility->evaluate($resolved['series'], $occurrence, $person);
    if (!$candidate instanceof CalendarProjectionCandidate) {
      throw new \InvalidArgumentException('This occurrence is not eligible for Google export.');
    }
    $revision = $this->entityTypeManager->getStorage('personal_sec_activity_series')->loadRevision((int) $occurrence->seriesRevisionId);
    if (!$revision instanceof ActivitySeries
      || (int) $revision->id() !== $seriesId
      || $revision->uuid() !== $occurrence->seriesUuid
      || count($this->projection->project($revision, limit: 2)) !== 1) {
      throw new \InvalidArgumentException('Google export requires a single non-recurring occurrence.');
    }
    return $resolved + ['revision' => $revision, 'candidate' => $candidate];
  }

}
