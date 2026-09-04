<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\Person;
use Drupal\personal_secretary\Value\CalendarProjectionCandidate;
use Drupal\personal_secretary\Value\EffectiveOccurrence;
use Drupal\personal_secretary\Value\EffectiveResponsibility;
use InvalidArgumentException;
use RuntimeException;

/**
 * Determines provider-agnostic calendar eligibility from domain truth only.
 */
final class CalendarEligibilityService {

  public function __construct(
    private readonly EffectiveResponsibilityService $effectiveResponsibility,
    private readonly TimeCommitmentResolver $timeCommitment,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function evaluate(
    ActivitySeries $series,
    EffectiveOccurrence $occurrence,
    Person $currentPerson,
  ): ?CalendarProjectionCandidate {
    if ($currentPerson->isNew() || $currentPerson->id() === NULL) {
      throw new InvalidArgumentException('Calendar eligibility requires a persisted CurrentPerson.');
    }

    $responsibility = $this->effectiveResponsibility->resolve($series, $occurrence);
    if (
      $responsibility->state !== EffectiveResponsibility::STATE_ASSIGNED
      || $responsibility->responsiblePersonId !== (int) $currentPerson->id()
    ) {
      return NULL;
    }

    $commitment = $this->timeCommitment->resolve($series, $occurrence);
    if ($commitment === NULL) {
      return NULL;
    }

    $seriesId = $series->id();
    $ruleId = $commitment->id();
    $ruleRevisionId = $commitment->getRevisionId();
    if ($seriesId === NULL || $ruleId === NULL || $ruleRevisionId === NULL) {
      throw new RuntimeException('Calendar eligibility source identities must be persisted.');
    }

    $seriesStorage = $this->entityTypeManager->getStorage('personal_sec_activity_series');
    if (!$seriesStorage instanceof RevisionableStorageInterface) {
      throw new RuntimeException('ActivitySeries storage must support revisions.');
    }
    $governingSeries = $seriesStorage->loadRevision((int) $occurrence->seriesRevisionId);
    if (
      !$governingSeries instanceof ActivitySeries
      || (int) $governingSeries->id() !== (int) $seriesId
      || $governingSeries->uuid() !== $occurrence->seriesUuid
    ) {
      throw new RuntimeException('Calendar projection candidate cannot resolve its governing ActivitySeries revision.');
    }
    $activityLabel = trim((string) $governingSeries->label());
    if ($activityLabel === '') {
      throw new RuntimeException('Calendar projection candidate requires an ActivitySeries label.');
    }

    return new CalendarProjectionCandidate(
      seriesId: (int) $seriesId,
      seriesUuid: $occurrence->seriesUuid,
      seriesRevisionId: $occurrence->seriesRevisionId,
      originalOccurrenceKey: $occurrence->originalOccurrenceKey,
      originalUtcStart: $occurrence->originalUtcStart,
      originalUtcEnd: $occurrence->originalUtcEnd,
      originalSourceLocalStart: $occurrence->originalSourceLocalStart,
      originalSourceLocalEnd: $occurrence->originalSourceLocalEnd,
      sourceTimezone: $occurrence->sourceTimezone,
      effectiveUtcStart: $occurrence->effectiveUtcStart,
      effectiveUtcEnd: $occurrence->effectiveUtcEnd,
      effectiveSourceLocalStart: $occurrence->effectiveSourceLocalStart,
      effectiveSourceLocalEnd: $occurrence->effectiveSourceLocalEnd,
      currentPersonId: (int) $currentPerson->id(),
      currentPersonUuid: $currentPerson->uuid(),
      activityLabel: $activityLabel,
      timeCommitmentRuleId: (int) $ruleId,
      timeCommitmentRuleRevisionId: (string) $ruleRevisionId,
    );
  }

}
