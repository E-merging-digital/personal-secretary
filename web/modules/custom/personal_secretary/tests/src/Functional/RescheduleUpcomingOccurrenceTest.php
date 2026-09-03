<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\personal_secretary\Entity\ActivityException;

/**
 * Proves one exact Upcoming occurrence can be rescheduled through Form API.
 *
 * @group personal_secretary
 */
final class RescheduleUpcomingOccurrenceTest extends BrowserTestBase {

  protected static $modules = ['block', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testRescheduleUpcomingOccurrenceFlow(): void {
    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\OccurrenceProjectionService $baseProjection */
    $baseProjection = $this->container->get('personal_secretary.occurrence_projection');
    /** @var \Drupal\personal_secretary\Service\ResponsibilityMutationService $responsibilityMutations */
    $responsibilityMutations = $this->container->get('personal_secretary.responsibility_mutation');
    /** @var \Drupal\personal_secretary\Service\PreparationRequirementMutationService $preparationMutations */
    $preparationMutations = $this->container->get('personal_secretary.preparation_requirement_mutation');
    $entityTypeManager = $this->container->get('entity_type.manager');

    $utc = new DateTimeZone('UTC');
    $sourceTimezone = new DateTimeZone('Europe/Brussels');
    $nowUtc = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone($utc);
    $nowLocal = $nowUtc->setTimezone($sourceTimezone);
    $originalStartLocal = $nowLocal->modify('+2 days')->setTime(10, 0);
    $originalEndLocal = $originalStartLocal->modify('+1 hour');
    $originalStartUtc = $originalStartLocal->setTimezone($utc);
    $originalEndUtc = $originalEndLocal->setTimezone($utc);

    $person = $domain->createPerson('Riley Reschedule');
    $household = $domain->createHousehold('Reschedule Household', [(int) $person->id()]);
    $series = $domain->createActivitySeries(
      'Synthetic reschedule activity',
      (int) $household->id(),
      $originalStartLocal,
      $originalEndLocal,
      'FREQ=DAILY;COUNT=1',
    );
    $responsibilityMutations->createResponsibilityRule(
      $series,
      (int) $person->id(),
      $originalStartLocal->setTime(9, 0),
      $originalStartLocal->setTime(12, 0),
      'FREQ=DAILY;COUNT=1',
    );
    $preparationMutations->createPreparationRequirement(
      $series,
      'Pack reschedule equipment',
      3600,
      $nowUtc->modify('-1 day'),
    );

    $baseOccurrence = $baseProjection->project(
      $series,
      $originalStartUtc->modify('-1 minute'),
      $originalEndUtc->modify('+1 minute'),
    )[0];
    $rescheduleUrl = Url::fromRoute(
      'personal_secretary.reschedule_occurrence',
      [
        'series' => (int) $series->id(),
        'original_occurrence_key' => $baseOccurrence->originalOccurrenceKey,
      ],
    )->toString();

    $this->drupalGet($rescheduleUrl);
    $this->assertSession()->statusCodeEquals(403);

    $authorized = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->drupalLogin($authorized);

    $oldDisplay = $originalStartLocal->format('Y-m-d H:i');
    $oldDueDisplay = $originalStartLocal->modify('-1 hour')->format('Y-m-d H:i');
    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Synthetic reschedule activity');
    $this->assertSession()->pageTextContains('Riley Reschedule');
    $this->assertSession()->pageTextContains('Pack reschedule equipment');
    $this->assertSession()->pageTextContains($oldDisplay);
    $this->assertSession()->pageTextContains($oldDueDisplay);
    $this->assertSession()->linkExists('Reschedule occurrence');
    $this->assertSession()->linkByHrefExists($rescheduleUrl);

    $seriesCount = count($entityTypeManager->getStorage('personal_sec_activity_series')->loadMultiple());
    $this->assertCount(0, $entityTypeManager->getStorage('personal_sec_activity_exception')->loadMultiple());

    $this->drupalGet($rescheduleUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Synthetic reschedule activity');
    $this->assertSession()->pageTextContains('Source timezone');
    $this->assertSession()->pageTextContains('Europe/Brussels');
    $this->assertSession()->fieldExists('new_date');
    $this->assertSession()->fieldExists('new_local_start_time');
    $this->assertSession()->fieldExists('new_local_end_time');
    $this->assertSession()->fieldNotExists('source_timezone');
    $this->assertSession()->fieldValueEquals('new_date', $originalStartLocal->format('Y-m-d'));
    $this->assertSession()->fieldValueEquals('new_local_start_time', '10:00');
    $this->assertSession()->fieldValueEquals('new_local_end_time', '11:00');
    $this->assertSession()->buttonExists('Reschedule occurrence');
    $this->assertCount(0, $entityTypeManager->getStorage('personal_sec_activity_exception')->loadMultiple());

    $newDate = $originalStartLocal->format('Y-m-d');
    $newLocalStart = new DateTimeImmutable($newDate . ' 10:30:00', $sourceTimezone);
    $newLocalEnd = new DateTimeImmutable($newDate . ' 11:30:00', $sourceTimezone);
    $newDisplay = $newLocalStart->format('Y-m-d H:i');
    $newDueDisplay = $newLocalStart->modify('-1 hour')->format('Y-m-d H:i');

    $this->submitForm([
      'new_date' => $newDate,
      'new_local_start_time' => '10:30',
      'new_local_end_time' => '11:30',
    ], 'Reschedule occurrence');

    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Synthetic reschedule activity');
    $this->assertSession()->pageTextContains('Riley Reschedule');
    $this->assertSession()->pageTextContains('Pack reschedule equipment');
    $this->assertSession()->pageTextNotContains($oldDisplay);
    $this->assertSession()->pageTextNotContains($oldDueDisplay);
    $this->assertSession()->pageTextContains($newDisplay);
    $this->assertSession()->pageTextContains($newDueDisplay);
    $this->assertSession()->linkNotExists('Reschedule occurrence');
    $this->assertSession()->linkNotExists('Cancel occurrence');

    $this->assertCount(
      $seriesCount,
      $entityTypeManager->getStorage('personal_sec_activity_series')->loadMultiple(),
    );
    $activityExceptions = array_values($entityTypeManager
      ->getStorage('personal_sec_activity_exception')
      ->loadMultiple());
    $this->assertCount(1, $activityExceptions);
    $this->assertInstanceOf(ActivityException::class, $activityExceptions[0]);
    $this->assertSame(ActivityException::ACTION_RESCHEDULE, (string) $activityExceptions[0]->get('action')->value);
    $this->assertSame(ActivityException::STATUS_ACTIVE, (string) $activityExceptions[0]->get('status')->value);
    $this->assertSame((int) $series->id(), (int) $activityExceptions[0]->get('series')->target_id);
    $this->assertSame((int) $baseOccurrence->seriesRevisionId, (int) $activityExceptions[0]->get('target_revision_id')->value);
    $this->assertSame($baseOccurrence->originalOccurrenceKey, (string) $activityExceptions[0]->get('original_occurrence_key')->value);
    $this->assertSame('Europe/Brussels', (string) $activityExceptions[0]->get('source_timezone')->value);
    $this->assertSame(
      $newLocalStart->setTimezone($utc)->format('Y-m-d\\TH:i:s'),
      (string) $activityExceptions[0]->get('rescheduled_utc_start')->value,
    );
    $this->assertSame(
      $newLocalEnd->setTimezone($utc)->format('Y-m-d\\TH:i:s'),
      (string) $activityExceptions[0]->get('rescheduled_utc_end')->value,
    );
    $this->assertCount(0, $entityTypeManager->getStorage('personal_sec_resp_override')->loadMultiple());
  }

}
