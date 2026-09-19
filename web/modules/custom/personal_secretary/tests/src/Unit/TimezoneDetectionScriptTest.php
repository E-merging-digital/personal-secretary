<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Proves the browser timezone suggestion stays local and option-bounded.
 */
#[Group('personal_secretary')]
final class TimezoneDetectionScriptTest extends TestCase {

  public function testIntlSuggestionIsNetworkAndLocationFree(): void {
    $path = dirname(__DIR__, 3) . '/js/timezone-detection.js';
    $source = file_get_contents($path);
    self::assertIsString($source);

    self::assertStringContainsString(
      'new Intl.DateTimeFormat().resolvedOptions().timeZone',
      $source,
    );
    self::assertStringContainsString(
      'option.value === detected',
      $source,
    );
    self::assertStringContainsString(
      'select.value = detected',
      $source,
    );

    foreach ([
      'fetch(',
      'XMLHttpRequest',
      '$.ajax',
      'navigator.geolocation',
      'geolocation.getCurrentPosition',
      'http://',
      'https://',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $source);
    }
  }

}
