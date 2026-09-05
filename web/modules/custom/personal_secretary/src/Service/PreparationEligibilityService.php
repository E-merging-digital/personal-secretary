<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\PreparationRequirement;
use Drupal\personal_secretary\Value\EffectiveOccurrence;
use Drupal\personal_secretary\Value\EffectiveResponsibility;
use Drupal\personal_secretary\Value\PreparationEligibility;
use InvalidArgumentException;
use RuntimeException;

/**
 * Derives preparation eligibility from current occurrence and responsibility.
 */
final class PreparationEligibilityService {

  private const UTC_STORAGE_FORMAT = 'Y-m-d\\TH:i:s';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EffectiveResponsibilityService $effectiveResponsibility,
  ) {}

  /**
   * @return \Drupal\personal_secretary\Value\PreparationEligibility[]
   */
  public function derive(
    ActivitySeries $series,
    EffectiveOccurrence $occurrence,
  ): array {
    return $this->deriveForResponsibility(
      $series,
      $occurrence,
      $this->effectiveResponsibility->resolve($series, $occurrence),
    );
  }

  /**
   * Derives preparation eligibility from already-resolved effective truth.
   *
   * @return \Drupal\personal_secretary\Value\PreparationEligibility[]
   */
  public function deriveForResponsibility(
    ActivitySeries $series,
    EffectiveOccurrence $occurrence,
    EffectiveResponsibility $responsibility,
  ): array {
    if ($responsibility->state === EffectiveResponsibility::STATE_NONE) {
      return [];
    }
    if (
      $responsibility->state !== EffectiveResponsibility::STATE_ASSIGNED
      || $responsibility->responsiblePersonId === NULL
      || $responsibility->responsiblePersonUuid === NULL
    ) {
      throw new RuntimeException('Preparation eligibility requires a valid assigned EffectiveResponsibility.');
    }

    /** @var \Drupal\personal_secretary\Entity\PreparationRequirement[] $requirements */
    $requirements = $this->entityTypeManager
      ->getStorage('personal_sec_prep_req')
      ->loadByProperties(['series' => $series->id()]);
    usort(
      $requirements,
      static fn(PreparationRequirement $left, PreparationRequirement $right): int => (int) $left->id() <=> (int) $right->id(),
    );

    $targetStart = $this->utc(new DateTimeImmutable($occurrence->effectiveUtcStart));
    $results = [];
    foreach ($requirements as $requirement) {
      if (!$this->applies($requirement, $targetStart)) {
        continue;
      }

      $label = trim((string) $requirement->get('label')->value);
      $leadTimeSeconds = (int) $requirement->get('lead_time_seconds')->value;
      if ($label === '') {
        throw new RuntimeException('PreparationRequirement has no preparation instruction.');
      }
      if ($leadTimeSeconds < 0) {
        throw new RuntimeException('PreparationRequirement lead time must be zero or greater.');
      }

      $results[] = new PreparationEligibility(
        requirementId: (int) $requirement->id(),
        requirementRevisionId: (string) $requirement->getRevisionId(),
        requirementLabel: $label,
        seriesUuid: $occurrence->seriesUuid,
        seriesRevisionId: $occurrence->seriesRevisionId,
        originalOccurrenceKey: $occurrence->originalOccurrenceKey,
        responsiblePersonId: $responsibility->responsiblePersonId,
        responsiblePersonUuid: $responsibility->responsiblePersonUuid,
        effectiveUtcStart: $occurrence->effectiveUtcStart,
        effectiveUtcEnd: $occurrence->effectiveUtcEnd,
        effectiveSourceLocalStart: $occurrence->effectiveSourceLocalStart,
        effectiveSourceLocalEnd: $occurrence->effectiveSourceLocalEnd,
        leadTimeSeconds: $leadTimeSeconds,
        dueAtUtc: $targetStart->modify(sprintf('-%d seconds', $leadTimeSeconds))->format(DATE_ATOM),
      );
    }

    return $results;
  }

  /**
   * Returns the maximum current persisted lead time for an already-scoped set.
   *
   * PreparationRequirement entities are queried by authorized ActivitySeries IDs
   * before loading. NULL means no current requirements exist in that scope.
   *
   * @param array<int, int|string> $seriesIds
   */
  public function maximumLeadTimeSecondsForSeriesIds(array $seriesIds): ?int {
    $normalized = [];
    foreach ($seriesIds as $value) {
      if (is_string($value) && ctype_digit($value)) {
        $value = (int) $value;
      }
      if (!is_int($value) || $value <= 0) {
        throw new InvalidArgumentException('Preparation lead-time discovery requires positive ActivitySeries IDs.');
      }
      $normalized[$value] = $value;
    }
    if ($normalized === []) {
      return NULL;
    }

    $normalized = array_values($normalized);
    sort($normalized, SORT_NUMERIC);

    $storage = $this->entityTypeManager->getStorage('personal_sec_prep_req');
    $requirementIds = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('series', $normalized, 'IN')
      ->execute();
    if ($requirementIds === []) {
      return NULL;
    }

    $requirements = $storage->loadMultiple($requirementIds);
    $maximum = 0;
    $found = FALSE;
    foreach ($requirementIds as $requirementId) {
      $requirement = $requirements[$requirementId] ?? NULL;
      if (!$requirement instanceof PreparationRequirement) {
        throw new RuntimeException('Preparation lead-time query returned an unexpected entity type.');
      }
      $seriesId = (int) ($requirement->get('series')->target_id ?? 0);
      if (!in_array($seriesId, $normalized, TRUE)) {
        throw new RuntimeException('Preparation lead-time discovery crossed its authorized ActivitySeries boundary.');
      }
      $leadTimeSeconds = (int) $requirement->get('lead_time_seconds')->value;
      if ($leadTimeSeconds < 0) {
        throw new RuntimeException('PreparationRequirement lead time must be zero or greater.');
      }
      $maximum = max($maximum, $leadTimeSeconds);
      $found = TRUE;
    }

    return $found ? $maximum : NULL;
  }

  private function applies(
    PreparationRequirement $requirement,
    DateTimeImmutable $targetStart,
  ): bool {
    if ($requirement->get('effective_from')->isEmpty()) {
      throw new RuntimeException('PreparationRequirement has no effective-from value.');
    }
    $effectiveFrom = $this->fromStorage((string) $requirement->get('effective_from')->value);

    $effectiveUntil = NULL;
    if (!$requirement->get('effective_until')->isEmpty()) {
      $effectiveUntil = $this->fromStorage((string) $requirement->get('effective_until')->value);
      if ($effectiveUntil <= $effectiveFrom) {
        throw new RuntimeException('PreparationRequirement effective window is invalid.');
      }
    }

    return $targetStart >= $effectiveFrom
      && ($effectiveUntil === NULL || $targetStart < $effectiveUntil);
  }

  private function fromStorage(string $value): DateTimeImmutable {
    $parsed = DateTimeImmutable::createFromFormat(
      '!' . self::UTC_STORAGE_FORMAT,
      $value,
      new DateTimeZone('UTC'),
    );
    if (!$parsed instanceof DateTimeImmutable) {
      throw new RuntimeException('Stored preparation UTC datetime is invalid.');
    }
    return $parsed;
  }

  private function utc(DateTimeImmutable $value): DateTimeImmutable {
    return $value->setTimezone(new DateTimeZone('UTC'));
  }

}
