<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Value;

/**
 * Immutable projection of one calculated base occurrence.
 */
final readonly class BaseOccurrence {

  public function __construct(
    public string $seriesUuid,
    public string $seriesRevisionId,
    public string $originalOccurrenceKey,
    public string $utcStart,
    public string $utcEnd,
    public string $sourceLocalStart,
    public string $sourceLocalEnd,
    public string $sourceTimezone,
  ) {}

}
