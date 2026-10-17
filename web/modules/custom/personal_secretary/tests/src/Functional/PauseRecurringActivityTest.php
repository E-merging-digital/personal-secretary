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
    $secondStart = $recurringStart->modify('+7 days');
    $secondBase = $timeline->projectBaseWindow(
      $recurring,
      $secondStart->setTimezone($utc)->modify('-1 second'),
      $secondStart->modify('+1 day')->setTimezone($utc),
    );
    $this->assertCount(1, $secondBase);
    $secondTargetKey = $secondBase[0]->originalOccurrenceKey;

    $seriesStorage = $entityTypeManager->getStorage('personal_sec_activity_series');
    $exceptionStorage = $entityTypeManager->getStorage('personal_sec_activity_exception');
    $ruleStorage = $entityTypeManager->getStorage('personal_sec_resp_rule');
    $overrideStorage = $entityTypeManager->getStorage('personal_sec_resp_override');
    $recurringId = (int) $recurring->id();
    $oneOffId = (int) $oneOff->id();
    $initialRecurringRevision = (string) $recurring->getRevisionId();
    $initialOneOffRevision = (string) $oneOff->getRevisionId();
    $initialSeriesCount = count($seriesStorage->loadMultiple());
    $initialExceptionCount = count($exceptionStorage->loadMultiple());
    $initialRuleCount = count($ruleStorage->loadMultiple());
    $initialOverrideCount = count($overrideStorage->loadMultiple());
    $upcomingUrl = Url::fromRoute('personal_secretary.upcoming')->toString();

    $assertNoPauseMutation = function () use (
      $seriesStorage,
      $exceptionStorage,
      $ruleStorage,
      $overrideStorage,
      $recurringId,
      $oneOffId,
      $initialRecurringRevision,
      $initialOneOffRevision,
      $initialSeriesCount,
      $initialExceptionCount,
      $initialRuleCount,
      $initialOverrideCount,
    ): void {
      $seriesStorage->resetCache([$recurringId, $oneOffId]);
      $this->assertSame($initialRecurringRevision, (string) $seriesStorage->load($recurringId)?->getRevisionId());
      $this->assertSame($initialOneOffRevision, (string) $seriesStorage->load($oneOffId)?->getRevisionId());
      $this->assertCount($initialSeriesCount, $seriesStorage->loadMultiple());
      $this->assertCount($initialExceptionCount, $exceptionStorage->loadMultiple());
      $this->assertCount($initialRuleCount, $ruleStorage->loadMultiple());
      $this->assertCount($initialOverrideCount, $overrideStorage->loadMultiple());
    };

    $this->drupalGet($recurringPauseUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('pause_start_date');
    $this->assertSession()->fieldExists('pause_end_date');
    $this->assertSession()->pageTextContains('Europe/Brussels');
    $this->assertSession()->buttonExists('Preview pause');

    $this->assertSession()->pageTextContains('Previewing does not save changes.');
    $this->assertSession()->pageTextContains('occurrences outside the range keep their existing schedule');
    $cancelLinks = $this->getSession()->getPage()->findAll('css', 'form a[href="' . $upcomingUrl . '"]');
    $this->assertCount(1, $cancelLinks);
    $this->assertSame('Cancel', trim($cancelLinks[0]->getText()));
    $this->clickLink('Cancel');
    $this->assertSession()->addressEquals($upcomingUrl);
    $this->assertSession()->statusCodeEquals(200);
    $assertNoPauseMutation();

    // Return to the range step for the existing preview/confirmation flow.
    $this->drupalGet($recurringPauseUrl);
    $this->assertSession()->statusCodeEquals(200);

    $pauseDate = $recurringStart->format('Y-m-d');
    $pauseEndDate = $secondStart->format('Y-m-d');
    $previewBefore = $this->container->get('personal_secretary.pause_recurring_activity')
      ->preview($recurringId, $pauseDate, $pauseEndDate);
    $this->assertSame(2, $previewBefore['occurrences_to_cancel']);
    $this->assertCount(2, $previewBefore['eligible_occurrences']);
    $this->assertSame(0, $previewBefore['already_cancelled']);
    $this->assertSame('none', $previewBefore['conflict']);
    $this->assertSame($recurringStart->format('Y-m-d H:i'), (new DateTimeImmutable($previewBefore['eligible_occurrences'][0]['start']))->setTimezone($timezone)->format('Y-m-d H:i'));
    $this->assertSame($secondStart->format('Y-m-d H:i'), (new DateTimeImmutable($previewBefore['eligible_occurrences'][1]['start']))->setTimezone($timezone)->format('Y-m-d H:i'));
    $this->assertArrayNotHasKey('originalOccurrenceKey', $previewBefore['eligible_occurrences'][0]);
    $this->assertArrayNotHasKey('seriesRevisionId', $previewBefore['eligible_occurrences'][0]);
    $assertNoPauseMutation();
    $this->submitForm([
      'pause_start_date' => $pauseDate,
      'pause_end_date' => $pauseEndDate,
    ], 'Preview pause');
    $this->assertSession()->pageTextContains('Occurrences to cancel');
    $this->assertSession()->pageTextContains('Already cancelled');
    $this->assertSession()->buttonExists('Confirm pause');
    $this->assertSession()->buttonExists('Change dates');
    $this->assertSession()->pageTextContains('Preview only: no occurrence has been cancelled.');
    $this->assertSession()->pageTextContains('Eligible occurrences in the selected range (not yet cancelled)');
    $this->assertSession()->pageTextContains('Confirmation will recheck the saved activity and targets.');
    $shown = $this->getSession()->getPage()->findAll('css', 'table[data-ps-pause-targets="eligible"] tbody tr');
    $this->assertCount(2, $shown);
    $this->assertSame($previewBefore['occurrences_to_cancel'], count($shown));
    $this->assertStringContainsString($recurringStart->format('Y-m-d H:i'), $shown[0]->getText());
    $this->assertStringContainsString($secondStart->format('Y-m-d H:i'), $shown[1]->getText());
    $this->assertSession()->pageTextNotContains($targetKey);
    $this->assertSession()->pageTextNotContains($secondTargetKey);
    $assertNoPauseMutation();

    $this->assertSession()->pageTextContains('Previewing does not save changes.');
    $this->assertSession()->pageTextContains('occurrences outside the range keep their existing schedule');
    $cancelLinks = $this->getSession()->getPage()->findAll('css', 'form a[href="' . $upcomingUrl . '"]');
    $this->assertCount(1, $cancelLinks);
    $this->assertSame('Cancel', trim($cancelLinks[0]->getText()));
    $this->clickLink('Cancel');
    $this->assertSession()->addressEquals($upcomingUrl);
    $this->assertSession()->statusCodeEquals(200);
    $assertNoPauseMutation();

    // A fresh preview still permits the original explicit confirmation.
    $this->drupalGet($recurringPauseUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([
      'pause_start_date' => $pauseDate,
      'pause_end_date' => $pauseEndDate,
    ], 'Preview pause');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->buttonExists('Confirm pause');
    $this->assertCount(2, $this->getSession()->getPage()->findAll('css', 'table[data-ps-pause-targets="eligible"] tbody tr'));
    $assertNoPauseMutation();

    $this->submitForm([], 'Confirm pause');

    $errorLogPath = $this->siteDirectory . '/error.log';
    $errorLog = is_readable($errorLogPath) ? file_get_contents($errorLogPath) : FALSE;
    $diagnostic = sprintf(
      "Confirm pause diagnostic:\nURL: %s\nHTTP: %d\nResponse:\n%s\nError log:\n%s",
      $this->getSession()->getCurrentUrl(),
      $this->getSession()->getStatusCode(),
      $this->getSession()->getPage()->getContent(),
      is_string($errorLog) ? $errorLog : '[not readable]',
    );

    $created = $exceptionStorage->loadByProperties([
      'series' => $recurring->id(),
      'action' => ActivityException::ACTION_CANCEL,
      'status' => ActivityException::STATUS_ACTIVE,
    ]);
    $this->assertCount(2, $created, $diagnostic);
    $createdKeys = [];
    foreach ($created as $exception) {
      $this->assertInstanceOf(ActivityException::class, $exception);
      $this->assertSame((string) $recurring->getRevisionId(), (string) $exception->get('target_revision_id')->value);
      $createdKeys[] = (string) $exception->get('original_occurrence_key')->value;
    }
    sort($createdKeys);
    $expectedKeys = [$targetKey, $secondTargetKey];
    sort($expectedKeys);
    $this->assertSame($expectedKeys, $createdKeys);
    $this->assertSame(ActivitySeries::TIME_MODE_TIMED, $recurring->timeMode());

    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSame(200, $this->getSession()->getStatusCode(), $diagnostic);
    $this->assertSession()->pageTextContains('Pause applied');
    $this->assertSession()->pageTextNotContains('Synthetic recurring pause UI');
    $this->assertSession()->pageTextContains('Synthetic one-off pause UI');

    $remaining = $effective->project(
      $recurring,
      $recurringStart->setTimezone($utc)->modify('-1 second'),
      $secondStart->modify('+1 day')->setTimezone($utc),
    );
    $this->assertCount(0, $remaining);

    // After confirmation the eligible list is empty, cancellations are counted separately.
    $after = $this->container->get('personal_secretary.pause_recurring_activity')
      ->preview($recurringId, $pauseDate, $pauseEndDate);
    $this->assertSame(0, $after['occurrences_to_cancel']);
    $this->assertSame([], $after['eligible_occurrences']);
    $this->assertSame(2, $after['already_cancelled']);
  }

}
