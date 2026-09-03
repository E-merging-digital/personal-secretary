<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Tests\BrowserTestBase;

/**
 * Proves the first read-only upcoming/preparation application surface.
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

}
