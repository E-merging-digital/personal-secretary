<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\personal_secretary\Entity\ActivityException;
use InvalidArgumentException;

/**
 * Reschedules one exact currently effective base occurrence.
 */
final class RescheduleOccurrenceService {

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

  public function reschedule(
    int $seriesId,
    string $originalOccurrenceKey,
    DateTimeImmutable $newLocalStart,
    DateTimeImmutable $newLocalEnd,
  ): ActivityException {
    $resolved = $this->targets->resolve($seriesId, $originalOccurrenceKey);
    $target = $resolved['occurrence'];
    if (
      $newLocalStart->getTimezone()->getName() !== $target->sourceTimezone
      || $newLocalEnd->getTimezone()->getName() !== $target->sourceTimezone
    ) {
      throw new InvalidArgumentException('Reschedule local datetimes must use the target source timezone.');
    }

    $utc = new DateTimeZone('UTC');

    return $this->activityExceptions->createReschedule(
      $resolved['series'],
      $target,
      $newLocalStart->setTimezone($utc),
      $newLocalEnd->setTimezone($utc),
      $target->sourceTimezone,
    );
  }

}
