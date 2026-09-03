<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\PreparationRequirement;
use InvalidArgumentException;
use RuntimeException;

/**
 * Governed lifecycle mutations for PreparationRequirement.
 */
final class PreparationRequirementMutationService {

  private const UTC_STORAGE_FORMAT = 'Y-m-d\\TH:i:s';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ResponsibilityContextValidator $contextValidator,
    private readonly TimeInterface $time,
  ) {}

  public function createPreparationRequirement(
    ActivitySeries $series,
    string $label,
    int $leadTimeSeconds,
    DateTimeImmutable $effectiveFrom,
    ?DateTimeImmutable $effectiveUntil = NULL,
  ): PreparationRequirement {
    $this->contextValidator->assertCurrentSeries($series);
    $label = trim($label);
    if ($label === '') {
      throw new InvalidArgumentException('PreparationRequirement label must not be empty.');
    }
    if ($leadTimeSeconds < 0) {
      throw new InvalidArgumentException('PreparationRequirement lead time must be zero or greater.');
    }

    $effectiveFrom = $this->utc($effectiveFrom);
    $effectiveUntil = $effectiveUntil === NULL ? NULL : $this->utc($effectiveUntil);
    $this->assertValidWindow($effectiveFrom, $effectiveUntil);

    /** @var \Drupal\personal_secretary\Entity\PreparationRequirement $requirement */
    $requirement = $this->storage()->create([
      'series' => $series->id(),
      'label' => $label,
      'lead_time_seconds' => $leadTimeSeconds,
      'effective_from' => $this->toStorage($effectiveFrom),
      'effective_until' => $effectiveUntil === NULL ? NULL : $this->toStorage($effectiveUntil),
      'lifecycle_persisted_at' => $this->time->getCurrentTime(),
    ]);
    $requirement->save();
    return $requirement;
  }

  public function retirePreparationRequirement(
    PreparationRequirement $requirement,
    DateTimeImmutable $effectiveUntil,
  ): PreparationRequirement {
    $this->assertCurrentRequirement($requirement);
    if (!$requirement->get('effective_until')->isEmpty()) {
      throw new InvalidArgumentException('A retired PreparationRequirement cannot be retired again.');
    }

    $series = $requirement->get('series')->entity;
    if (!$series instanceof ActivitySeries) {
      throw new RuntimeException('PreparationRequirement references no persisted ActivitySeries.');
    }
    $this->contextValidator->assertCurrentSeries($series);

    $effectiveFrom = $this->fromStorage((string) $requirement->get('effective_from')->value);
    $effectiveUntil = $this->utc($effectiveUntil);
    $this->assertValidWindow($effectiveFrom, $effectiveUntil);

    $requirement->setNewRevision(TRUE);
    $requirement->set('effective_until', $this->toStorage($effectiveUntil));
    $requirement->set('lifecycle_persisted_at', $this->time->getCurrentTime());
    $requirement->save();
    return $requirement;
  }

  private function assertCurrentRequirement(PreparationRequirement $requirement): void {
    if ($requirement->isNew() || $requirement->id() === NULL) {
      throw new InvalidArgumentException('PreparationRequirement lifecycle mutations require a persisted requirement.');
    }
    $latestRevisionId = $this->storage()->getLatestRevisionId($requirement->id());
    if ($latestRevisionId === NULL || (string) $latestRevisionId !== (string) $requirement->getRevisionId()) {
      throw new InvalidArgumentException('PreparationRequirement lifecycle mutations require the current revision.');
    }
  }

  private function assertValidWindow(
    DateTimeImmutable $effectiveFrom,
    ?DateTimeImmutable $effectiveUntil,
  ): void {
    if ($effectiveUntil !== NULL && $effectiveUntil <= $effectiveFrom) {
      throw new InvalidArgumentException('PreparationRequirement effective-until must be after effective-from.');
    }
  }

  private function storage(): RevisionableStorageInterface {
    $storage = $this->entityTypeManager->getStorage('personal_sec_prep_req');
    if (!$storage instanceof RevisionableStorageInterface) {
      throw new RuntimeException('PreparationRequirement storage must support revisions.');
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
      throw new RuntimeException('Stored preparation UTC datetime is invalid.');
    }
    return $parsed;
  }

  private function utc(DateTimeImmutable $value): DateTimeImmutable {
    return $value->setTimezone(new DateTimeZone('UTC'));
  }

}
