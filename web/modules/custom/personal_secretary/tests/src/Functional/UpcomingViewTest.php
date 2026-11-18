<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\personal_secretary\Entity\ActivityException;
use Drupal\personal_secretary\Entity\ActivitySeries;

/**
 * Proves the read-only, onboarding, repeatable activity, and cancellation surfaces.
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
    $this->assertSession()->elementNotExists('css', '.ps-upcoming-actions__add');

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

    // Empty existing context still has one primary CTA, before the empty text.
    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->pageTextContains('No upcoming activities in the next 7 days.');
    $this->assertSession()->linkByHrefExists('/personal-secretary/activities/add');
    $this->assertCount(1, $this->getSession()->getPage()->findAll('css', 'a.ps-upcoming-actions__add'));
    $this->assertSession()->elementExists('css', '.ps-upcoming-actions ~ p');
    $this->assertCount(4, $this->getSession()->getPage()->findAll('css', '.ps-action-group--household .ps-action-group__links > a.ps-action-link'));
    $this->assertSession()->elementExists('css', '.ps-action-group--household .ps-action-group__links > a.ps-action-link[href="/personal-secretary/households/rename"]');

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

    $rescheduledLocalDisplay = $rescheduledStartUtc->setTimezone($sourceTimezone)->format('H:i');
    $originalLocalDisplay = $originalStartUtc->setTimezone($sourceTimezone)->format('H:i');
    $dueLocalDisplay = $rescheduledStartUtc->modify('-1 hour')->setTimezone($sourceTimezone)->format('H:i');

    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('No upcoming activities in the next 7 days.');
    $this->assertSession()->pageTextContains('Synthetic upcoming activity');
    $this->assertSession()->linkNotExists('Change recurring schedule');
    $this->assertSession()->linkNotExists('Change recurring responsibility');
    $this->assertSession()->linkNotExists('Pause recurring activity');
    $this->assertSession()->linkByHrefExists('/personal-secretary/activities/add');
    $this->assertCount(1, $this->getSession()->getPage()->findAll('css', 'a.ps-upcoming-actions__add'));
    $this->assertSession()->elementExists('css', '.ps-upcoming-actions ~ .ps-upcoming-list');
    $this->assertCount(4, $this->getSession()->getPage()->findAll('css', '.ps-action-group--household .ps-action-group__links > a.ps-action-link'));
    $this->assertSession()->elementExists('css', '.ps-action-group--household .ps-action-group__links > a.ps-action-link[href="/personal-secretary/households/rename"]');
    $this->assertSession()->pageTextContains('Parker Browser');
    $this->assertSession()->pageTextContains('Pack synthetic equipment');
    $this->assertSession()->pageTextContains($rescheduledLocalDisplay);
    $this->assertSession()->pageTextContains($dueLocalDisplay);
    $this->assertSession()->pageTextContains('Europe/Brussels');
    $this->assertSession()->pageTextNotContains($originalLocalDisplay);
    $this->assertSession()->pageTextNotContains('Synthetic cancelled activity');
    $this->assertSession()->linkNotExists('Cancel occurrence');
  }


  public function testAddPreparationToExistingActivityPreservesReferencesAndRejectsReplays(): void {
    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\ResponsibilityMutationService $responsibilities */
    $responsibilities = $this->container->get('personal_secretary.responsibility_mutation');
    /** @var \Drupal\personal_secretary\Service\PreparationRequirementMutationService $preparations */
    $preparations = $this->container->get('personal_secretary.preparation_requirement_mutation');
    /** @var \Drupal\personal_secretary\Service\EffectiveOccurrenceProjectionService $projection */
    $projection = $this->container->get('personal_secretary.effective_occurrence_projection');
    /** @var \Drupal\personal_secretary\Service\PreparationEligibilityService $eligibility */
    $eligibility = $this->container->get('personal_secretary.preparation_eligibility');
    $entities = $this->container->get('entity_type.manager');
    $seriesStorage = $entities->getStorage('personal_sec_activity_series');
    $preparationStorage = $entities->getStorage('personal_sec_prep_req');

    $now = new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime());
    $oneStart = $now->modify('+2 days')->setTime(10, 0);
    $weekStart = $now->modify('+3 days')->setTime(14, 0);
    $person = $domain->createPerson('Synthetic new preparation member');
    $household = $domain->createHousehold('Synthetic preparation household', [(int) $person->id()]);
    $one = $domain->createActivitySeries('Synthetic one-off preparation', (int) $household->id(), $oneStart, $oneStart->modify('+1 hour'), 'FREQ=DAILY;COUNT=1');
    $week = $domain->createActivitySeries('Synthetic weekly preparation', (int) $household->id(), $weekStart, $weekStart->modify('+1 hour'), 'FREQ=WEEKLY;INTERVAL=1');
    $oneRule = $responsibilities->createResponsibilityRule($one, (int) $person->id(), $oneStart, $oneStart->modify('+1 hour'), 'FREQ=DAILY;COUNT=1');
    $weekRule = $responsibilities->createResponsibilityRule($week, (int) $person->id(), $weekStart, $weekStart->modify('+1 hour'), 'FREQ=WEEKLY;INTERVAL=1');
    $existing = $preparations->createPreparationRequirement($week, 'Existing synthetic kit', 600, $now->modify('-1 day'));
    $existingId = (int) $existing->id();
    $existingSnapshot = $existing->toArray();
    $oneId = (int) $one->id();
    $weekId = (int) $week->id();
    $before = [
      $oneId => [$one->uuid(), (string) $one->getRevisionId(), $one->get('recurrence')->getValue(), (int) $one->get('household')->target_id],
      $weekId => [$week->uuid(), (string) $week->getRevisionId(), $week->get('recurrence')->getValue(), (int) $week->get('household')->target_id],
    ];
    $originalKeys = [
      $oneId => $projection->project($one, $now, $now->modify('+14 days'))[0]->originalOccurrenceKey,
      $weekId => $projection->project($week, $now, $now->modify('+14 days'))[0]->originalOccurrenceKey,
    ];
    $oneUrl = Url::fromRoute('personal_secretary.add_activity_preparation', ['series' => $oneId])->toString();
    $weekUrl = Url::fromRoute('personal_secretary.add_activity_preparation', ['series' => $weekId])->toString();

    $ordinary = $this->drupalCreateUser(['use personal secretary']);
    $this->drupalLogin($ordinary);
    $this->drupalGet($oneUrl);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/personal-secretary/upcoming/mine');
    $this->assertSession()->linkNotExists('Add a preparation');

    $admin = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->drupalLogin($admin);
    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->linkByHrefExists($oneUrl);
    $this->assertSession()->linkByHrefExists($weekUrl);
    $this->drupalGet('/personal-secretary/activities/999999/preparations/add');
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalGet($oneUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Synthetic one-off preparation');
    $this->assertSession()->pageTextContains('No active preparations for this activity.');
    $this->assertSession()->pageTextNotContains('Existing synthetic kit');
    $this->assertSession()->fieldExists('preparation_instruction');
    $this->assertSession()->fieldExists('preparation_lead_minutes');
    $this->assertSession()->buttonExists('Add preparation');
    $this->clickLink('Cancel');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertCount(1, $preparationStorage->loadMultiple());

    $this->drupalGet($oneUrl);
    $this->submitForm(['preparation_instruction' => '', 'preparation_lead_minutes' => '0'], 'Add preparation');
    $this->assertCount(1, $preparationStorage->loadMultiple());
    $this->drupalGet($oneUrl);
    $this->submitForm(['preparation_instruction' => str_repeat('x', 256), 'preparation_lead_minutes' => '0'], 'Add preparation');
    $this->assertCount(1, $preparationStorage->loadMultiple());
    foreach (['-1', '35791395', '1.5'] as $invalidLead) {
      $this->drupalGet($oneUrl);
      $this->submitForm(['preparation_instruction' => 'Must not be added', 'preparation_lead_minutes' => $invalidLead], 'Add preparation');
      $this->assertCount(1, $preparationStorage->loadMultiple());
    }

    $this->drupalGet($oneUrl);
    $this->assertSession()->elementExists('css', 'input[name="snapshot_id"][type="hidden"]')->setValue(str_repeat('f', 32));
    $this->submitForm(['preparation_instruction' => 'Forged preparation', 'preparation_lead_minutes' => '5'], 'Add preparation');
    $this->assertCount(1, $preparationStorage->loadMultiple());

    $this->drupalGet($oneUrl);
    $oneToken = (string) $this->assertSession()->elementExists('css', 'input[name="snapshot_id"][type="hidden"]')->getValue();
    $this->submitForm(['preparation_instruction' => '  Synthetic appointment notes  ', 'preparation_lead_minutes' => '30'], 'Add preparation');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSession()->pageTextContains('Preparation added to this activity.');
    $this->assertCount(2, $preparationStorage->loadMultiple());
    $newOne = array_values(array_filter($preparationStorage->loadMultiple(), static fn($r): bool => (int) $r->get('series')->target_id === $oneId));
    $this->assertCount(1, $newOne);
    $this->assertSame('Synthetic appointment notes', (string) $newOne[0]->get('label')->value);
    $this->assertSame(1800, (int) $newOne[0]->get('lead_time_seconds')->value);
    $this->assertTrue($newOne[0]->get('effective_until')->isEmpty());
    $oneEffectiveFrom = new DateTimeImmutable((string) $newOne[0]->get('effective_from')->value, new DateTimeZone('UTC'));
    $this->assertGreaterThanOrEqual($now->getTimestamp(), $oneEffectiveFrom->getTimestamp());
    $this->assertLessThanOrEqual($this->container->get('datetime.time')->getCurrentTime(), $oneEffectiveFrom->getTimestamp());
    $oneEligible = $eligibility->derive($one, $projection->project($one, $now, $now->modify('+14 days'))[0]);
    $this->assertCount(1, $oneEligible);
    $this->assertSame((int) $newOne[0]->id(), $oneEligible[0]->requirementId);
    $this->assertSame($originalKeys[$oneId], $oneEligible[0]->originalOccurrenceKey);

    // Existing requirement is additive: the new weekly one cannot retire it.
    $boundedRoute = Url::fromRoute('personal_secretary.add_activity_preparation', ['series' => $weekId], [
      'query' => ['from' => 'mine', 'days' => '30', 'q' => 'weekly', 'focus' => 'tomorrow'],
    ]);
    $returnUrl = Url::fromRoute('personal_secretary.my_upcoming', [], ['query' => ['days' => '30', 'q' => 'weekly', 'focus' => 'tomorrow']])->toString();
    $this->drupalGet($boundedRoute);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkByHrefExists($returnUrl);
    $this->assertSession()->pageTextContains('Active preparations');
    $this->assertSession()->pageTextContains('Existing synthetic kit');
    $this->assertSession()->pageTextNotContains('Synthetic appointment notes');
    $this->submitForm(['preparation_instruction' => 'Synthetic weekly documents', 'preparation_lead_minutes' => '0'], 'Add preparation');
    $this->assertSession()->addressEquals($returnUrl);
    $this->assertCount(3, $preparationStorage->loadMultiple());
    $preparationStorage->resetCache([$existingId]);
    $this->assertEquals($existingSnapshot, $preparationStorage->load($existingId)->toArray());
    $weeklyEligible = $eligibility->derive($week, $projection->project($week, $now, $now->modify('+14 days'))[0]);
    $this->assertCount(2, $weeklyEligible);
    $this->assertSame($existingId, $weeklyEligible[0]->requirementId);
    $this->assertSame($originalKeys[$weekId], $weeklyEligible[1]->originalOccurrenceKey);
    $this->assertSame(0, $weeklyEligible[1]->leadTimeSeconds);
    // Readback shows both active requirements without changing their identities.
    $this->drupalGet($boundedRoute);
    $this->assertSession()->pageTextContains('Existing synthetic kit');
    $this->assertSession()->pageTextContains('Synthetic weekly documents');

    // An already-consumed server-held token must never add another requirement.
    $this->drupalGet($oneUrl);
    $this->assertSession()->elementExists('css', 'input[name="snapshot_id"][type="hidden"]')->setValue($oneToken);
    $this->submitForm(['preparation_instruction' => 'Replayed preparation', 'preparation_lead_minutes' => '1'], 'Add preparation');
    $this->assertCount(3, $preparationStorage->loadMultiple());

    // A series title edit after GET invalidates the trusted snapshot.
    $this->drupalGet($oneUrl);
    $seriesStorage->resetCache([$oneId]);
    $concurrent = $seriesStorage->load($oneId);
    $concurrent->setNewRevision(FALSE);
    $concurrent->set('name', 'Concurrent synthetic activity name');
    $concurrent->save();
    $this->submitForm(['preparation_instruction' => 'Stale preparation', 'preparation_lead_minutes' => '1'], 'Add preparation');
    $this->assertCount(3, $preparationStorage->loadMultiple());

    foreach ([$oneId, $weekId] as $id) {
      $seriesStorage->resetCache([$id]);
      $current = $seriesStorage->load($id);
      $this->assertSame($id, (int) $current->id());
      $this->assertSame($before[$id][0], $current->uuid());
      $this->assertSame($before[$id][1], (string) $current->getRevisionId());
      $this->assertSame($before[$id][2], $current->get('recurrence')->getValue());
      $this->assertSame($before[$id][3], (int) $current->get('household')->target_id);
      $this->assertSame($originalKeys[$id], $projection->project($current, $now, $now->modify('+14 days'))[0]->originalOccurrenceKey);
    }
    $this->assertSame((string) $oneId, (string) $oneRule->get('series')->target_id);
    $this->assertSame((string) $weekId, (string) $weekRule->get('series')->target_id);
    $this->assertCount(2, $entities->getStorage('personal_sec_resp_rule')->loadMultiple());
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
    $this->assertSession()->linkByHrefExists('/personal-secretary/upcoming');
    $this->clickLink('Cancel');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertCount(0, $this->container->get('entity_type.manager')->getStorage('personal_sec_activity_series')->loadMultiple());
    $this->assertCount(0, $this->container->get('entity_type.manager')->getStorage('personal_secretary_household')->loadMultiple());
    $this->drupalGet('/personal-secretary/setup');

    $sourceTimezone = new DateTimeZone('Europe/Brussels');
    $nowLocal = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone($sourceTimezone);
    $firstDate = $nowLocal->modify('+2 days')->format('Y-m-d');
    $localStart = new DateTimeImmutable($firstDate . ' 09:00:00', $sourceTimezone);
    $dueAt = $localStart->modify('-60 minutes');
    $dueDisplay = $this->container->get('date.formatter')->format(
      $dueAt->getTimestamp(),
      'custom',
      'j M Y, H:i',
      'Europe/Brussels',
    );

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

  public function testAddActivityToExistingContextFlow(): void {
    $this->drupalGet('/personal-secretary/activities/add');
    $this->assertSession()->statusCodeEquals(403);

    $authorized = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->drupalLogin($authorized);

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\ResponsibilityMutationService $responsibilityMutations */
    $responsibilityMutations = $this->container->get('personal_secretary.responsibility_mutation');
    /** @var \Drupal\personal_secretary\Service\OccurrenceProjectionService $baseProjection */
    $baseProjection = $this->container->get('personal_secretary.occurrence_projection');
    $entityTypeManager = $this->container->get('entity_type.manager');

    $sourceTimezone = new DateTimeZone('Europe/Brussels');
    $nowLocal = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone($sourceTimezone);
    $firstStart = $nowLocal->modify('+2 days')->setTime(9, 0);
    $firstEnd = $firstStart->modify('+1 hour');

    $person = $domain->createPerson('Existing Synthetic Person');
    $household = $domain->createHousehold('Existing Synthetic Household', [(int) $person->id()]);
    $firstSeries = $domain->createActivitySeries(
      'Existing first weekly activity',
      (int) $household->id(),
      $firstStart,
      $firstEnd,
      'FREQ=WEEKLY;INTERVAL=1',
    );
    $responsibilityMutations->createResponsibilityRule(
      $firstSeries,
      (int) $person->id(),
      $firstStart,
      $firstEnd,
      'FREQ=WEEKLY;INTERVAL=1',
    );

    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Existing first weekly activity');
    $this->assertSession()->linkExists('Add activity');
    $this->assertSession()->linkByHrefExists('/personal-secretary/activities/add');

    $personCount = count($entityTypeManager->getStorage('personal_secretary_person')->loadMultiple());
    $householdCount = count($entityTypeManager->getStorage('personal_secretary_household')->loadMultiple());
    $seriesCount = count($entityTypeManager->getStorage('personal_sec_activity_series')->loadMultiple());

    $this->drupalGet('/personal-secretary/activities/add');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('household_id');
    $this->assertSession()->fieldExists('responsible_person_id');
    $this->assertSession()->fieldExists('activity_label');
    $this->assertSession()->fieldExists('first_occurrence_date');
    $this->assertSession()->fieldExists('start_local_time');
    $this->assertSession()->fieldExists('end_local_time');
    $this->assertSession()->fieldExists('source_timezone');
    $this->assertSession()->fieldExists('preparation_instruction');
    $this->assertSession()->fieldExists('preparation_lead_minutes');
    $this->assertSession()->pageTextContains('Weekly');
    $this->assertSession()->fieldNotExists('rrule');

    $secondStart = $nowLocal->modify('+3 days')->setTime(14, 0);
    $secondDate = $secondStart->format('Y-m-d');
    $dueAt = $secondStart->modify('-30 minutes');
    $dueDisplay = $this->container->get('date.formatter')->format(
      $dueAt->getTimestamp(),
      'custom',
      'j M Y, H:i',
      'Europe/Brussels',
    );
    $this->submitForm([
      'household_id' => (string) $household->id(),
      'responsible_person_id' => (string) $person->id(),
      'activity_type' => 'weekly',
      'activity_label' => 'Synthetic second weekly activity',
      'first_occurrence_date' => $secondDate,
      'start_local_time' => '14:00',
      'end_local_time' => '15:00',
      'source_timezone' => 'Europe/Brussels',
      'preparation_instruction' => 'Prepare synthetic second equipment',
      'preparation_lead_minutes' => '30',
    ], 'Add activity');

    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Existing first weekly activity');
    $this->assertSession()->pageTextContains('Synthetic second weekly activity');
    // Each activity owns its summary and both existing action sections.
    $activityItems = $this->getSession()->getPage()->findAll('css', '.ps-upcoming-list > .ps-upcoming-list__item');
    $this->assertCount(2, $activityItems);
    $this->assertSession()->linkExists('Change recurring schedule');
    $this->assertSession()->linkExists('Change recurring responsibility');
    $this->assertSession()->linkExists('Pause recurring activity');
    foreach ($activityItems as $activityItem) {
      $this->assertCount(1, $activityItem->findAll('css', 'article.ps-upcoming-card'));
      $this->assertCount(1, $activityItem->findAll('css', '.ps-action-group--occurrence'));
      $this->assertCount(1, $activityItem->findAll('css', '.ps-action-group--series'));
    }
    $this->assertSession()->pageTextContains('Existing Synthetic Person');
    $this->assertSession()->pageTextContains('Prepare synthetic second equipment');
    $this->assertSession()->pageTextContains($dueDisplay);

    $this->assertCount($personCount, $entityTypeManager->getStorage('personal_secretary_person')->loadMultiple());
    $this->assertCount($householdCount, $entityTypeManager->getStorage('personal_secretary_household')->loadMultiple());
    $allSeries = array_values($entityTypeManager->getStorage('personal_sec_activity_series')->loadMultiple());
    $this->assertCount($seriesCount + 1, $allSeries);
    $this->assertSame('Existing first weekly activity', $entityTypeManager
      ->getStorage('personal_sec_activity_series')
      ->load($firstSeries->id())
      ?->label());

    $addedSeries = array_values(array_filter(
      $allSeries,
      static fn($candidate): bool => $candidate->label() === 'Synthetic second weekly activity',
    ));
    $this->assertCount(1, $addedSeries);
    $this->assertInstanceOf(ActivitySeries::class, $addedSeries[0]);
    $recurrenceItem = $addedSeries[0]->get('recurrence')->first();
    $this->assertNotNull($recurrenceItem);
    $recurrence = $recurrenceItem->getValue();
    $this->assertSame('FREQ=WEEKLY;INTERVAL=1', $recurrence['rrule']);
    $this->assertSame('Europe/Brussels', $recurrence['timezone']);

    $rules = array_values($entityTypeManager->getStorage('personal_sec_resp_rule')->loadMultiple());
    $addedRules = array_values(array_filter(
      $rules,
      static fn($rule): bool => (int) $rule->get('series')->target_id === (int) $addedSeries[0]->id(),
    ));
    $this->assertCount(1, $addedRules);
    $this->assertSame((int) $person->id(), (int) $addedRules[0]->get('responsible_person')->target_id);
    $this->assertCount(1, $entityTypeManager->getStorage('personal_sec_prep_req')->loadMultiple());

    $firstTarget = $baseProjection->project(
      $firstSeries,
      $firstStart->setTimezone(new DateTimeZone('UTC'))->modify('-1 minute'),
      $firstEnd->setTimezone(new DateTimeZone('UTC'))->modify('+1 minute'),
    )[0];
    $cancelUrl = Url::fromRoute(
      'personal_secretary.cancel_occurrence',
      [
        'series' => (int) $firstSeries->id(),
        'original_occurrence_key' => $firstTarget->originalOccurrenceKey,
      ],
    )->toString();

    $this->drupalLogout();
    $this->drupalGet($cancelUrl);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($authorized);

    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->pageTextContains('Existing first weekly activity');
    $this->assertSession()->pageTextContains('Synthetic second weekly activity');
    $this->assertSession()->linkByHrefExists($cancelUrl);

    $seriesCountBeforeCancel = count($entityTypeManager->getStorage('personal_sec_activity_series')->loadMultiple());
    $this->drupalGet($cancelUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Cancel occurrence of Existing first weekly activity');
    $this->assertSession()->buttonExists('Cancel this occurrence');

    $this->submitForm([], 'Cancel this occurrence');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('Existing first weekly activity');
    $this->assertSession()->pageTextContains('Synthetic second weekly activity');
    $this->assertSession()->pageTextContains('Prepare synthetic second equipment');
    $this->assertCount(
      $seriesCountBeforeCancel,
      $entityTypeManager->getStorage('personal_sec_activity_series')->loadMultiple(),
    );

    $activityExceptions = array_values($entityTypeManager
      ->getStorage('personal_sec_activity_exception')
      ->loadMultiple());
    $this->assertCount(1, $activityExceptions);
    $this->assertInstanceOf(ActivityException::class, $activityExceptions[0]);
    $this->assertSame(ActivityException::ACTION_CANCEL, (string) $activityExceptions[0]->get('action')->value);
    $this->assertSame(ActivityException::STATUS_ACTIVE, (string) $activityExceptions[0]->get('status')->value);
    $this->assertSame((int) $firstSeries->id(), (int) $activityExceptions[0]->get('series')->target_id);
    $this->assertSame($firstTarget->originalOccurrenceKey, (string) $activityExceptions[0]->get('original_occurrence_key')->value);
  }

}
