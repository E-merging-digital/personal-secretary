<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Value;

/**
 * Immutable calculated responsibility for one EffectiveOccurrence.
 */
final readonly class EffectiveResponsibility {

  public const STATE_ASSIGNED = 'assigned';
  public const STATE_NONE = 'none';

  public const SOURCE_OVERRIDE = 'override';
  public const SOURCE_RULE = 'rule';
  public const SOURCE_NONE = 'none';

  public function __construct(
    public string $state,
    public string $source,
    public ?int $responsiblePersonId,
    public ?string $responsiblePersonUuid,
    public ?int $ruleId,
    public ?string $ruleRevisionId,
    public ?int $overrideId,
    public ?string $overrideRevisionId,
    public string $seriesUuid,
    public string $seriesRevisionId,
    public string $originalOccurrenceKey,
    public string $effectiveUtcStart,
    public string $effectiveUtcEnd,
    public string $effectiveSourceLocalStart,
    public string $effectiveSourceLocalEnd,
  ) {}

}
