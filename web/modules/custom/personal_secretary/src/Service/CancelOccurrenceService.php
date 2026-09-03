<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\personal_secretary\Entity\ActivityException;

/**
 * Cancels one exact currently effective base occurrence.
 */
final class CancelOccurrenceService {

  public function __construct(
    private readonly OccurrenceTargetResolver $targets,
    private readonly ActivityExceptionService $activityExceptions,
  ) {}

  /**
   * @return array{series: \Drupal\personal_secretary\Entity\ActivitySeries, occurrence: \Drupal\personal_secretary\Value\BaseOccurrence}
   */
  public function resolve(int $seriesId, string $originalOccurrenceKey): array {
    return $this->targets->resolve($seriesId, $originalOccurrenceKey);
  }

  public function cancel(int $seriesId, string $originalOccurrenceKey): ActivityException {
    $resolved = $this->targets->resolve($seriesId, $originalOccurrenceKey);

    return $this->activityExceptions->createCancel(
      $resolved['series'],
      $resolved['occurrence'],
    );
  }

}
