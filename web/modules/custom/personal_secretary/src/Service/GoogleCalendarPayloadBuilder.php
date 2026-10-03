<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\personal_secretary\Entity\ActivitySeries;

/**
 * The complete allowlist of product-owned Google event data.
 */
final class GoogleCalendarPayloadBuilder {

  public function build(array $resolved): array {
    $candidate = $resolved['candidate'];
    $occurrence = $resolved['occurrence'];
    $payload = ['summary' => $candidate->activityLabel];
    $location = trim((string) $resolved['series']->get('location')->value);
    if ($location !== '') {
      $payload['location'] = $location;
    }
    foreach (['start' => $occurrence->effectiveSourceLocalStart, 'end' => $occurrence->effectiveSourceLocalEnd] as $field => $value) {
      $payload[$field] = $resolved['revision']->timeMode() === ActivitySeries::TIME_MODE_ALL_DAY
        ? ['date' => (new \DateTimeImmutable($value))->format('Y-m-d')]
        : ['dateTime' => $value, 'timeZone' => $occurrence->sourceTimezone];
    }
    return $payload;
  }

  public function fingerprint(array $payload): string {
    $canonicalize = static function (array $value) use (&$canonicalize): array {
      ksort($value, SORT_STRING);
      foreach ($value as &$item) {
        if (is_array($item)) {
          $item = $canonicalize($item);
        }
      }
      return $value;
    };
    return hash('sha256', json_encode($canonicalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  }

  public function eventId(string $seriesUuid, string $originalOccurrenceKey): string {
    return 'ps' . hash('sha256', $seriesUuid . '|' . $originalOccurrenceKey);
  }

}
