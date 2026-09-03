<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Tests\BrowserTestBase;
use Drupal\personal_secretary\Entity\ActivitySeries;

/**
 * Proves the first read-only and onboarding application surfaces.
 *
 * @group personal_secretary
 */
final class UpcomingViewTest extends BrowserTestBase {

  protected static $modules = ['block', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testUpcomingAccessEmptyAndEffectiveRendering(): void {
    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(403);

    $authorized = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->drupalLogin($authorized);
    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('No upcoming activities in the next 7 days.');
    $this->assertSession()->linkExists('Add your first activity');
    $this->assertSession()->linkByHrefExists('/personal-secretary/setup');

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\OccurrenceProjectionService $baseProjection */
    $baseProjection = $this->container->get('personal_secretary.occurrence_projection');
    /** @var \Drupal\personal_secretary\Service\EffectiveOccurrenceProjectionService $effectiveProjection */
    $effectiveProjection = $this->container->get('personal_secretary.effective_occurrence_projection');
    /** @var \Drupal\personal_secretary\Service\ActivityExceptionService $exceptions */
    $exceptions = $this->container->get('personal_secretary.activity_exception');
    /** @var \Drupal\personal_secretary\Service\ResponsibilityMutationService $responsibilityMutations */
    $responsibilityMutations = $this->container->get('personal_secretary.responsibility_mutation');
    /** @var \Drupal\personal_secretary\Service\PreparationRequirementMutationService $preparationMutations */
    $preparationMutations = $this->container->get('personal_secretary.preparation_requirement_mutation');

    $utc = new DateTimeZone('UTC');
    $sourceTimezone = new DateTimeZone('Europe/Brussels');
    $now = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone($utc);
    $windowEnd = $now->modify('+7 days');

    $person = $domain->createPerson('Parker Browser');
    $household = $domain->createHousehold('Browser Household', [(int) $person->id()]);

    $originalStartUtc = $now->modify('+2 days')->setTime(9, 0);
    $originalEndUtc = $originalStartUtc->modify('+1 hour');
    $series = $domain->createActivitySeries(
      'Synthetic upcoming activity',
      (int) $household->id(),
      $originalStartUtc->setTimezone($sourceTimezone),
      $originalEndUtc->setTimezone($sourceTimezone),
      'FREQ=DAILY;COUNT=1',
    );
    $baseOccurrence = $baseProjection->project(
      $series,
      $originalStartUtc->modify('-1 minute'),
      $originalEndUtc->modify('+1 minute'),
    )[0];

    $rescheduledStartUtc = $originalStartUtc->modify('+4 hours');
    $rescheduledEndUtc = $rescheduledStartUtc->modify('+1 hour');
    $exceptions->createReschedule(
      $series,
      $baseOccurrence,
      $rescheduledStartUtc,
      $rescheduledEndUtc,
      'Europe/Brussels',
    );
    $effectiveOccurrence = $effectiveProjection->project($series, $now, $windowEnd)[0];
    $responsibilityMutations->createAssignOverride(
      $series,
      $effectiveOccurrence,
      (int) $person->id(),
    );
    $preparationMutations->createPreparationRequirement(
      $series,
      'Pack synthetic equipment',
      3600,
      $now->modify('-1 day'),
    );

    $cancelStartUtc = $now->modify('+3 days')->setTime(10, 0);
    $cancelEndUtc = $cancelStartUtc->modify('+1 hour');
    $cancelledSeries = $domain->createActivitySeries(
      'Synthetic cancelled activity',
      (int) $household->id(),
      $cancelStartUtc->setTimezone($sourceTimezone),
      $cancelEndUtc->setTimezone($sourceTimezone),
      'FREQ=DAILY;COUNT=1',
    );
    $cancelTarget = $baseProjection->project(
      $cancelledSeries,
      $cancelStartUtc->modify('-1 minute'),
      $cancelEndUtc->modify('+1 minute'),
    )[0];
    $exceptions->createCancel($cancelledSeries, $cancelTarget);

    $rescheduledLocalDisplay = $rescheduledStartUtc->setTimezone($sourceTimezone)->format('Y-m-d H:i');
    $originalLocalDisplay = $originalStartUtc->setTimezone($sourceTimezone)->format('Y-m-d H:i');
    $dueLocalDisplay = $rescheduledStartUtc->modify('-1 hour')->setTimezone($sourceTimezone)->format('Y-m-d H:i');

    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('No upcoming activities in the next 7 days.');
    $this->assertSession()->pageTextContains('Synthetic upcoming activity');
    $this->assertSession()->pageTextContains('Parker Browser');
    $this->assertSession()->pageTextContains('Pack synthetic equipment');
    $this->assertSession()->pageTextContains($rescheduledLocalDisplay);
    $this->assertSession()->pageTextContains($dueLocalDisplay);
    $this->assertSession()->pageTextContains('Europe/Brussels');
    $this->assertSession()->pageTextNotContains($originalLocalDisplay);
    $this->assertSession()->pageTextNotContains('Synthetic cancelled activity');
  }

  public function testFirstActivitySetupFlow(): void {
    $this->drupalGet('/personal-secretary/setup');
    $this->assertSession()->statusCodeEquals(403);

    $authorized = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->drupalLogin($authorized);
    $this->drupalGet('/personal-secretary/setup');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('household_name');
    $this->assertSession()->fieldExists('responsible_person_name');
    $this->assertSession()->fieldExists('activity_label');
    $this->assertSession()->fieldExists('first_occurrence_date');
    $this->assertSession()->fieldExists('start_local_time');
    $this->assertSession()->fieldExists('end_local_time');
    $this->assertSession()->fieldExists('source_timezone');
    $this->assertSession()->fieldExists('preparation_instruction');
    $this->assertSession()->fieldExists('preparation_lead_minutes');
    $this->assertSession()->pageTextContains('Weekly');
    $this->assertSession()->fieldNotExists('rrule');

    $sourceTimezone = new DateTimeZone('Europe/Brussels');
    $nowLocal = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone($sourceTimezone);
    $firstDate = $nowLocal->modify('+2 days')->format('Y-m-d');
    $localStart = new DateTimeImmutable($firstDate . ' 09:00:00', $sourceTimezone);
    $dueDisplay = $localStart->modify('-60 minutes')->format('Y-m-d H:i');

    $this->submitForm([
      'household_name' => 'Synthetic Setup Household',
      'responsible_person_name' => 'Synthetic Responsible Person',
      'activity_label' => 'Synthetic Weekly Activity',
      'first_occurrence_date' => $firstDate,
      'start_local_time' => '09:00',
      'end_local_time' => '10:00',
      'source_timezone' => 'Europe/Brussels',
      'preparation_instruction' => 'Pack synthetic weekly equipment',
      'preparation_lead_minutes' => '60',
    ], 'Create first activity');

    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Synthetic Weekly Activity');
    $this->assertSession()->pageTextContains('Synthetic Responsible Person');
    $this->assertSession()->pageTextContains('Pack synthetic weekly equipment');
    $this->assertSession()->pageTextContains($dueDisplay);

    $entityTypeManager = $this->container->get('entity_type.manager');
    $series = array_values($entityTypeManager
      ->getStorage('personal_sec_activity_series')
      ->loadMultiple());
    $this->assertCount(1, $series);
    $this->assertInstanceOf(ActivitySeries::class, $series[0]);
    $recurrenceItem = $series[0]->get('recurrence')->first();
    $this->assertNotNull($recurrenceItem);
    $recurrence = $recurrenceItem->getValue();
    $this->assertSame('FREQ=WEEKLY;INTERVAL=1', $recurrence['rrule']);
    $this->assertSame('Europe/Brussels', $recurrence['timezone']);
    $this->assertCount(1, $entityTypeManager->getStorage('personal_secretary_person')->loadMultiple());
    $this->assertCount(1, $entityTypeManager->getStorage('personal_secretary_household')->loadMultiple());
    $this->assertCount(1, $entityTypeManager->getStorage('personal_sec_resp_rule')->loadMultiple());
    $this->assertCount(1, $entityTypeManager->getStorage('personal_sec_prep_req')->loadMultiple());
  }

}
