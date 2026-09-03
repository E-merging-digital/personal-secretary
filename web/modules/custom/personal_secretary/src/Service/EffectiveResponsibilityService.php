<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\Person;
use Drupal\personal_secretary\Entity\ResponsibilityOverride;
use Drupal\personal_secretary\Entity\ResponsibilityRule;
use Drupal\personal_secretary\Value\EffectiveOccurrence;
use Drupal\personal_secretary\Value\EffectiveResponsibility;
use RuntimeException;

/**
 * Calculates deterministic effective responsibility for one EffectiveOccurrence.
 */
final class EffectiveResponsibilityService {

  private const UTC_STORAGE_FORMAT = 'Y-m-d\\TH:i:s';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ResponsibilityContextValidator $contextValidator,
  ) {}

  public function resolve(
    ActivitySeries $series,
    EffectiveOccurrence $occurrence,
  ): EffectiveResponsibility {
    $this->contextValidator->assertCurrentOccurrence($series, $occurrence);

    $override = $this->activeOverrideFor($series, $occurrence);
    if ($override !== NULL) {
      return $this->fromOverride($series, $occurrence, $override);
    }

    $matches = [];
    /** @var \Drupal\personal_secretary\Entity\ResponsibilityRule[] $rules */
    $rules = $this->entityTypeManager
      ->getStorage('personal_sec_resp_rule')
      ->loadByProperties(['series' => $series->id()]);

    foreach ($rules as $rule) {
      $person = $this->contextValidator->requireMember(
        $series,
        (int) $rule->get('responsible_person')->target_id,
      );
      if ($this->ruleMatches($rule, $occurrence)) {
        $matches[] = [$rule, $person];
      }
    }

    if (count($matches) > 1) {
      throw new RuntimeException('Multiple ResponsibilityRules match the same EffectiveOccurrence.');
    }
    if ($matches === []) {
      return $this->none($occurrence);
    }

    /** @var \Drupal\personal_secretary\Entity\ResponsibilityRule $rule */
    /** @var \Drupal\personal_secretary\Entity\Person $person */
    [$rule, $person] = $matches[0];
    return new EffectiveResponsibility(
      state: EffectiveResponsibility::STATE_ASSIGNED,
      source: EffectiveResponsibility::SOURCE_RULE,
      responsiblePersonId: (int) $person->id(),
      responsiblePersonUuid: $person->uuid(),
      ruleId: (int) $rule->id(),
      ruleRevisionId: (string) $rule->getRevisionId(),
      overrideId: NULL,
      overrideRevisionId: NULL,
      seriesUuid: $occurrence->seriesUuid,
      seriesRevisionId: $occurrence->seriesRevisionId,
      originalOccurrenceKey: $occurrence->originalOccurrenceKey,
      effectiveUtcStart: $occurrence->effectiveUtcStart,
      effectiveUtcEnd: $occurrence->effectiveUtcEnd,
      effectiveSourceLocalStart: $occurrence->effectiveSourceLocalStart,
      effectiveSourceLocalEnd: $occurrence->effectiveSourceLocalEnd,
    );
  }

  private function activeOverrideFor(
    ActivitySeries $series,
    EffectiveOccurrence $occurrence,
  ): ?ResponsibilityOverride {
    $storage = $this->entityTypeManager->getStorage('personal_sec_resp_override');
    $ids = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('series', $series->id())
      ->condition('target_revision_id', (int) $occurrence->seriesRevisionId)
      ->condition('original_occurrence_key', $occurrence->originalOccurrenceKey)
      ->condition('status', ResponsibilityOverride::STATUS_ACTIVE)
      ->execute();

    if (count($ids) > 1) {
      throw new RuntimeException('Multiple active ResponsibilityOverrides target the same EffectiveOccurrence.');
    }
    if ($ids === []) {
      return NULL;
    }

    $override = $storage->load(reset($ids));
    if (!$override instanceof ResponsibilityOverride) {
      throw new RuntimeException('ResponsibilityOverride target resolved to an invalid entity.');
    }
    if (!$this->contextValidator->sameImmutableIdentity(
      $series,
      (string) $override->get('target_revision_id')->value,
      (string) $override->get('original_occurrence_key')->value,
      $occurrence,
    )) {
      return NULL;
    }
    $this->contextValidator->assertStoredOriginalContext(
      (string) $override->get('original_utc_start')->value,
      (string) $override->get('original_utc_end')->value,
      (string) $override->get('original_source_local_start')->value,
      (string) $override->get('original_source_local_end')->value,
      (string) $override->get('source_timezone')->value,
      $occurrence,
    );
    return $override;
  }

  private function fromOverride(
    ActivitySeries $series,
    EffectiveOccurrence $occurrence,
    ResponsibilityOverride $override,
  ): EffectiveResponsibility {
    $action = (string) $override->get('action')->value;
    if ($action === ResponsibilityOverride::ACTION_CLEAR_RESPONSIBILITY) {
      if (!$override->get('responsible_person')->isEmpty()) {
        throw new RuntimeException('CLEAR_RESPONSIBILITY override must not reference a Person.');
      }
      return new EffectiveResponsibility(
        state: EffectiveResponsibility::STATE_NONE,
        source: EffectiveResponsibility::SOURCE_OVERRIDE,
        responsiblePersonId: NULL,
        responsiblePersonUuid: NULL,
        ruleId: NULL,
        ruleRevisionId: NULL,
        overrideId: (int) $override->id(),
        overrideRevisionId: (string) $override->getRevisionId(),
        seriesUuid: $occurrence->seriesUuid,
        seriesRevisionId: $occurrence->seriesRevisionId,
        originalOccurrenceKey: $occurrence->originalOccurrenceKey,
        effectiveUtcStart: $occurrence->effectiveUtcStart,
        effectiveUtcEnd: $occurrence->effectiveUtcEnd,
        effectiveSourceLocalStart: $occurrence->effectiveSourceLocalStart,
        effectiveSourceLocalEnd: $occurrence->effectiveSourceLocalEnd,
      );
    }
    if ($action !== ResponsibilityOverride::ACTION_ASSIGN_PERSON) {
      throw new RuntimeException('Unknown active ResponsibilityOverride action.');
    }

    $personId = (int) $override->get('responsible_person')->target_id;
    if ($personId <= 0) {
      throw new RuntimeException('ASSIGN_PERSON override has no responsible Person.');
    }
    $person = $this->contextValidator->requireMember($series, $personId);
    return new EffectiveResponsibility(
      state: EffectiveResponsibility::STATE_ASSIGNED,
      source: EffectiveResponsibility::SOURCE_OVERRIDE,
      responsiblePersonId: (int) $person->id(),
      responsiblePersonUuid: $person->uuid(),
      ruleId: NULL,
      ruleRevisionId: NULL,
      overrideId: (int) $override->id(),
      overrideRevisionId: (string) $override->getRevisionId(),
      seriesUuid: $occurrence->seriesUuid,
      seriesRevisionId: $occurrence->seriesRevisionId,
      originalOccurrenceKey: $occurrence->originalOccurrenceKey,
      effectiveUtcStart: $occurrence->effectiveUtcStart,
      effectiveUtcEnd: $occurrence->effectiveUtcEnd,
      effectiveSourceLocalStart: $occurrence->effectiveSourceLocalStart,
      effectiveSourceLocalEnd: $occurrence->effectiveSourceLocalEnd,
    );
  }

  private function ruleMatches(
    ResponsibilityRule $rule,
    EffectiveOccurrence $occurrence,
  ): bool {
    $targetStart = $this->utc(new DateTimeImmutable($occurrence->effectiveUtcStart));

    if (!$rule->get('effective_until')->isEmpty()) {
      $effectiveUntil = $this->fromStorage((string) $rule->get('effective_until')->value);
      if ($targetStart >= $effectiveUntil) {
        return FALSE;
      }
    }

    $item = $rule->get('recurrence')->first();
    if ($item === NULL || $item->isEmpty()) {
      throw new RuntimeException('ResponsibilityRule has no recurrence value.');
    }
    $raw = $item->getValue();
    $timezoneName = (string) ($raw['timezone'] ?? '');
    if ($timezoneName === '') {
      throw new RuntimeException('ResponsibilityRule recurrence has no canonical source timezone.');
    }

    $windowStart = $this->fromStorage((string) ($raw['value'] ?? ''));
    $windowEnd = $this->fromStorage((string) ($raw['end_value'] ?? ''));
    $durationSeconds = $windowEnd->getTimestamp() - $windowStart->getTimestamp();
    if ($durationSeconds <= 0) {
      throw new RuntimeException('ResponsibilityRule recurrence window duration must be positive.');
    }

    $probeStart = $targetStart->modify(sprintf('-%d seconds', $durationSeconds));
    $probeEnd = $targetStart->modify('+1 second');
    foreach ($item->getHelper()->getOccurrences($probeStart, $probeEnd) as $generated) {
      $generatedStart = $this->utc(DateTimeImmutable::createFromInterface($generated->getStart()));
      $generatedEnd = $this->utc(DateTimeImmutable::createFromInterface($generated->getEnd()));
      if ($generatedEnd <= $generatedStart) {
        throw new RuntimeException('Generated ResponsibilityRule window duration must be positive.');
      }
      if ($targetStart >= $generatedStart && $targetStart < $generatedEnd) {
        return TRUE;
      }
    }

    return FALSE;
  }

  private function none(EffectiveOccurrence $occurrence): EffectiveResponsibility {
    return new EffectiveResponsibility(
      state: EffectiveResponsibility::STATE_NONE,
      source: EffectiveResponsibility::SOURCE_NONE,
      responsiblePersonId: NULL,
      responsiblePersonUuid: NULL,
      ruleId: NULL,
      ruleRevisionId: NULL,
      overrideId: NULL,
      overrideRevisionId: NULL,
      seriesUuid: $occurrence->seriesUuid,
      seriesRevisionId: $occurrence->seriesRevisionId,
      originalOccurrenceKey: $occurrence->originalOccurrenceKey,
      effectiveUtcStart: $occurrence->effectiveUtcStart,
      effectiveUtcEnd: $occurrence->effectiveUtcEnd,
      effectiveSourceLocalStart: $occurrence->effectiveSourceLocalStart,
      effectiveSourceLocalEnd: $occurrence->effectiveSourceLocalEnd,
    );
  }

  private function fromStorage(string $value): DateTimeImmutable {
    $parsed = DateTimeImmutable::createFromFormat(
      '!' . self::UTC_STORAGE_FORMAT,
      $value,
      new DateTimeZone('UTC'),
    );
    if (!$parsed instanceof DateTimeImmutable) {
      throw new RuntimeException('Stored responsibility UTC datetime is invalid.');
    }
    return $parsed;
  }

  private function utc(DateTimeImmutable $value): DateTimeImmutable {
    return $value->setTimezone(new DateTimeZone('UTC'));
  }

}
