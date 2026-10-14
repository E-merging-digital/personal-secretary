<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the new-activity duration convenience in an actual browser.
 *
 * @group personal_secretary
 */
#[RunTestsInSeparateProcesses]
final class ActivityDefaultDurationJavascriptTest extends WebDriverTestBase {

  protected static $modules = ['personal_secretary'];

  protected $defaultTheme = 'olivero';

  protected function setUp(): void {
    parent::setUp();

    $domain = $this->container->get('personal_secretary.domain_mutation');
    $person = $domain->createPerson('Synthetic duration browser person');
    $domain->createHousehold('Synthetic duration browser household', [(int) $person->id()]);

    $user = $this->drupalCreateUser([
      HouseholdAuthorizationService::ADMIN_PERMISSION,
      HouseholdAuthorizationService::PRODUCT_USE_PERMISSION,
    ]);
    $this->drupalLogin($user);
  }

  public function testTimedDefaultOverrideClearMidnightAndReattachment(): void {
    $this->getSession()->resizeWindow(390, 844);
    $this->drupalGet('/personal-secretary/activities/add');
    $this->assertSession()->elementExists('css', 'input[data-ps-activity-start]');
    $this->assertSession()->elementExists('css', 'input[data-ps-activity-end]');
    $this->assertTime('end_local_time', '');

    $this->setTime('start_local_time', '10:00');
    $this->assertTime('end_local_time', '10:30');
    $this->setTime('start_local_time', '11:15');
    $this->assertTime('end_local_time', '11:45');

    $this->setTime('end_local_time', '12:30');
    $this->setTime('start_local_time', '11:30');
    $this->assertTime('end_local_time', '12:30');

    // Manual clearing is also sticky, even after Drupal reattachment.
    $this->setTime('end_local_time', '');
    $this->setTime('start_local_time', '13:00');
    $this->assertTime('end_local_time', '');
    $this->getSession()->executeScript('Drupal.attachBehaviors(document, drupalSettings);');
    $this->setTime('start_local_time', '14:00');
    $this->assertTime('end_local_time', '');
    $this->assertSame(1, $this->getSession()->evaluateScript(
      'document.querySelectorAll("[data-ps-activity-duration-status]").length',
    ));

    // Midnight is never represented as an invalid same-date end.
    $this->drupalGet('/personal-secretary/activities/add');
    $this->setTime('start_local_time', '23:29');
    $this->assertTime('end_local_time', '23:59');
    $this->setTime('start_local_time', '23:31');
    $this->assertTime('end_local_time', '');
    $this->setTime('start_local_time', '23:59');
    $this->assertTime('end_local_time', '');
    $this->setTime('start_local_time', '09:00');
    $this->assertTime('end_local_time', '09:30');
  }

  public function testAllDayOneOffAndFocusAccessibility(): void {
    $this->drupalGet('/personal-secretary/activities/add');
    $this->getSession()->executeScript(
      "document.querySelector('input[name=\"activity_type\"][value=\"one_off\"]').click();",
    );
    $this->getSession()->executeScript(
      "document.querySelector('input[name=\"time_mode\"][value=\"all_day\"]').click();",
    );
    $this->setTime('start_local_time', '09:00');
    $this->assertTime('end_local_time', '');

    $this->getSession()->executeScript(
      "document.querySelector('input[name=\"time_mode\"][value=\"timed\"]').click();",
    );
    $this->assertTime('end_local_time', '09:30');

    // Standard input/change events work without pointer-only handlers.
    $this->getSession()->executeScript(
      "document.querySelector('input[name=\"start_local_time\"]').focus();",
    );
    $this->setTime('start_local_time', '10:15');
    $this->assertTime('end_local_time', '10:45');
    $this->assertTrue((bool) $this->getSession()->evaluateScript(
      "document.activeElement === document.querySelector('input[name=\"start_local_time\"]')",
    ));
    $this->assertSame('status', $this->getSession()->evaluateScript(
      'document.querySelector("[data-ps-activity-duration-status]").getAttribute("role")',
    ));
    $this->assertTrue((bool) $this->getSession()->evaluateScript(
      'document.querySelector("[data-ps-activity-duration-status]").textContent.includes("10:45")',
    ));

    // Changing start while ALL_DAY does not mutate the time fields.
    $this->getSession()->executeScript(
      "document.querySelector('input[name=\"time_mode\"][value=\"all_day\"]').click();",
    );
    $this->setTime('start_local_time', '11:00');
    $this->assertTime('end_local_time', '10:45');
  }

  private function setTime(string $name, string $value): void {
    $input = json_encode($name, JSON_THROW_ON_ERROR);
    $time = json_encode($value, JSON_THROW_ON_ERROR);
    $this->getSession()->executeScript(
      "const input = document.getElementsByName($input)[0];"
      . "input.value = $time;"
      . "input.dispatchEvent(new Event('input', { bubbles: true }));"
      . "input.dispatchEvent(new Event('change', { bubbles: true }));",
    );
  }

  private function assertTime(string $name, string $expected): void {
    $input = json_encode($name, JSON_THROW_ON_ERROR);
    $actual = $this->getSession()->evaluateScript(
      "document.getElementsByName($input)[0].value",
    );
    $this->assertSame($expected, $actual);
  }

}
