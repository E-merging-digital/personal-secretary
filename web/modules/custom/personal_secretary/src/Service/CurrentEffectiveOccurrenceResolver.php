<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\personal_secretary\Entity\ActivityException;
use Drupal\personal_secretary\Value\EffectiveOccurrence;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves one exact current EffectiveOccurrence by immutable target identity.
 */
final class CurrentEffectiveOccurrenceResolver {

  private const UTC_STORAGE_FORMAT = 'Y-m-d\\TH:i:s';

  public function __construct(
    private readonly OriginalOccurrenceTargetResolver $originalTargets,
    private readonly ActivityExceptionService $activityExceptions,
    private readonly EffectiveOccurrenceProjectionService $effectiveOccurrences,
  ) {}

  /**
   * @return array{series: \Drupal\personal_secretary\Entity\ActivitySeries, occurrence: \Drupal\personal_secretary\Value\EffectiveOccurrence}
   */
  public function resolve(int $seriesId, string $originalOccurrenceKey): array {
    $resolved = $this->originalTargets->resolve($seriesId, $originalOccurrenceKey);
    $series = $resolved['series'];
    $base = $resolved['occurrence'];

    $originalStart = new DateTimeImmutable($base->utcStart);
    $matches = $this->matchingEffectiveOccurrences(
      $series,
      $base->seriesRevisionId,
      $base->originalOccurrenceKey,
      $originalStart,
    );
    if (count($matches) === 1) {
      return ['series' => $series, 'occurrence' => $matches[0]];
    }
    if (count($matches) > 1) {
      throw new InvalidArgumentException('Multiple current EffectiveOccurrences conflict for the same target identity.');
    }

    $targetExceptions = array_values(array_filter(
      $this->activityExceptions->activeForSeries($series),
      static fn(ActivityException $exception): bool =>
        (string) $exception->get('target_revision_id')->value === (string) $base->seriesRevisionId
        && (string) $exception->get('original_occurrence_key')->value === $base->originalOccurrenceKey,
    ));
    if (count($targetExceptions) > 1) {
      throw new InvalidArgumentException('Multiple active ActivityExceptions conflict for the same target identity.');
    }
    if ($targetExceptions === []) {
      throw new InvalidArgumentException('Occurrence target has no current EffectiveOccurrence.');
    }

    $exception = $targetExceptions[0];
    $action = (string) $exception->get('action')->value;
    if ($action === ActivityException::ACTION_CANCEL) {
      throw new InvalidArgumentException('Cancelled occurrence target has no current EffectiveOccurrence.');
    }
    if ($action !== ActivityException::ACTION_RESCHEDULE) {
      throw new RuntimeException('Unknown active ActivityException action.');
    }

    $rescheduledStart = $this->fromStorage((string) $exception->get('rescheduled_utc_start')->value);
    $matches = $this->matchingEffectiveOccurrences(
      $series,
      $base->seriesRevisionId,
      $base->originalOccurrenceKey,
      $rescheduledStart,
    );
    if (count($matches) !== 1) {
      throw new InvalidArgumentException('Rescheduled target did not resolve to exactly one current EffectiveOccurrence.');
    }

    return ['series' => $series, 'occurrence' => $matches[0]];
  }

  /**
   * @return \Drupal\personal_secretary\Value\EffectiveOccurrence[]
   */
  private function matchingEffectiveOccurrences(
    \Drupal\personal_secretary\Entity\ActivitySeries $series,
    string $seriesRevisionId,
    string $originalOccurrenceKey,
    DateTimeImmutable $effectiveStart,
  ): array {
    $matches = $this->effectiveOccurrences->project(
      $series,
      $effectiveStart->modify('-1 second'),
      $effectiveStart->modify('+1 second'),
    );

    return array_values(array_filter(
      $matches,
      static fn(EffectiveOccurrence $candidate): bool =>
        (string) $candidate->seriesRevisionId === (string) $seriesRevisionId
        && $candidate->originalOccurrenceKey === $originalOccurrenceKey,
    ));
  }

  private function fromStorage(string $value): DateTimeImmutable {
    $parsed = DateTimeImmutable::createFromFormat(
      '!' . self::UTC_STORAGE_FORMAT,
      $value,
      new DateTimeZone('UTC'),
    );
    if (!$parsed instanceof DateTimeImmutable || $parsed->format(self::UTC_STORAGE_FORMAT) !== $value) {
      throw new RuntimeException('Stored rescheduled UTC datetime is invalid.');
    }
    return $parsed;
  }

}
