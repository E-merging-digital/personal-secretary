<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Checks generated private HTTPS URLs without real network or Google access.
 *
 * @group personal_secretary
 */
#[RunTestsInSeparateProcesses]
final class PrivateDevHttpsOriginTest extends BrowserTestBase {

  protected static $modules = [
    'personal_secretary',
  ];

  protected $defaultTheme = 'stark';

  /**
   * Proves real Drupal route URL generation under a synthetic context.
   */
  public function testSynthetic8443RouteUrlAndRedirectLocation(): void {
    $context = $this->container->get('router.request_context');
    $scheme = $context->getScheme();
    $host = $context->getHost();
    $httpPort = $context->getHttpPort();
    $httpsPort = $context->getHttpsPort();
    $baseUrl = $context->getBaseUrl();

    try {
      // Only modify the ephemeral Drupal URL generator's test RequestContext.
      // This is NOT evidence of a trusted real Caddy peer or browser session.
      $context->setScheme('https');
      $context->setHost('ps-dev.internal.emergingdigital.be');
      $context->setHttpPort(80);
      $context->setHttpsPort(8443);
      $context->setBaseUrl('');

      // Construct a URL only. Never visit the protected Google status route.
      $absolute = Url::fromRoute(
        'personal_secretary.google_calendar_status',
        [],
        ['absolute' => TRUE],
      )->toString();
      $expected = 'https://ps-dev.internal.emergingdigital.be:8443/personal-secretary/calendar/google';
      $this->assertSame($expected, $absolute);

      // Synthetic redirect response only; no controller/provider was called.
      $redirect = new RedirectResponse($absolute, 302);
      $this->assertSame($expected, $redirect->headers->get('Location'));
      $this->assertStringNotContainsString('personal-secretary.emergingdigital.be', $absolute);
    }
    finally {
      $context->setScheme($scheme);
      $context->setHost($host);
      $context->setHttpPort($httpPort);
      $context->setHttpsPort($httpsPort);
      $context->setBaseUrl($baseUrl);
    }
  }

}
