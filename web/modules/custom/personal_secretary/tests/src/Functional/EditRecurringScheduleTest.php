<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\ResponsibilityRule;

/**
 * Proves the bounded future weekly schedule correction user flow.
 *
 * @group personal_secretary
 */
final class EditRecurringScheduleTest extends BrowserTestBase {

  protected static $modules = ['block', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testFutureWeeklyScheduleCorrection(): void {
    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\ResponsibilityMutationService $responsibilityMutations */
    $responsibilityMutations = $this->container->get('personal_secretary.responsibility_mutation');
    /** @var \Drupal\personal_secretary\Service\PreparationRequirementMutationService $preparationMutations */
    $preparationMutations = $this->container->get('personal_secretary.preparation_requirement_mutation');
    $entityTypeManager = $this->container->get('entity_type.manager');

    $utc = new DateTimeZone('UTC');
    $sourceTimezone = new DateTimeZone('Europe/Brussels');
    $nowLocal = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone($sourceTimezone);
    $firstStart = $nowLocal->modify('+1 day')->setTime(16, 0);
    $firstEnd = $firstStart->modify('+1 hour');
    $effectiveLocalMidnight = $nowLocal->modify('+2 days')->setTime(0, 0);
    $effectiveDate = $effectiveLocalMidnight->format('Y-m-d');
    $newStart = $effectiveLocalMidnight->setTime(17, 30);
    $newEnd = $effectiveLocalMidnight->setTime(18, 30);

    $person = $domain->createPerson('Synthetic Schedule Person');
    $household = $domain->createHousehold(
      'Synthetic Schedule Household',
      [(int) $person->id()],
    );
    $series = $domain->createActivitySeries(
      'Synthetic Weekly Schedule',
      (int) $household->id(),
      $firstStart,
      $firstEnd,
      'FREQ=WEEKLY;INTERVAL=1',
    );
    $rule = $responsibilityMutations->createResponsibilityRule(
      $series,
      (int) $person->id(),
      $firstStart,
      $firstEnd,
      'FREQ=WEEKLY;INTERVAL=1',
    );
    $preparationMutations->createPreparationRequirement(
      $series,
      'Prepare synthetic schedule equipment',
      3600,
      $firstStart,
    );

    $seriesId = (int) $series->id();
    $ruleId = (int) $rule->id();
    $seriesStorage = $entityTypeManager->getStorage('personal_sec_activity_series');
    $ruleStorage = $entityTypeManager->getStorage('personal_sec_resp_rule');
    $preparationStorage = $entityTypeManager->getStorage('personal_sec_prep_req');
    $initialSeriesCount = count($seriesStorage->loadMultiple());
    $initialSeriesRevision = (string) $series->getRevisionId();
    $initialRuleRevision = (string) $rule->getRevisionId();
    $initialRuleCount = count($ruleStorage->loadMultiple());
    $initialPreparationCount = count($preparationStorage->loadMultiple());

    $editUrl = Url::fromRoute(
      'personal_secretary.edit_recurring_schedule',
      ['series' => $seriesId],
    )->toString();

    $this->drupalGet($editUrl);
    $this->assertSession()->statusCodeEquals(403);

    $authorized = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->drupalLogin($authorized);

    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Change recurring schedule');
    $this->assertSession()->linkByHrefExists($editUrl);

    $this->drupalGet($editUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Synthetic Weekly Schedule');
    $this->assertSession()->pageTextContains('Current weekly schedule');
    $this->assertSession()->pageTextContains('Weekly');
    $this->assertSession()->pageTextContains('Europe/Brussels');
    $this->assertSession()->pageTextContains('Synthetic Schedule Person');
    $this->assertSession()->pageTextContains('may need to be redone');
    $this->assertSession()->fieldExists('effective_from_date');
    $this->assertSession()->fieldExists('new_start_local_time');
    $this->assertSession()->fieldExists('new_end_local_time');
    $this->assertSession()->fieldNotExists('source_timezone');
    $this->assertSession()->fieldNotExists('rrule');

    $seriesAfterGet = $seriesStorage->load($seriesId);
    $ruleAfterGet = $ruleStorage->load($ruleId);
    $this->assertInstanceOf(ActivitySeries::class, $seriesAfterGet);
    $this->assertInstanceOf(ResponsibilityRule::class, $ruleAfterGet);
    $this->assertSame($initialSeriesRevision, (string) $seriesAfterGet->getRevisionId());
    $this->assertSame($initialRuleRevision, (string) $ruleAfterGet->getRevisionId());
    $this->assertCount($initialRuleCount, $ruleStorage->loadMultiple());

    // Real rejected form input proves fail-closed behavior before any durable
    // series/rule mutation; no artificial post-write failure is introduced.
    $this->submitForm([
      'effective_from_date' => $nowLocal->format('Y-m-d'),
      'new_start_local_time' => '17:30',
      'new_end_local_time' => '18:30',
    ], 'Save recurring schedule');
    $this->assertSession()->pageTextContains('The recurring schedule change is not valid');
    $seriesAfterRejected = $seriesStorage->load($seriesId);
    $ruleAfterRejected = $ruleStorage->load($ruleId);
    $this->assertInstanceOf(ActivitySeries::class, $seriesAfterRejected);
    $this->assertInstanceOf(ResponsibilityRule::class, $ruleAfterRejected);
    $this->assertSame($initialSeriesRevision, (string) $seriesAfterRejected->getRevisionId());
    $this->assertSame($initialRuleRevision, (string) $ruleAfterRejected->getRevisionId());
    $this->assertCount($initialRuleCount, $ruleStorage->loadMultiple());

    $this->submitForm([
      'effective_from_date' => $effectiveDate,
      'new_start_local_time' => '17:30',
      'new_end_local_time' => '18:30',
    ], 'Save recurring schedule');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);

    $updatedSeries = $seriesStorage->load($seriesId);
    $this->assertInstanceOf(ActivitySeries::class, $updatedSeries);
    $this->assertSame($seriesId, (int) $updatedSeries->id());
    $this->assertCount($initialSeriesCount, $seriesStorage->loadMultiple());
    $updatedSeriesRevision = (string) $updatedSeries->getRevisionId();
    $this->assertNotSame($initialSeriesRevision, $updatedSeriesRevision);
    $historicalSeries = $seriesStorage->loadRevision((int) $initialSeriesRevision);
    $this->assertInstanceOf(ActivitySeries::class, $historicalSeries);

    $expectedBoundaryStorage = $effectiveLocalMidnight
      ->setTimezone($utc)
      ->format('Y-m-d\\TH:i:s');
    $this->assertSame(
      $expectedBoundaryStorage,
      (string) $updatedSeries->get('effective_from')->value,
    );
    $seriesRecurrenceItem = $updatedSeries->get('recurrence')->first();
    $this->assertNotNull($seriesRecurrenceItem);
    $seriesRecurrence = $seriesRecurrenceItem->getValue();
    $this->assertSame('FREQ=WEEKLY;INTERVAL=1', $seriesRecurrence['rrule']);
    $this->assertSame('Europe/Brussels', $seriesRecurrence['timezone']);
    $this->assertSame(
      $newStart->setTimezone($utc)->format('Y-m-d\\TH:i:s'),
      $seriesRecurrence['value'],
    );
    $this->assertSame(
      $newEnd->setTimezone($utc)->format('Y-m-d\\TH:i:s'),
      $seriesRecurrence['end_value'],
    );

    $retiredRule = $ruleStorage->load($ruleId);
    $this->assertInstanceOf(ResponsibilityRule::class, $retiredRule);
    $this->assertNotSame($initialRuleRevision, (string) $retiredRule->getRevisionId());
    $this->assertSame($expectedBoundaryStorage, (string) $retiredRule->get('effective_until')->value);
    $historicalRule = $ruleStorage->loadRevision((int) $initialRuleRevision);
    $this->assertInstanceOf(ResponsibilityRule::class, $historicalRule);
    $this->assertTrue($historicalRule->get('effective_until')->isEmpty());

    $allRules = array_values($ruleStorage->loadMultiple());
    $this->assertCount($initialRuleCount + 1, $allRules);
    $currentRules = array_values(array_filter(
      $allRules,
      static fn($candidate): bool => $candidate instanceof ResponsibilityRule
        && (int) $candidate->get('series')->target_id === $seriesId
        && $candidate->get('effective_until')->isEmpty(),
    ));
    $this->assertCount(1, $currentRules);
    $replacementRule = $currentRules[0];
    $this->assertNotSame($ruleId, (int) $replacementRule->id());
    $this->assertSame((int) $person->id(), (int) $replacementRule->get('responsible_person')->target_id);
    $replacementRecurrenceItem = $replacementRule->get('recurrence')->first();
    $this->assertNotNull($replacementRecurrenceItem);
    $replacementRecurrence = $replacementRecurrenceItem->getValue();
    $this->assertSame('FREQ=WEEKLY;INTERVAL=1', $replacementRecurrence['rrule']);
    $this->assertSame('Europe/Brussels', $replacementRecurrence['timezone']);
    $this->assertSame($seriesRecurrence['value'], $replacementRecurrence['value']);
    $this->assertSame($seriesRecurrence['end_value'], $replacementRecurrence['end_value']);

    $this->assertCount($initialPreparationCount, $preparationStorage->loadMultiple());
    $this->assertSession()->pageTextContains($firstStart->format('Y-m-d H:i'));
    $this->assertSession()->pageTextContains($newStart->format('Y-m-d H:i'));
    $this->assertSession()->pageTextContains('Synthetic Schedule Person');
    $this->assertSession()->pageTextContains('Prepare synthetic schedule equipment');
    $this->assertSession()->pageTextContains($newStart->modify('-1 hour')->format('Y-m-d H:i'));
  }

}
