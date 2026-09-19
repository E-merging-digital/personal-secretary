<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Service\CurrentUserTimezoneService;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Proves Core User timezone persistence and activity creation defaults.
 *
 * @group personal_secretary
 */
#[RunTestsInSeparateProcesses]
final class UserTimezoneExperienceTest extends BrowserTestBase {

  protected static $modules = ['personal_secretary'];

  protected $defaultTheme = 'olivero';

  protected function setUp(): void {
    parent::setUp();

    $this->config('system.date')
      ->set('timezone.default', CurrentUserTimezoneService::FALLBACK_TIMEZONE)
      ->set('timezone.user.configurable', TRUE)
      ->set('timezone.user.default', UserInterface::TIMEZONE_EMPTY)
      ->save();
  }

  public function testCoreTimezonePersistenceDrivesCreationDefaultsWithoutRewritingSeries(): void {
    $admin = $this->drupalCreateUser([HouseholdAuthorizationService::ADMIN_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $admin);
    $this->assertSame('', (string) $admin->getTimezone());

    $this->drupalLogin($admin);

    /** @var \Drupal\personal_secretary\Service\CurrentUserTimezoneService $timezone */
    $timezone = $this->container->get('personal_secretary.current_user_timezone');
    $this->assertSame('Europe/Brussels', $timezone->effectiveTimezone());
    $this->assertTrue($timezone->mayUseBrowserSuggestion());

    // The existing Core account form remains the durable preference surface.
    $this->drupalGet('/user/' . $admin->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('timezone');
    $this->assertSession()->fieldValueEquals('timezone', 'Europe/Brussels');
    $this->assertSession()->elementExists(
      'css',
      'select[name="timezone"].personal-secretary-timezone-detect',
    );
    $this->assertSession()->responseContains('timezone-detection.js');
    $this->assertSession()->responseNotContains('core/misc/timezone.js');

    // Missing browser execution deterministically leaves the Brussels fallback.
    $this->drupalGet('/personal-secretary/setup');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldValueEquals('source_timezone', 'Europe/Brussels');
    $this->assertSession()->elementExists(
      'css',
      'select[name="source_timezone"].personal-secretary-timezone-detect',
    );

    $firstDate = '2026-10-17';
    $this->submitForm([
      'household_name' => 'Timezone Household',
      'responsible_person_name' => 'Timezone Person',
      'activity_label' => 'Timezone preserved activity',
      'first_occurrence_date' => $firstDate,
      'start_local_time' => '09:00',
      'end_local_time' => '10:00',
      'source_timezone' => 'Europe/Brussels',
      'preparation_instruction' => '',
      'preparation_lead_minutes' => '0',
    ], 'Create first activity');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');

    $manager = $this->container->get('entity_type.manager');
    $series = array_values($manager
      ->getStorage('personal_sec_activity_series')
      ->loadMultiple());
    $this->assertCount(1, $series);
    $this->assertInstanceOf(ActivitySeries::class, $series[0]);
    $seriesId = (int) $series[0]->id();
    $recurrenceBefore = $series[0]->get('recurrence')->first()?->getValue();
    $this->assertIsArray($recurrenceBefore);
    $this->assertSame('Europe/Brussels', $recurrenceBefore['timezone']);

    /** @var \Drupal\personal_secretary\Service\OccurrenceProjectionService $projection */
    $projection = $this->container->get('personal_secretary.occurrence_projection');
    $projectionStart = new DateTimeImmutable('2026-10-16T00:00:00Z');
    $projectionEnd = new DateTimeImmutable('2026-11-01T00:00:00Z');
    $keysBefore = array_map(
      static fn($occurrence): string => $occurrence->originalOccurrenceKey,
      $projection->project($series[0], $projectionStart, $projectionEnd),
    );
    $this->assertNotEmpty($keysBefore);

    // Add activity also falls back to Brussels while the User preference is empty.
    $this->drupalGet('/personal-secretary/activities/add');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldValueEquals('source_timezone', 'Europe/Brussels');
    $this->assertSession()->elementExists(
      'css',
      'select[name="source_timezone"].personal-secretary-timezone-detect',
    );

    // Persist through Core's own User timezone field.
    $this->drupalGet('/user/' . $admin->id() . '/edit');
    $this->submitForm(['timezone' => 'America/New_York'], 'Save');
    $this->assertSession()->pageTextContains('The changes have been saved.');

    $persisted = User::load($admin->id());
    $this->assertInstanceOf(UserInterface::class, $persisted);
    $this->assertSame('America/New_York', $persisted->getTimezone());

    $this->drupalLogout();
    $this->drupalLogin($persisted);

    $this->assertSame('America/New_York', $timezone->effectiveTimezone());
    $this->assertFalse($timezone->mayUseBrowserSuggestion());

    $this->drupalGet('/personal-secretary/setup');
    $this->assertSession()->fieldValueEquals('source_timezone', 'America/New_York');
    $this->assertSession()->elementNotExists(
      'css',
      'select[name="source_timezone"].personal-secretary-timezone-detect',
    );

    $this->drupalGet('/personal-secretary/activities/add');
    $this->assertSession()->fieldValueEquals('source_timezone', 'America/New_York');
    $this->assertSession()->elementNotExists(
      'css',
      'select[name="source_timezone"].personal-secretary-timezone-detect',
    );

    // Core remains the correction surface; no Personal Secretary preference is
    // persisted separately.
    $this->drupalGet('/user/' . $persisted->id() . '/edit');
    $this->submitForm(['timezone' => 'Asia/Tokyo'], 'Save');
    $corrected = User::load($persisted->id());
    $this->assertInstanceOf(UserInterface::class, $corrected);
    $this->assertSame('Asia/Tokyo', $corrected->getTimezone());

    $this->drupalLogout();
    $this->drupalLogin($corrected);
    $this->assertSame('Asia/Tokyo', $timezone->effectiveTimezone());

    $this->drupalGet('/personal-secretary/activities/add');
    $this->assertSession()->fieldValueEquals('source_timezone', 'Asia/Tokyo');

    // Changing User.timezone must not rewrite existing domain temporal truth.
    $storage = $manager->getStorage('personal_sec_activity_series');
    $storage->resetCache([$seriesId]);
    $after = $storage->load($seriesId);
    $this->assertInstanceOf(ActivitySeries::class, $after);
    $recurrenceAfter = $after->get('recurrence')->first()?->getValue();
    $this->assertSame($recurrenceBefore, $recurrenceAfter);

    $keysAfter = array_map(
      static fn($occurrence): string => $occurrence->originalOccurrenceKey,
      $projection->project($after, $projectionStart, $projectionEnd),
    );
    $this->assertSame($keysBefore, $keysAfter);

    // Drupal Core's account edit route remains the authenticated correction
    // surface; no duplicate Personal Secretary preference form exists.
    $this->drupalGet('/user/' . $corrected->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldValueEquals('timezone', 'Asia/Tokyo');
  }

  public function testInvalidSiteTimezoneStillFallsBackToBrussels(): void {
    $admin = $this->drupalCreateUser([HouseholdAuthorizationService::ADMIN_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $admin);
    $this->drupalLogin($admin);

    $this->config('system.date')
      ->set('timezone.default', 'Not/A-Timezone')
      ->save();

    /** @var \Drupal\personal_secretary\Service\CurrentUserTimezoneService $timezone */
    $timezone = $this->container->get('personal_secretary.current_user_timezone');
    $this->assertFalse($timezone->isValidTimezone('Not/A-Timezone'));
    $this->assertSame('Europe/Brussels', $timezone->effectiveTimezone());
  }

}
