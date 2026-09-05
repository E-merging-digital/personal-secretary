<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\ResponsibilityOverride;
use Drupal\Tests\BrowserTestBase;
use InvalidArgumentException;

/**
 * Proves one-off creation through the existing Add activity flow.
 *
 * @group personal_secretary
 */
final class OneOffActivityTest extends BrowserTestBase {

  protected static $modules = ['block', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testOneOffAndWeeklyCreationShareTheExistingFlow(): void {
    $authorized = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->drupalLogin($authorized);

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\OccurrenceProjectionService $baseProjection */
    $baseProjection = $this->container->get('personal_secretary.occurrence_projection');
    /** @var \Drupal\personal_secretary\Service\EffectiveOccurrenceProjectionService $effectiveProjection */
    $effectiveProjection = $this->container->get('personal_secretary.effective_occurrence_projection');
    /** @var \Drupal\personal_secretary\Service\AddActivityService $addActivity */
    $addActivity = $this->container->get('personal_secretary.add_activity');

    $entityTypeManager = $this->container->get('entity_type.manager');
    $sourceTimezone = new DateTimeZone('Europe/Brussels');
    $utc = new DateTimeZone('UTC');
    $nowUtc = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone($utc);
    $nowLocal = $nowUtc->setTimezone($sourceTimezone);

    $person = $domain->createPerson('One-off Synthetic Person');
    $household = $domain->createHousehold('One-off Synthetic Household', [(int) $person->id()]);

    $this->drupalGet('/personal-secretary/activities/add');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('activity_type');
    $this->assertSession()->fieldExists('responsible_person_id');
    $this->assertSession()->pageTextContains('One-off');
    $this->assertSession()->pageTextContains('Weekly');
    $this->assertSession()->pageTextContains('Activity date');
    $this->assertSession()->fieldNotExists('rrule');

    $weeklyStart = $nowLocal->modify('+2 days')->setTime(9, 0);
    $this->submitForm([
      'household_id' => (string) $household->id(),
      'activity_type' => 'weekly',
      'responsible_person_id' => (string) $person->id(),
      'activity_label' => 'Synthetic weekly preserved',
      'first_occurrence_date' => $weeklyStart->format('Y-m-d'),
      'start_local_time' => '09:00',
      'end_local_time' => '10:00',
      'source_timezone' => 'Europe/Brussels',
      'preparation_instruction' => '',
      'preparation_lead_minutes' => '0',
    ], 'Add activity');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSession()->pageTextContains('Synthetic weekly preserved');

    $weeklySeries = $this->seriesByLabel('Synthetic weekly preserved');
    $this->assertSame('FREQ=WEEKLY;INTERVAL=1', $this->rrule($weeklySeries));
    $this->assertCount(1, $this->idsForSeries('personal_sec_resp_rule', (int) $weeklySeries->id()));
    $this->assertCount(0, $this->idsForSeries('personal_sec_resp_override', (int) $weeklySeries->id()));

    $oneOffWithoutResponsibilityStart = $nowLocal->modify('+3 days')->setTime(11, 0);
    $this->drupalGet('/personal-secretary/activities/add');
    $this->submitForm([
      'household_id' => (string) $household->id(),
      'activity_type' => 'one_off',
      'responsible_person_id' => '',
      'activity_label' => 'Synthetic one-off without responsibility',
      'first_occurrence_date' => $oneOffWithoutResponsibilityStart->format('Y-m-d'),
      'start_local_time' => '11:00',
      'end_local_time' => '12:00',
      'source_timezone' => 'Europe/Brussels',
      'preparation_instruction' => '',
      'preparation_lead_minutes' => '0',
    ], 'Add activity');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSession()->pageTextContains('Synthetic one-off without responsibility');

    $withoutResponsibility = $this->seriesByLabel('Synthetic one-off without responsibility');
    $this->assertSame('FREQ=DAILY;COUNT=1', $this->rrule($withoutResponsibility));
    $this->assertCount(
      1,
      $baseProjection->project(
        $withoutResponsibility,
        $nowUtc->modify('-1 day'),
        $nowUtc->modify('+10 days'),
      ),
    );
    $this->assertCount(
      1,
      $effectiveProjection->project(
        $withoutResponsibility,
        $nowUtc->modify('-1 day'),
        $nowUtc->modify('+10 days'),
      ),
    );
    $this->assertCount(0, $this->idsForSeries('personal_sec_resp_rule', (int) $withoutResponsibility->id()));
    $this->assertCount(0, $this->idsForSeries('personal_sec_resp_override', (int) $withoutResponsibility->id()));

    $assignedStart = $nowLocal->modify('+4 days')->setTime(14, 0);
    $this->drupalGet('/personal-secretary/activities/add');
    $this->submitForm([
      'household_id' => (string) $household->id(),
      'activity_type' => 'one_off',
      'responsible_person_id' => (string) $person->id(),
      'activity_label' => 'Synthetic assigned one-off',
      'first_occurrence_date' => $assignedStart->format('Y-m-d'),
      'start_local_time' => '14:00',
      'end_local_time' => '15:00',
      'source_timezone' => 'Europe/Brussels',
      'preparation_instruction' => 'Prepare synthetic one-off material',
      'preparation_lead_minutes' => '30',
    ], 'Add activity');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSession()->pageTextContains('Synthetic assigned one-off');
    $this->assertSession()->pageTextContains('One-off Synthetic Person');
    $this->assertSession()->pageTextContains('Prepare synthetic one-off material');

    $assigned = $this->seriesByLabel('Synthetic assigned one-off');
    $this->assertSame('FREQ=DAILY;COUNT=1', $this->rrule($assigned));
    $this->assertCount(
      1,
      $effectiveProjection->project(
        $assigned,
        $nowUtc->modify('-1 day'),
        $nowUtc->modify('+10 days'),
      ),
    );
    $this->assertCount(0, $this->idsForSeries('personal_sec_resp_rule', (int) $assigned->id()));

    $overrideIds = $this->idsForSeries('personal_sec_resp_override', (int) $assigned->id());
    $this->assertCount(1, $overrideIds);
    $override = $entityTypeManager->getStorage('personal_sec_resp_override')->load(reset($overrideIds));
    $this->assertNotNull($override);
    $this->assertSame(ResponsibilityOverride::ACTION_ASSIGN_PERSON, (string) $override->get('action')->value);
    $this->assertSame((int) $person->id(), (int) $override->get('responsible_person')->target_id);

    $preparationIds = $this->idsForSeries('personal_sec_prep_req', (int) $assigned->id());
    $this->assertCount(1, $preparationIds);
    $preparation = $entityTypeManager->getStorage('personal_sec_prep_req')->load(reset($preparationIds));
    $this->assertNotNull($preparation);
    $this->assertSame('Prepare synthetic one-off material', (string) $preparation->label());
    $this->assertSame(1800, (int) $preparation->get('lead_time_seconds')->value);

    $countsBeforeFailure = [
      'series' => count($entityTypeManager->getStorage('personal_sec_activity_series')->loadMultiple()),
      'rules' => count($entityTypeManager->getStorage('personal_sec_resp_rule')->loadMultiple()),
      'overrides' => count($entityTypeManager->getStorage('personal_sec_resp_override')->loadMultiple()),
      'preparations' => count($entityTypeManager->getStorage('personal_sec_prep_req')->loadMultiple()),
    ];

    $invalidStart = $nowLocal->modify('+5 days')->setTime(16, 0);
    try {
      $addActivity->addOneOffActivity(
        (int) $household->id(),
        (int) $person->id(),
        'Synthetic atomic failure one-off',
        $invalidStart,
        $invalidStart->modify('+1 hour'),
        'Invalid negative lead preparation',
        -1,
      );
      $this->fail('Invalid preparation must roll back one-off creation atomically.');
    }
    catch (InvalidArgumentException $exception) {
      $this->assertStringContainsString('lead time must be zero or greater', $exception->getMessage());
    }

    $this->assertSame($countsBeforeFailure, [
      'series' => count($entityTypeManager->getStorage('personal_sec_activity_series')->loadMultiple()),
      'rules' => count($entityTypeManager->getStorage('personal_sec_resp_rule')->loadMultiple()),
      'overrides' => count($entityTypeManager->getStorage('personal_sec_resp_override')->loadMultiple()),
      'preparations' => count($entityTypeManager->getStorage('personal_sec_prep_req')->loadMultiple()),
    ]);
    $this->assertNull($this->seriesByLabelOrNull('Synthetic atomic failure one-off'));
  }

  private function seriesByLabel(string $label): ActivitySeries {
    $series = $this->seriesByLabelOrNull($label);
    if (!$series instanceof ActivitySeries) {
      throw new \RuntimeException(sprintf('ActivitySeries %s was not found.', $label));
    }
    return $series;
  }

  private function seriesByLabelOrNull(string $label): ?ActivitySeries {
    foreach ($this->container->get('entity_type.manager')->getStorage('personal_sec_activity_series')->loadMultiple() as $series) {
      if ($series instanceof ActivitySeries && $series->label() === $label) {
        return $series;
      }
    }
    return NULL;
  }

  private function rrule(ActivitySeries $series): string {
    $item = $series->get('recurrence')->first();
    $this->assertNotNull($item);
    return (string) ($item->getValue()['rrule'] ?? '');
  }

  /**
   * @return array<int|string, int|string>
   */
  private function idsForSeries(string $entityTypeId, int $seriesId): array {
    return $this->container
      ->get('entity_type.manager')
      ->getStorage($entityTypeId)
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('series', $seriesId)
      ->execute();
  }

}
