<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\TimeCommitmentRule;
use Drupal\personal_secretary\Value\EffectiveOccurrence;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves explicit effective time commitment for one ActivitySeries instant.
 */
final class TimeCommitmentResolver {

  private const UTC_STORAGE_FORMAT = 'Y-m-d\\TH:i:s';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function resolve(
    ActivitySeries $series,
    EffectiveOccurrence $occurrence,
  ): ?TimeCommitmentRule {
    $this->requirePersistedSeries($series);
    if ($occurrence->seriesUuid !== $series->uuid()) {
      throw new InvalidArgumentException('EffectiveOccurrence does not belong to the requested ActivitySeries.');
    }

    return $this->resolveAt($series, new DateTimeImmutable($occurrence->effectiveUtcStart));
  }

  public function resolveAt(
    ActivitySeries $series,
    DateTimeImmutable $instant,
  ): ?TimeCommitmentRule {
    $this->requirePersistedSeries($series);
    $instant = $this->utc($instant);
    $matches = [];

    /** @var \Drupal\personal_secretary\Entity\TimeCommitmentRule[] $rules */
    $rules = $this->entityTypeManager
      ->getStorage(TimeCommitmentRule::ENTITY_TYPE_ID)
      ->loadByProperties(['series' => $series->id()]);

    foreach ($rules as $rule) {
      if (!$rule instanceof TimeCommitmentRule) {
        throw new RuntimeException('TimeCommitmentRule storage returned an unexpected entity type.');
      }
      if ((string) $rule->get('mode')->value !== TimeCommitmentRule::MODE_FULL_OCCURRENCE) {
        throw new RuntimeException('TimeCommitmentRule contains an unsupported mode.');
      }

      $start = $this->fromStorage((string) $rule->get('effective_from')->value);
      $end = NULL;
      if (!$rule->get('effective_until')->isEmpty()) {
        $end = $this->fromStorage((string) $rule->get('effective_until')->value);
        if ($end < $start) {
          throw new RuntimeException('TimeCommitmentRule effective interval is invalid.');
        }
      }

      if ($instant >= $start && ($end === NULL || $instant < $end)) {
        $matches[] = $rule;
      }
    }

    if (count($matches) > 1) {
      throw new RuntimeException('Multiple TimeCommitmentRules match the same EffectiveOccurrence instant.');
    }

    return $matches[0] ?? NULL;
  }

  private function requirePersistedSeries(ActivitySeries $series): ActivitySeries {
    if ($series->isNew() || $series->id() === NULL) {
      throw new InvalidArgumentException('Time commitment resolution requires a persisted ActivitySeries.');
    }
    $persisted = $this->entityTypeManager
      ->getStorage('personal_sec_activity_series')
      ->load($series->id());
    if (!$persisted instanceof ActivitySeries || $persisted->uuid() !== $series->uuid()) {
      throw new InvalidArgumentException('Time commitment resolution requires the current persisted ActivitySeries identity.');
    }
    return $persisted;
  }

  private function fromStorage(string $value): DateTimeImmutable {
    $parsed = DateTimeImmutable::createFromFormat(
      '!' . self::UTC_STORAGE_FORMAT,
      $value,
      new DateTimeZone('UTC'),
    );
    if (!$parsed instanceof DateTimeImmutable || $parsed->format(self::UTC_STORAGE_FORMAT) !== $value) {
      throw new RuntimeException('Stored TimeCommitmentRule UTC datetime is invalid.');
    }
    return $parsed;
  }

  private function utc(DateTimeImmutable $value): DateTimeImmutable {
    return $value->setTimezone(new DateTimeZone('UTC'));
  }

}
