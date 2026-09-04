<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Value;

use Drupal\personal_secretary\Entity\TimeCommitmentRule;

/**
 * Immutable provider-agnostic candidate derived from governed domain truth.
 */
final readonly class CalendarProjectionCandidate {

  public const REASON_FULL_OCCURRENCE = TimeCommitmentRule::MODE_FULL_OCCURRENCE;

  public function __construct(
    public int $seriesId,
    public string $seriesUuid,
    public string $seriesRevisionId,
    public string $originalOccurrenceKey,
    public string $originalUtcStart,
    public string $originalUtcEnd,
    public string $originalSourceLocalStart,
    public string $originalSourceLocalEnd,
    public string $sourceTimezone,
    public string $effectiveUtcStart,
    public string $effectiveUtcEnd,
    public string $effectiveSourceLocalStart,
    public string $effectiveSourceLocalEnd,
    public int $currentPersonId,
    public string $currentPersonUuid,
    public string $activityLabel,
    public int $timeCommitmentRuleId,
    public string $timeCommitmentRuleRevisionId,
    public string $reason = self::REASON_FULL_OCCURRENCE,
  ) {}

}
