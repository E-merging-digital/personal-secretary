<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\Household;
use Drupal\personal_secretary\Entity\Person;
use Drupal\personal_secretary\Value\EffectiveOccurrence;
use InvalidArgumentException;
use RuntimeException;

/**
 * Shared fail-closed validation for responsibility domain operations.
 */
final class ResponsibilityContextValidator {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EffectiveOccurrenceProjectionService $effectiveOccurrenceProjection,
  ) {}

  public function assertCurrentSeries(ActivitySeries $series): void {
    if ($series->isNew() || $series->id() === NULL) {
      throw new InvalidArgumentException('Responsibility operations require a persisted ActivitySeries.');
    }

    $storage = $this->entityTypeManager->getStorage('personal_sec_activity_series');
    if (!$storage instanceof RevisionableStorageInterface) {
      throw new RuntimeException('ActivitySeries storage must support revisions.');
    }
    $latestRevisionId = $storage->getLatestRevisionId($series->id());
    if ($latestRevisionId === NULL || (string) $latestRevisionId !== (string) $series->getRevisionId()) {
      throw new InvalidArgumentException('Responsibility operations require the current ActivitySeries revision.');
    }
  }

  public function household(ActivitySeries $series): Household {
    $this->assertCurrentSeries($series);
    $household = $series->get('household')->entity;
    if (!$household instanceof Household) {
      throw new RuntimeException('ActivitySeries references no valid Household.');
    }
    return $household;
  }

  public function requireMember(ActivitySeries $series, int $personId): Person {
    $household = $this->household($series);
    $memberIds = array_map(
      static fn(array $item): int => (int) ($item['target_id'] ?? 0),
      $household->get('members')->getValue(),
    );
    if (!in_array($personId, $memberIds, TRUE)) {
      throw new InvalidArgumentException('Responsible Person must belong to the ActivitySeries Household.');
    }

    $person = $this->entityTypeManager->getStorage('personal_secretary_person')->load($personId);
    if (!$person instanceof Person) {
      throw new InvalidArgumentException('Responsible Person must reference an existing Person.');
    }
    return $person;
  }

  public function assertCurrentOccurrence(ActivitySeries $series, EffectiveOccurrence $target): void {
    $this->assertCurrentSeries($series);
    if ($series->uuid() !== $target->seriesUuid) {
      throw new InvalidArgumentException('EffectiveOccurrence belongs to another ActivitySeries.');
    }

    $targetStart = $this->utc(new DateTimeImmutable($target->effectiveUtcStart));
    $probeEnd = $targetStart->modify('+1 second');
    foreach ($this->effectiveOccurrenceProjection->project($series, $targetStart, $probeEnd) as $candidate) {
      if ($this->sameCurrentOccurrence($candidate, $target)) {
        return;
      }
    }

    throw new InvalidArgumentException('Responsibility target must be a currently effective audited EffectiveOccurrence.');
  }

  public function sameImmutableIdentity(
    ActivitySeries $series,
    string $targetRevisionId,
    string $originalOccurrenceKey,
    EffectiveOccurrence $target,
  ): bool {
    return $series->uuid() === $target->seriesUuid
      && $targetRevisionId === $target->seriesRevisionId
      && $originalOccurrenceKey === $target->originalOccurrenceKey;
  }

  public function assertStoredOriginalContext(
    string $originalUtcStart,
    string $originalUtcEnd,
    string $originalSourceLocalStart,
    string $originalSourceLocalEnd,
    string $sourceTimezone,
    EffectiveOccurrence $target,
  ): void {
    if (
      $this->atomFromStorage($originalUtcStart) !== $target->originalUtcStart
      || $this->atomFromStorage($originalUtcEnd) !== $target->originalUtcEnd
      || $originalSourceLocalStart !== $target->originalSourceLocalStart
      || $originalSourceLocalEnd !== $target->originalSourceLocalEnd
      || $sourceTimezone !== $target->sourceTimezone
    ) {
      throw new RuntimeException('Stored responsibility target original audit context is inconsistent.');
    }
  }

  private function sameCurrentOccurrence(EffectiveOccurrence $left, EffectiveOccurrence $right): bool {
    return $left->seriesUuid === $right->seriesUuid
      && $left->seriesRevisionId === $right->seriesRevisionId
      && $left->originalOccurrenceKey === $right->originalOccurrenceKey
      && $left->originalUtcStart === $right->originalUtcStart
      && $left->originalUtcEnd === $right->originalUtcEnd
      && $left->originalSourceLocalStart === $right->originalSourceLocalStart
      && $left->originalSourceLocalEnd === $right->originalSourceLocalEnd
      && $left->sourceTimezone === $right->sourceTimezone
      && $left->effectiveUtcStart === $right->effectiveUtcStart
      && $left->effectiveUtcEnd === $right->effectiveUtcEnd
      && $left->effectiveSourceLocalStart === $right->effectiveSourceLocalStart
      && $left->effectiveSourceLocalEnd === $right->effectiveSourceLocalEnd
      && $left->exceptionUuid === $right->exceptionUuid
      && $left->exceptionRevisionId === $right->exceptionRevisionId
      && $left->exceptionAction === $right->exceptionAction;
  }

  private function atomFromStorage(string $value): string {
    $parsed = DateTimeImmutable::createFromFormat(
      '!Y-m-d\\TH:i:s',
      $value,
      new DateTimeZone('UTC'),
    );
    if (!$parsed instanceof DateTimeImmutable) {
      throw new RuntimeException('Stored responsibility target UTC datetime is invalid.');
    }
    return $parsed->format(DATE_ATOM);
  }

  private function utc(DateTimeImmutable $value): DateTimeImmutable {
    return $value->setTimezone(new DateTimeZone('UTC'));
  }

}
