<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Value;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Transient, fictional preview input; never accepted as domain truth.
 */
final readonly class SyntheticRoutineProposal {

  public function __construct(
    public string $key,
    public int $version,
    public string $fictionalSentence,
    public string $householdKey,
    public string $startDate,
    public bool $requireSchoolDay,
    public bool $requirePhysicalPresence,
    public bool $suggestPreparation,
    public bool $suggestMorningStops,
    public bool $suggestAfternoonCollection,
    public string $timezone = 'Europe/Brussels',
  ) {
    if (!preg_match('/^SYNTHETIC_[A-Z0-9_]+$/D', $key)
      || !preg_match('/^SYNTHETIC_[A-Z0-9_]+$/D', $householdKey)
      || $version < 1 || trim($fictionalSentence) === '' || !$requirePhysicalPresence) {
      throw new InvalidArgumentException('Synthetic preview identity and input are required.');
    }
    try {
      $zone = new DateTimeZone($timezone);
    }
    catch (\Throwable) {
      throw new InvalidArgumentException('Invalid source timezone.');
    }
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate, $zone);
    if (!$start || $start->format('Y-m-d') !== $startDate) {
      throw new InvalidArgumentException('Invalid local start date.');
    }
  }

}
