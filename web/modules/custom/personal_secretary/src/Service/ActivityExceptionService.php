<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\personal_secretary\Entity\ActivityException;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Value\BaseOccurrence;
use InvalidArgumentException;
use RuntimeException;

/**
 * Governed mutation boundary for explicit single-occurrence exceptions.
 */
final class ActivityExceptionService {

  private const UTC_STORAGE_FORMAT = 'Y-m-d\TH:i:s';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RevisionTimelineService $revisionTimeline,
  ) {}

  public function createCancel(ActivitySeries $series, BaseOccurrence $target): ActivityException {
    $this->validateTarget($series, $target);
    $this->assertNoDuplicateActiveTarget($series, $target);

    return $this->createException(
      $series,
      $target,
      ActivityException::ACTION_CANCEL,
      NULL,
      NULL,
    );
  }

  public function createReschedule(
    ActivitySeries $series,
    BaseOccurrence $target,
    DateTimeImmutable $newUtcStart,
    DateTimeImmutable $newUtcEnd,
    string $sourceTimezone,
  ): ActivityException {
    $this->validateTarget($series, $target);
    $this->assertNoDuplicateActiveTarget($series, $target);
    if ($sourceTimezone !== $target->sourceTimezone) {
      throw new InvalidArgumentException('Cross-timezone ActivityException rescheduling is not authorized.');
    }

    $newUtcStart = $this->utc($newUtcStart);
    $newUtcEnd = $this->utc($newUtcEnd);
    if ($newUtcEnd <= $newUtcStart) {
      throw new InvalidArgumentException('Rescheduled occurrence end must be after start.');
    }

    return $this->createException(
      $series,
      $target,
      ActivityException::ACTION_RESCHEDULE,
      $newUtcStart,
      $newUtcEnd,
    );
  }

  /**
   * Marks superseded active targets orphaned as new exception revisions.
   *
   * @return \Drupal\personal_secretary\Entity\ActivityException[]
   */
  public function orphanSupersededTargets(
    ActivitySeries $newRevision,
    DateTimeImmutable $effectiveFrom,
  ): array {
    if ($newRevision->isNew()) {
      throw new InvalidArgumentException('Orphan evaluation requires a persisted ActivitySeries revision.');
    }

    $boundary = $this->utc($effectiveFrom);
    $newRevisionId = (string) $newRevision->getRevisionId();
    $orphaned = [];

    foreach ($this->activeForSeries($newRevision) as $exception) {
      if ((string) $exception->get('target_revision_id')->value === $newRevisionId) {
        continue;
      }
      $originalStart = $this->fromStorage((string) $exception->get('original_utc_start')->value);
      if ($originalStart < $boundary) {
        continue;
      }

      $exception->setNewRevision(TRUE);
      $exception->set('status', ActivityException::STATUS_ORPHANED);
      $exception->save();
      $orphaned[] = $exception;
    }

    return $orphaned;
  }

  public function reconcile(
    ActivityException $exception,
    ActivitySeries $series,
    BaseOccurrence $newTarget,
  ): ActivityException {
    if ($exception->isNew()) {
      throw new InvalidArgumentException('Reconciliation requires a persisted ActivityException.');
    }
    $storage = $this->exceptionStorage();
    $latestRevisionId = $storage->getLatestRevisionId($exception->id());
    if ($latestRevisionId === NULL || (string) $latestRevisionId !== (string) $exception->getRevisionId()) {
      throw new InvalidArgumentException('Reconciliation requires the current ActivityException revision.');
    }
    if ((string) $exception->get('status')->value !== ActivityException::STATUS_ORPHANED) {
      throw new InvalidArgumentException('Only an orphaned ActivityException can be reconciled.');
    }
    if ((string) $exception->get('series')->target_id !== (string) $series->id()) {
      throw new InvalidArgumentException('ActivityException reconciliation cannot cross ActivitySeries.');
    }

    $this->validateTarget($series, $newTarget);
    $this->assertNoDuplicateActiveTarget($series, $newTarget, (int) $exception->id());

    $exception->setNewRevision(TRUE);
    foreach ($this->targetSnapshot($newTarget) as $field => $value) {
      $exception->set($field, $value);
    }
    $exception->set('status', ActivityException::STATUS_ACTIVE);
    $exception->save();

    return $exception;
  }

  /**
   * @return \Drupal\personal_secretary\Entity\ActivityException[]
   */
  public function activeForSeries(ActivitySeries $series): array {
    if ($series->isNew() || $series->id() === NULL) {
      return [];
    }
    /** @var \Drupal\personal_secretary\Entity\ActivityException[] $exceptions */
    $exceptions = $this->exceptionStorage()->loadByProperties([
      'series' => $series->id(),
      'status' => ActivityException::STATUS_ACTIVE,
    ]);
    return array_values($exceptions);
  }

  private function createException(
    ActivitySeries $series,
    BaseOccurrence $target,
    string $action,
    ?DateTimeImmutable $newUtcStart,
    ?DateTimeImmutable $newUtcEnd,
  ): ActivityException {
    $values = $this->targetSnapshot($target) + [
      'series' => $series->id(),
      'action' => $action,
      'status' => ActivityException::STATUS_ACTIVE,
      'rescheduled_utc_start' => $newUtcStart === NULL ? NULL : $this->toStorage($newUtcStart),
      'rescheduled_utc_end' => $newUtcEnd === NULL ? NULL : $this->toStorage($newUtcEnd),
    ];

    /** @var \Drupal\personal_secretary\Entity\ActivityException $exception */
    $exception = $this->exceptionStorage()->create($values);
    $exception->save();
    return $exception;
  }

  /**
   * @return array<string, int|string>
   */
  private function targetSnapshot(BaseOccurrence $target): array {
    return [
      'target_revision_id' => (int) $target->seriesRevisionId,
      'original_occurrence_key' => $target->originalOccurrenceKey,
      'original_utc_start' => $this->toStorage(new DateTimeImmutable($target->utcStart)),
      'original_utc_end' => $this->toStorage(new DateTimeImmutable($target->utcEnd)),
      'original_source_local_start' => $target->sourceLocalStart,
      'original_source_local_end' => $target->sourceLocalEnd,
      'source_timezone' => $target->sourceTimezone,
    ];
  }

  private function validateTarget(ActivitySeries $series, BaseOccurrence $target): void {
    if (!$this->revisionTimeline->isEffectiveTarget($series, $target)) {
      throw new InvalidArgumentException('ActivityException target must be a currently effective audited BaseOccurrence.');
    }
  }

  private function assertNoDuplicateActiveTarget(
    ActivitySeries $series,
    BaseOccurrence $target,
    ?int $excludeExceptionId = NULL,
  ): void {
    $ids = $this->exceptionStorage()
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('series', $series->id())
      ->condition('target_revision_id', (int) $target->seriesRevisionId)
      ->condition('original_occurrence_key', $target->originalOccurrenceKey)
      ->condition('status', ActivityException::STATUS_ACTIVE)
      ->execute();

    foreach ($ids as $id) {
      if ($excludeExceptionId === NULL || (int) $id !== $excludeExceptionId) {
        throw new InvalidArgumentException('An active ActivityException already targets this occurrence.');
      }
    }
  }

  private function exceptionStorage(): RevisionableStorageInterface {
    $storage = $this->entityTypeManager->getStorage('personal_sec_activity_exception');
    if (!$storage instanceof RevisionableStorageInterface) {
      throw new RuntimeException('ActivityException storage must support revisions.');
    }
    return $storage;
  }

  private function toStorage(DateTimeImmutable $value): string {
    return $this->utc($value)->format(self::UTC_STORAGE_FORMAT);
  }

  private function fromStorage(string $value): DateTimeImmutable {
    $parsed = DateTimeImmutable::createFromFormat(
      '!' . self::UTC_STORAGE_FORMAT,
      $value,
      new DateTimeZone('UTC'),
    );
    if (!$parsed instanceof DateTimeImmutable) {
      throw new RuntimeException('Stored ActivityException UTC datetime is invalid.');
    }
    return $parsed;
  }

  private function utc(DateTimeImmutable $value): DateTimeImmutable {
    return $value->setTimezone(new DateTimeZone('UTC'));
  }

}
