<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Proves the bounded privacy-safe PWA shell contract.
 *
 * @group personal_secretary
 */
final class PwaMvpTest extends BrowserTestBase {

  protected static $modules = ['personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testManifestInstallabilityAndGlobalAttachment(): void {
    $admin = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->drupalLogin($admin);
    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists(
      'css',
      'link[rel="manifest"][href="/personal-secretary.webmanifest"]',
    );
    $this->assertSession()->elementExists(
      'css',
      'link[rel="apple-touch-icon"][href="/pwa-icon-192.png"][sizes="192x192"]',
    );
    $this->assertSession()->elementExists(
      'css',
      'meta[name="theme-color"][content="#1b9ae4"]',
    );
    $this->assertSession()->elementExists(
      'css',
      'script[src*="/modules/custom/personal_secretary/js/pwa-register.js"]',
    );

    $this->drupalGet('/personal-secretary.webmanifest');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains(
      'Content-Type',
      'application/manifest+json',
    );

    $manifest = json_decode(
      $this->getSession()->getPage()->getContent(),
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );
    $this->assertSame('Personal Secretary', $manifest['name']);
    $this->assertSame('Secretary', $manifest['short_name']);
    $this->assertSame('/personal-secretary/', $manifest['id']);
    $this->assertSame('/personal-secretary/today', $manifest['start_url']);
    $this->assertSame('/', $manifest['scope']);
    $this->assertSame('standalone', $manifest['display']);
    $this->assertSame('#ffffff', $manifest['background_color']);
    $this->assertSame('#1b9ae4', $manifest['theme_color']);
    $this->assertFalse($manifest['prefer_related_applications']);

    $icons = [];
    foreach ($manifest['icons'] as $icon) {
      $icons[$icon['sizes']] = $icon;
    }
    $this->assertSame('/pwa-icon-192.png', $icons['192x192']['src']);
    $this->assertSame('image/png', $icons['192x192']['type']);
    $this->assertSame('/pwa-icon-512.png', $icons['512x512']['src']);
    $this->assertSame('image/png', $icons['512x512']['type']);

    $this->assertPngDimensions('pwa-icon-192.png', 192);
    $this->assertPngDimensions('pwa-icon-512.png', 512);
  }

  public function testWorkerIsNetworkOnlyOutsideGenericOfflineFallback(): void {
    $this->drupalGet('/personal-secretary-service-worker.js');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'javascript');

    $worker = $this->getSession()->getPage()->getContent();
    $this->assertStringContainsString(
      "const CACHE_PREFIX = 'personal-secretary-pwa-';",
      $worker,
    );
    $this->assertStringContainsString(
      "const CACHE_NAME = `${CACHE_PREFIX}v1`;",
      $worker,
    );
    $this->assertStringContainsString(
      "const OFFLINE_URL = '/personal-secretary-offline.html';",
      $worker,
    );
    $this->assertStringContainsString('cache.add(OFFLINE_URL)', $worker);
    $this->assertStringContainsString("request.method !== 'GET'", $worker);
    $this->assertStringContainsString("request.mode !== 'navigate'", $worker);
    $this->assertStringContainsString('fetch(request).catch(', $worker);
    $this->assertStringContainsString('key.startsWith(CACHE_PREFIX)', $worker);
    $this->assertStringContainsString('caches.delete(key)', $worker);

    foreach ([
      'cache.put(',
      'cache.addAll(',
      'response.clone(',
      'indexedDB',
      "addEventListener('push'",
      "addEventListener('sync'",
      'backgroundSync',
    ] as $forbidden) {
      $this->assertStringNotContainsString($forbidden, $worker);
    }

    $registration = file_get_contents(
      DRUPAL_ROOT . '/modules/custom/personal_secretary/js/pwa-register.js',
    );
    $this->assertIsString($registration);
    $this->assertStringContainsString(
      ".register('/personal-secretary-service-worker.js', { scope: '/' })",
      $registration,
    );
  }

  public function testOfflineFallbackIsGenericAndLoggedOutLaunchKeepsDrupalAuthority(): void {
    $this->drupalGet('/personal-secretary-offline.html');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'text/html');
    $this->assertSession()->pageTextContains('Personal Secretary is offline.');
    $this->assertSession()->pageTextContains('Reconnect to access your information.');
    $this->assertSession()->pageTextContains('Personal Secretary est hors ligne.');
    $this->assertSession()->pageTextContains(
      'Reconnectez-vous pour accéder à vos informations.',
    );

    $fallback = $this->getSession()->getPage()->getContent();
    foreach ([
      'CurrentPerson',
      'Household',
      'ActivitySeries',
      'PreparationCompletion',
      'csrf_token',
      'session',
    ] as $personal_or_authority_marker) {
      $this->assertStringNotContainsString(
        $personal_or_authority_marker,
        $fallback,
      );
    }

    $this->drupalGet('/personal-secretary/today');
    $this->assertSession()->statusCodeEquals(403);
  }

  private function assertPngDimensions(string $filename, int $expected): void {
    $path = DRUPAL_ROOT . '/' . $filename;
    $contents = file_get_contents($path);
    $this->assertIsString($contents);
    $this->assertSame("\x89PNG\r\n\x1a\n", substr($contents, 0, 8));

    $dimensions = unpack('Nwidth/Nheight', substr($contents, 16, 8));
    $this->assertIsArray($dimensions);
    $this->assertSame($expected, $dimensions['width']);
    $this->assertSame($expected, $dimensions['height']);

    $this->drupalGet('/' . $filename);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'image/png');
  }

}
