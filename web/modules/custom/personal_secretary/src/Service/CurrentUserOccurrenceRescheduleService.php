<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use Drupal\personal_secretary\Entity\ActivityException;
use InvalidArgumentException;

/**
 * Authorizes current-user rescheduling before reusing the #61 engine.
 */
final class CurrentUserOccurrenceRescheduleService {

  public function __construct(
    private readonly CurrentUserOccurrenceAuthorityService $authority,
    private readonly RescheduleOccurrenceService $rescheduleOccurrence,
  ) {}

  /**
   * @return array{
   *   series: \Drupal\personal_secretary\Entity\ActivitySeries,
   *   occurrence: \Drupal\personal_secretary\Value\BaseOccurrence
   * }
   */
  public function authorize(int $seriesId, string $originalOccurrenceKey): array {
    $this->authority->authorize($seriesId, $originalOccurrenceKey);

    try {
      return $this->rescheduleOccurrence->resolve($seriesId, $originalOccurrenceKey);
    }
    catch (InvalidArgumentException $exception) {
      throw new InvalidArgumentException(
        'The requested occurrence is not currently authorized for rescheduling.',
        0,
        $exception,
      );
    }
  }

  public function reschedule(
    int $seriesId,
    string $originalOccurrenceKey,
    DateTimeImmutable $newLocalStart,
    DateTimeImmutable $newLocalEnd,
  ): ActivityException {
    $this->authorize($seriesId, $originalOccurrenceKey);

    return $this->rescheduleOccurrence->reschedule(
      $seriesId,
      $originalOccurrenceKey,
      $newLocalStart,
      $newLocalEnd,
    );
  }

}
