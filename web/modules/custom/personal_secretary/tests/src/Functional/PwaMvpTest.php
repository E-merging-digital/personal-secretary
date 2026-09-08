<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Proves the hybrid contrib-manifest / project-worker PWA contract.
 *
 * @group personal_secretary
 */
final class PwaMvpTest extends BrowserTestBase {

  protected static $modules = ['pwa', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  protected function setUp(): void {
    parent::setUp();
    $this->config('pwa.config')
      ->set('name', 'Personal Secretary')
      ->set('short_name', 'Secretary')
      ->set('app_id', '/personal-secretary/')
      ->set('start_url', '/personal-secretary/today')
      ->set('scope', '/')
      ->set('display', 'standalone')
      ->set('theme_color', '#1b9ae4')
      ->set('background_color', '#ffffff')
      ->save();
  }

  public function testAcceptedPackageAndDisabledContribSubmodules(): void {
    $handler = $this->container->get('module_handler');
    $this->assertTrue($handler->moduleExists('pwa'));
    $this->assertFalse($handler->moduleExists('pwa_service_worker'));
    $this->assertFalse($handler->moduleExists('pwa_extras'));
    $this->assertFalse($handler->moduleExists('pwa_a2hs'));

    $info = $this->container->get('extension.list.module')->getExtensionInfo('pwa');
    $this->assertSame('2.1.0-beta7', $info['version'] ?? NULL);

    $coreExtension = file_get_contents(dirname(DRUPAL_ROOT) . '/config/sync/core.extension.yml');
    $this->assertIsString($coreExtension);
    $this->assertStringContainsString("  pwa: 0\n", $coreExtension);
    foreach (['pwa_service_worker:', 'pwa_extras:', 'pwa_a2hs:'] as $disabled) {
      $this->assertStringNotContainsString($disabled, $coreExtension);
    }

    $lock = json_decode(
      (string) file_get_contents(dirname(DRUPAL_ROOT) . '/composer.lock'),
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );
    $packages = array_column($lock['packages'], NULL, 'name');
    $this->assertSame('2.1.0-beta7', $packages['drupal/pwa']['version'] ?? NULL);
  }

  public function testContribManifestAndProjectBrandingAreGloballyAttached(): void {
    $user = $this->drupalCreateUser([
      'access pwa',
      'administer personal secretary domain',
    ]);
    $this->drupalLogin($user);
    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists(
      'css',
      'link[rel="manifest"][href="/manifest.json"]',
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

    $this->drupalGet('/manifest.json');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'application/json');
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

    $icons = [];
    foreach ($manifest['icons'] as $icon) {
      $icons[$icon['sizes']] = $icon;
    }
    $this->assertSame('/pwa-icon-192.png', $icons['192x192']['src']);
    $this->assertSame('/pwa-icon-512.png', $icons['512x512']['src']);
    $this->assertPngDimensions('pwa-icon-192.png', 192);
    $this->assertPngDimensions('pwa-icon-512.png', 512);
    $this->assertFileDoesNotExist(DRUPAL_ROOT . '/personal-secretary.webmanifest');
  }

  public function testProjectWorkerIsNetworkOnlyOutsideGenericOfflineFallback(): void {
    $this->assertFalse($this->container->get('module_handler')->moduleExists('pwa_service_worker'));
    $this->drupalGet('/personal-secretary-service-worker.js');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'javascript');

    $worker = $this->getSession()->getPage()->getContent();
    foreach ([
      "const CACHE_PREFIX = 'personal-secretary-pwa-';",
      "const OFFLINE_URL = '/personal-secretary-offline.html';",
      'cache.add(OFFLINE_URL)',
      "request.method !== 'GET'",
      "request.mode !== 'navigate'",
      'fetch(request).catch(',
      'key.startsWith(CACHE_PREFIX)',
      'caches.delete(key)',
    ] as $required) {
      $this->assertStringContainsString($required, $worker);
    }
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
    $this->assertStringNotContainsString('/service-worker-data', $registration);
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
    ] as $personalOrAuthorityMarker) {
      $this->assertStringNotContainsString($personalOrAuthorityMarker, $fallback);
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
