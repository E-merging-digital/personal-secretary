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
    $responsibility = $this->effectiveResponsibility->resolve($series, $occurrence);
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
