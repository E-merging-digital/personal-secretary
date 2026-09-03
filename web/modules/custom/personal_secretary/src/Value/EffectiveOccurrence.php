<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Value;

/**
 * Immutable effective occurrence after ActivityException overlay.
 */
final readonly class EffectiveOccurrence {

  public function __construct(
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
    public ?string $exceptionUuid = NULL,
    public ?string $exceptionRevisionId = NULL,
    public ?string $exceptionAction = NULL,
  ) {}

}
