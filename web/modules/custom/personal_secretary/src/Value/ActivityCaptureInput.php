<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Value;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Immutable LOCAL_ONLY input for an activity-capture interpretation proposal.
 */
final readonly class ActivityCaptureInput {

  public string $text;
  public DateTimeImmutable $contextInstantUtc;
  public string $sourceTimezone;

  public function __construct(
    string $text,
    DateTimeImmutable $contextInstantUtc,
    string $sourceTimezone,
  ) {
    $text = trim($text);
    if ($text === '') {
      throw new InvalidArgumentException('Activity capture input text must not be empty.');
    }

    try {
      $timezone = new DateTimeZone($sourceTimezone);
    }
    catch (\Throwable $e) {
      throw new InvalidArgumentException('Activity capture source timezone must be valid.', 0, $e);
    }

    $this->text = $text;
    $this->contextInstantUtc = $contextInstantUtc->setTimezone(new DateTimeZone('UTC'));
    $this->sourceTimezone = $timezone->getName();
  }

  public function contextInstantIso8601(): string {
    return $this->contextInstantUtc->format('Y-m-d\TH:i:s\Z');
  }

}
