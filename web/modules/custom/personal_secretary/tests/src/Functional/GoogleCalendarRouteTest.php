<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Proves Google Calendar route authorization at Drupal router level.
 *
 * @group personal_secretary
 */
#[RunTestsInSeparateProcesses]
final class GoogleCalendarRouteTest extends BrowserTestBase {

  protected static $modules = [
    'personal_secretary',
  ];

  protected $defaultTheme = 'stark';

  public function testGoogleCalendarRouteAuthorization(): void {
    $routeName = 'personal_secretary.google_calendar_status';
    $path = '/personal-secretary/calendar/google';

    $provider = $this->container->get('router.route_provider');
    $accessManager = $this->container->get('access_manager');
    $currentUser = $this->container->get('current_user');
    $router = $this->container->get('router');

    $route = $provider->getRouteByName($routeName);

    $this->assertSame(
      'use personal secretary',
      $route->getRequirement('_permission'),
    );
    $this->assertSame(
      'TRUE',
      $route->getRequirement('_user_is_logged_in'),
    );

    $checks = $route->getOption('_access_checks') ?: [];

    $this->assertContains(
      'access_check.permission',
      $checks,
    );
    $this->assertContains(
      'access_check.user.login_status',
      $checks,
    );

    // Anonymous User.
    $this->assertTrue($currentUser->isAnonymous());

    $this->assertFalse(
      $accessManager->checkNamedRoute(
        $routeName,
        [],
        $currentUser,
      ),
      'Anonymous User must not be granted route access.',
    );

    try {
      $router->match($path);
      $this->fail(
        'AccessAwareRouter must reject anonymous access.',
      );
    }
    catch (AccessDeniedHttpException) {
      $this->addToAssertionCount(1);
    }

    // Authenticated User without product permission.
    $unauthorized = $this->drupalCreateUser([]);
    $this->assertInstanceOf(
      UserInterface::class,
      $unauthorized,
    );
    $this->assertTrue($unauthorized->isAuthenticated());
    $this->assertFalse(
      $unauthorized->hasPermission('use personal secretary'),
    );

    $this->assertFalse(
      $accessManager->checkNamedRoute(
        $routeName,
        [],
        $unauthorized,
      ),
      'Authenticated User without product permission must be denied.',
    );

    $currentUser->setAccount($unauthorized);

    try {
      $router->match($path);
      $this->fail(
        'AccessAwareRouter must reject authenticated User without product permission.',
      );
    }
    catch (AccessDeniedHttpException) {
      $this->addToAssertionCount(1);
    }

    // Authenticated User with explicit product permission.
    $authorized = $this->drupalCreateUser([
      'use personal secretary',
    ]);
    $this->assertInstanceOf(
      UserInterface::class,
      $authorized,
    );
    $this->assertTrue($authorized->isAuthenticated());
    $this->assertTrue(
      $authorized->hasPermission('use personal secretary'),
    );

    $this->assertTrue(
      $accessManager->checkNamedRoute(
        $routeName,
        [],
        $authorized,
      ),
      'Authorized authenticated User must be granted route access.',
    );

    $currentUser->setAccount($authorized);

    $match = $router->match($path);

    $this->assertSame(
      $routeName,
      $match['_route'] ?? NULL,
      'Authorized User must resolve the Google Calendar status route.',
    );
  }

}
