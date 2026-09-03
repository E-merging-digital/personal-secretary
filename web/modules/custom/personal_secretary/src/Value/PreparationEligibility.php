<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Value;

/**
 * Immutable calculated eligible preparation for one requirement and occurrence.
 */
final readonly class PreparationEligibility {

  public function __construct(
    public int $requirementId,
    public string $requirementRevisionId,
    public string $requirementLabel,
    public string $seriesUuid,
    public string $seriesRevisionId,
    public string $originalOccurrenceKey,
    public int $responsiblePersonId,
    public string $responsiblePersonUuid,
    public string $effectiveUtcStart,
    public string $effectiveUtcEnd,
    public string $effectiveSourceLocalStart,
    public string $effectiveSourceLocalEnd,
    public int $leadTimeSeconds,
    public string $dueAtUtc,
  ) {}

}
