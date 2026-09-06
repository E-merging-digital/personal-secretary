<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Url;
use Drupal\personal_secretary\Entity\ActivityException;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;

/**
 * Proves the bounded pause user surface and recurring-only guard.
 *
 * @group personal_secretary
 */
final class PauseRecurringActivityTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testRecurringPausePreviewConfirmAndOneOffGuard(): void {
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $timeline = $this->container->get('personal_secretary.revision_timeline');
    $effective = $this->container->get('personal_secretary.effective_occurrence_projection');
    $entityTypeManager = $this->container->get('entity_type.manager');

    $timezone = new DateTimeZone('Europe/Brussels');
    $utc = new DateTimeZone('UTC');
    $nowUtc = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))->setTimezone($utc);
    $nowLocal = $nowUtc->setTimezone($timezone);

    $person = $domain->createPerson('Synthetic pause UI person');
    $household = $domain->createHousehold('Synthetic pause UI household', [(int) $person->id()]);
    $recurringStart = $nowLocal->modify('+1 day')->setTime(9, 0, 0);
    $recurring = $domain->createActivitySeries(
      'Synthetic recurring pause UI',
      (int) $household->id(),
      $recurringStart,
      $recurringStart->modify('+1 hour'),
      'FREQ=WEEKLY;INTERVAL=1',
    );
    $oneOffStart = $nowLocal->modify('+2 days')->setTime(11, 0, 0);
    $oneOff = $domain->createActivitySeries(
      'Synthetic one-off pause UI',
      (int) $household->id(),
      $oneOffStart,
      $oneOffStart->modify('+1 hour'),
      'FREQ=DAILY;COUNT=1',
    );

    $admin = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->assertInstanceOf(UserInterface::class, $admin);
    $this->drupalLogin($admin);

    $recurringPauseUrl = Url::fromRoute('personal_secretary.pause_recurring_activity', [
      'series' => (int) $recurring->id(),
    ])->toString();
    $oneOffPauseUrl = Url::fromRoute('personal_secretary.pause_recurring_activity', [
      'series' => (int) $oneOff->id(),
    ])->toString();

    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Synthetic recurring pause UI');
    $this->assertSession()->pageTextContains('Synthetic one-off pause UI');
    $this->assertSession()->linkByHrefExists($recurringPauseUrl);
    $this->assertSession()->linkByHrefNotExists($oneOffPauseUrl);

    $this->drupalGet($oneOffPauseUrl);
    $this->assertSession()->statusCodeEquals(404);

    $base = $timeline->projectBaseWindow(
      $recurring,
      $recurringStart->setTimezone($utc)->modify('-1 second'),
      $recurringStart->modify('+1 day')->setTimezone($utc),
    );
    $this->assertCount(1, $base);
    $targetKey = $base[0]->originalOccurrenceKey;

    $this->drupalGet($recurringPauseUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('pause_start_date');
    $this->assertSession()->fieldExists('pause_end_date');
    $this->assertSession()->pageTextContains('Europe/Brussels');

    $pauseDate = $recurringStart->format('Y-m-d');
    $this->submitForm([
      'pause_start_date' => $pauseDate,
      'pause_end_date' => $pauseDate,
    ], 'Preview pause');
    $this->assertSession()->pageTextContains('Occurrences to cancel');
    $this->assertSession()->pageTextContains('Already cancelled');
    $this->assertSession()->buttonExists('Confirm pause');

    $this->submitForm([], 'Confirm pause');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Pause applied');
    $this->assertSession()->pageTextNotContains('Synthetic recurring pause UI');
    $this->assertSession()->pageTextContains('Synthetic one-off pause UI');

    $exceptionStorage = $entityTypeManager->getStorage('personal_sec_activity_exception');
    $created = $exceptionStorage->loadByProperties([
      'series' => $recurring->id(),
      'action' => ActivityException::ACTION_CANCEL,
      'status' => ActivityException::STATUS_ACTIVE,
    ]);
    $this->assertCount(1, $created);
    $exception = reset($created);
    $this->assertInstanceOf(ActivityException::class, $exception);
    $this->assertSame($targetKey, (string) $exception->get('original_occurrence_key')->value);
    $this->assertSame((string) $recurring->getRevisionId(), (string) $exception->get('target_revision_id')->value);
    $this->assertSame(ActivitySeries::TIME_MODE_TIMED, $recurring->timeMode());

    $remaining = $effective->project(
      $recurring,
      $recurringStart->setTimezone($utc)->modify('-1 second'),
      $recurringStart->modify('+1 day')->setTimezone($utc),
    );
    $this->assertCount(0, $remaining);
  }

}
