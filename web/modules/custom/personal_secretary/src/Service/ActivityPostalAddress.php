<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\personal_secretary\Entity\ActivitySeries;

/**
 * Displays optional structured postal metadata without changing venue truth.
 */
final class ActivityPostalAddress {

  public static function label(ActivitySeries $series): string {
    $address = $series->get('postal_address')->first()?->getValue() ?? [];
    if (!is_array($address) || empty($address['country_code'])) {
      return '';
    }
    $street = trim(implode(' ', array_filter([
      trim((string) ($address['address_line1'] ?? '')),
      trim((string) ($address['address_line2'] ?? '')),
    ], static fn(string $s): bool => $s !== '')));
    $city = trim(implode(' ', array_filter([
      trim((string) ($address['postal_code'] ?? '')),
      trim((string) ($address['locality'] ?? '')),
    ], static fn(string $s): bool => $s !== '')));
    return implode(', ', array_filter([
      $street,
      $city,
      trim((string) ($address['administrative_area'] ?? '')),
      trim((string) $address['country_code']),
    ], static fn(string $s): bool => $s !== ''));
  }

}
