<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use Drupal\personal_secretary\Value\EffectiveOccurrence;
use InvalidArgumentException;

/**
 * Resolves one exact currently effective unmodified base occurrence.
 */
final class OccurrenceTargetResolver {

  public function __construct(
    private readonly OriginalOccurrenceTargetResolver $originalTargets,
    private readonly EffectiveOccurrenceProjectionService $effectiveOccurrences,
  ) {}

  /**
   * @return array{series: \Drupal\personal_secretary\Entity\ActivitySeries, occurrence: \Drupal\personal_secretary\Value\BaseOccurrence}
   */
  public function resolve(int $seriesId, string $originalOccurrenceKey): array {
    $resolved = $this->originalTargets->resolve($seriesId, $originalOccurrenceKey);
    $series = $resolved['series'];
    $target = $resolved['occurrence'];
    $instant = new DateTimeImmutable($target->utcStart);

    $effectiveMatches = array_values(array_filter(
      $this->effectiveOccurrences->project(
        $series,
        $instant->modify('-1 second'),
        $instant->modify('+1 second'),
      ),
      static fn(EffectiveOccurrence $candidate): bool =>
        (string) $candidate->seriesRevisionId === (string) $target->seriesRevisionId
        && $candidate->originalOccurrenceKey === $target->originalOccurrenceKey,
    ));
    if (count($effectiveMatches) !== 1 || $effectiveMatches[0]->exceptionUuid !== NULL) {
      throw new InvalidArgumentException('Occurrence target is no longer an unmodified effective base occurrence.');
    }

    return $resolved;
  }

}
