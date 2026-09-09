<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\personal_secretary\Entity\ActivityException;

/**
 * Reuses shared current-user authority before domain cancellation.
 */
final class CurrentUserOccurrenceCancellationService {

  public function __construct(
    private readonly CurrentUserOccurrenceAuthorityService $authority,
    private readonly CancelOccurrenceService $cancelOccurrence,
  ) {}

  /**
   * @return array{
   *   series: \Drupal\personal_secretary\Entity\ActivitySeries,
   *   occurrence: \Drupal\personal_secretary\Value\BaseOccurrence
   * }
   */
  public function authorize(int $seriesId, string $originalOccurrenceKey): array {
    return $this->authority->authorize($seriesId, $originalOccurrenceKey);
  }

  public function cancel(int $seriesId, string $originalOccurrenceKey): ActivityException {
    $this->authorize($seriesId, $originalOccurrenceKey);

    return $this->cancelOccurrence->cancel($seriesId, $originalOccurrenceKey);
  }

}
