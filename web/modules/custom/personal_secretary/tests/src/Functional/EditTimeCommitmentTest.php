<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\personal_secretary\Entity\ActivityException;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\PreparationRequirement;
use Drupal\personal_secretary\Entity\ResponsibilityOverride;
use Drupal\personal_secretary\Entity\ResponsibilityRule;
use Drupal\personal_secretary\Entity\TimeCommitmentRule;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\EditTimeCommitmentService;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;

/**
 * Proves the governed future series-level time-commitment surface.
 *
 * @group personal_secretary
 */
final class EditTimeCommitmentTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testFutureTimeCommitmentLifecyclePreservesExistingDomain(): void {
    $this->installUserPersonFieldViaEntityApi();

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\ResponsibilityMutationService $responsibility */
    $responsibility = $this->container->get('personal_secretary.responsibility_mutation');
    /** @var \Drupal\personal_secretary\Service\PreparationRequirementMutationService $preparation */
    $preparation = $this->container->get('personal_secretary.preparation_requirement_mutation');
    /** @var \Drupal\personal_secretary\Service\OccurrenceProjectionService $baseProjection */
    $baseProjection = $this->container->get('personal_secretary.occurrence_projection');
    /** @var \Drupal\personal_secretary\Service\EffectiveOccurrenceProjectionService $effectiveProjection */
    $effectiveProjection = $this->container->get('personal_secretary.effective_occurrence_projection');
    /** @var \Drupal\personal_secretary\Service\ActivityExceptionService $exceptions */
    $exceptions = $this->container->get('personal_secretary.activity_exception');

    $entityTypeManager = $this->container->get('entity_type.manager');
    $timezone = new DateTimeZone('Europe/Brussels');
    $nowLocal = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone($timezone);
    $firstStart = $nowLocal->modify('+1 day')->setTime(9, 0);
    $firstEnd = $firstStart->modify('+1 hour');
    $secondStart = $firstStart->modify('+1 day');
    $thirdStart = $firstStart->modify('+2 days');

    $personA = $domain->createPerson('Time Commitment Current Person');
    $personB = $domain->createPerson('Time Commitment Other Person');
    $household = $domain->createHousehold(
      'Time Commitment Household',
      [(int) $personA->id(), (int) $personB->id()],
    );
    $series = $domain->createActivitySeries(
      'Time Commitment Activity',
      (int) $household->id(),
      $firstStart,
      $firstEnd,
      'FREQ=DAILY;COUNT=5',
    );
    $responsibilityRule = $responsibility->createResponsibilityRule(
      $series,
      (int) $personA->id(),
      $firstStart,
      $firstEnd,
      'FREQ=DAILY;COUNT=5',
    );
    $preparationRequirement = $preparation->createPreparationRequirement(
      $series,
      'Prepare time commitment fixture',
      1800,
      $firstStart->modify('-1 day'),
    );

    $thirdUtc = $thirdStart->setTimezone(new DateTimeZone('UTC'));
    $thirdBase = $baseProjection->project(
      $series,
      $thirdUtc->modify('-1 hour'),
      $thirdUtc->modify('+2 hours'),
    )[0];
    $rescheduledStart = (new DateTimeImmutable($thirdBase->utcStart))->modify('+2 hours');
    $rescheduledEnd = (new DateTimeImmutable($thirdBase->utcEnd))->modify('+2 hours');
    $activityException = $exceptions->createReschedule(
      $series,
      $thirdBase,
      $rescheduledStart,
      $rescheduledEnd,
      $thirdBase->sourceTimezone,
    );
    $rescheduledOccurrence = $effectiveProjection->project(
      $series,
      $rescheduledStart->modify('-1 second'),
      $rescheduledEnd->modify('+1 second'),
    )[0];
    $responsibilityOverride = $responsibility->createAssignOverride(
      $series,
      $rescheduledOccurrence,
      (int) $personB->id(),
    );

    $authorized = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->assertInstanceOf(UserInterface::class, $authorized);
    $authorized->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => $personA->id()]);
    $authorized->save();

    $seriesId = (int) $series->id();
    $editUrl = Url::fromRoute(
      'personal_secretary.edit_time_commitment',
      ['series' => $seriesId],
    )->toString();

    $commitmentStorage = $entityTypeManager->getStorage(TimeCommitmentRule::ENTITY_TYPE_ID);

    $baseline = [
      'series_revision' => (string) $series->getRevisionId(),
      'series_recurrence' => $series->get('recurrence')->first()->getValue(),
      'series_effective_from' => (string) $series->get('effective_from')->value,
      'exception_revision' => (string) $activityException->getRevisionId(),
      'exception_status' => (string) $activityException->get('status')->value,
      'exception_key' => (string) $activityException->get('original_occurrence_key')->value,
      'responsibility_rule_revision' => (string) $responsibilityRule->getRevisionId(),
      'responsibility_override_revision' => (string) $responsibilityOverride->getRevisionId(),
      'responsibility_override_action' => (string) $responsibilityOverride->get('action')->value,
      'preparation_revision' => (string) $preparationRequirement->getRevisionId(),
      'user_person_target' => (string) $authorized->get(CurrentPersonResolver::FIELD_NAME)->target_id,
      'counts' => $this->allDomainCounts(),
    ];

    $this->drupalGet($editUrl);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($authorized);

    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Change time commitment');
    $this->assertSession()->linkByHrefExists($editUrl);

    $this->drupalGet('/personal-secretary/upcoming/mine');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Change time commitment');
    $this->assertSession()->linkByHrefExists($editUrl);

    $this->drupalGet($editUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Time Commitment Activity');
    $this->assertSession()->pageTextContains('Europe/Brussels');
    $this->assertSession()->pageTextContains('Time commitment at the next affected occurrence');
    $this->assertSession()->fieldExists('effective_from_date');
    $this->assertSession()->fieldExists('mode');
    $this->assertSession()->fieldNotExists('series');
    $this->assertSession()->fieldNotExists('person');
    $this->assertSession()->fieldNotExists('source_timezone');
    $this->assertCount(0, $commitmentStorage->loadMultiple());
    $this->assertPreservedBaseline($baseline, $seriesId, (int) $activityException->id(), (int) $responsibilityRule->id(), (int) $responsibilityOverride->id(), (int) $preparationRequirement->id(), (int) $authorized->id());

    // NONE -> FULL: the transition is the first EffectiveOccurrence on/after
    // the requested local date, not local midnight and not a series revision.
    $this->submitForm([
      'effective_from_date' => $firstStart->format('Y-m-d'),
      'mode' => EditTimeCommitmentService::MODE_FULL_OCCURRENCE,
    ], 'Save time commitment');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $rules = array_values($commitmentStorage->loadMultiple());
    $this->assertCount(1, $rules);
    $commitment = $rules[0];
    $this->assertInstanceOf(TimeCommitmentRule::class, $commitment);
    $this->assertSame(TimeCommitmentRule::MODE_FULL_OCCURRENCE, (string) $commitment->get('mode')->value);
    $this->assertSame(
      $firstStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s'),
      (string) $commitment->get('effective_from')->value,
    );
    $this->assertTrue($commitment->get('effective_until')->isEmpty());
    $createdRevision = (string) $commitment->getRevisionId();
    $this->assertPreservedBaseline($baseline, $seriesId, (int) $activityException->id(), (int) $responsibilityRule->id(), (int) $responsibilityOverride->id(), (int) $preparationRequirement->id(), (int) $authorized->id(), 1);

    // FULL -> FULL at the same effective occurrence is a true no-op.
    $this->drupalGet($editUrl);
    $this->submitForm([
      'effective_from_date' => $firstStart->format('Y-m-d'),
      'mode' => EditTimeCommitmentService::MODE_FULL_OCCURRENCE,
    ], 'Save time commitment');
    $commitmentAfterNoop = $commitmentStorage->load($commitment->id());
    $this->assertInstanceOf(TimeCommitmentRule::class, $commitmentAfterNoop);
    $this->assertSame($createdRevision, (string) $commitmentAfterNoop->getRevisionId());
    $this->assertCount(1, $commitmentStorage->loadMultiple());

    // FULL -> NONE retires the existing rule at the second real occurrence;
    // NONE is represented by absence of a matching interval, never a new row.
    $this->drupalGet($editUrl);
    $this->submitForm([
      'effective_from_date' => $secondStart->format('Y-m-d'),
      'mode' => EditTimeCommitmentService::MODE_NONE,
    ], 'Save time commitment');
    $retired = $commitmentStorage->load($commitment->id());
    $this->assertInstanceOf(TimeCommitmentRule::class, $retired);
    $this->assertNotSame($createdRevision, (string) $retired->getRevisionId());
    $this->assertSame(
      $secondStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s'),
      (string) $retired->get('effective_until')->value,
    );
    $this->assertCount(1, $commitmentStorage->loadMultiple());

    $this->assertPreservedBaseline($baseline, $seriesId, (int) $activityException->id(), (int) $responsibilityRule->id(), (int) $responsibilityOverride->id(), (int) $preparationRequirement->id(), (int) $authorized->id(), 1);
  }

  /**
   * Proves dead time-commitment affordances stay hidden without widening scope.
   */
  public function testAffordanceRequiresSupportedFutureOccurrence(): void {
    $this->installUserPersonFieldViaEntityApi();

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\AddActivityService $addActivity */
    $addActivity = $this->container->get('personal_secretary.add_activity');
    /** @var \Drupal\personal_secretary\Service\EditTimeCommitmentService $editor */
    $editor = $this->container->get('personal_secretary.edit_time_commitment');
    /** @var \Drupal\personal_secretary\Service\UpcomingActivityService $upcoming */
    $upcoming = $this->container->get('personal_secretary.upcoming_activity');

    $timezone = $this->timezoneWithCurrentDayRoom();
    $nowLocal = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone($timezone);
    $todayStart = $nowLocal->setTime(0, 0);
    $timedStart = $nowLocal->modify('+1 hour');
    $this->assertSame($todayStart->format('Y-m-d'), $timedStart->format('Y-m-d'));
    $futureStart = $todayStart->modify('+1 day')->setTime(9, 0);
    $recurringStart = $todayStart->modify('+1 day')->setTime(11, 0);

    $person = $domain->createPerson('Time Commitment Affordance Person');
    $household = $domain->createHousehold(
      'Time Commitment Affordance Household',
      [(int) $person->id()],
    );

    $currentAllDay = $addActivity->addOneOffActivity(
      (int) $household->id(),
      (int) $person->id(),
      'Current-day one-off all-day',
      $todayStart,
      $todayStart->modify('+1 day'),
      '',
      0,
      '',
      [],
      ActivitySeries::TIME_MODE_ALL_DAY,
    );
    $currentTimed = $addActivity->addOneOffActivity(
      (int) $household->id(),
      (int) $person->id(),
      'Current-day one-off timed',
      $timedStart,
      $timedStart->modify('+1 hour'),
    );
    $futureOneOff = $addActivity->addOneOffActivity(
      (int) $household->id(),
      (int) $person->id(),
      'Future one-off timed',
      $futureStart,
      $futureStart->modify('+1 hour'),
    );
    $recurring = $addActivity->addWeeklyActivity(
      (int) $household->id(),
      (int) $person->id(),
      'Recurring future timed',
      $recurringStart,
      $recurringStart->modify('+1 hour'),
    );

    $authorized = $this->drupalCreateUser(['administer personal secretary domain']);
    $authorized->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $person->id()]);
    $authorized->set('timezone', $timezone->getName());
    $authorized->save();
    $this->drupalLogin($authorized);

    $allDayEditUrl = $this->timeCommitmentEditUrl($currentAllDay);
    $timedEditUrl = $this->timeCommitmentEditUrl($currentTimed);
    $futureEditUrl = $this->timeCommitmentEditUrl($futureOneOff);
    $recurringEditUrl = $this->timeCommitmentEditUrl($recurring);

    $this->assertFalse($editor->canEdit((int) $currentAllDay->id()));
    $this->assertFalse($editor->canEdit((int) $currentTimed->id()));
    $this->assertTrue($editor->canEdit((int) $futureOneOff->id()));
    $this->assertTrue($editor->canEdit((int) $recurring->id()));

    $baselineCounts = $this->allDomainCounts();
    $baselineRevisions = $this->seriesRevisions([
      $currentAllDay,
      $currentTimed,
      $futureOneOff,
      $recurring,
    ]);

    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Current-day one-off all-day');
    $this->assertSession()->pageTextContains('Current-day one-off timed');
    $this->assertSession()->pageTextContains('Future one-off timed');
    $this->assertSession()->pageTextContains('Recurring future timed');
    $this->assertSession()->linkByHrefNotExists($allDayEditUrl);
    $this->assertSession()->linkByHrefNotExists($timedEditUrl);
    $this->assertSession()->linkByHrefExists($futureEditUrl);
    $this->assertSession()->linkByHrefExists($recurringEditUrl);

    $this->drupalGet('/personal-secretary/upcoming/mine');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Current-day one-off all-day');
    $this->assertSession()->pageTextContains('Current-day one-off timed');
    $this->assertSession()->linkByHrefNotExists($allDayEditUrl);
    $this->assertSession()->linkByHrefNotExists($timedEditUrl);
    $this->assertSession()->linkByHrefExists($futureEditUrl);
    $this->assertSession()->linkByHrefExists($recurringEditUrl);

    $personalized = $upcoming->upcomingForPersonInHouseholds(
      $person,
      [(int) $household->id()],
    );
    $allDayDetailUrl = $this->detailUrlForLabel($personalized, 'Current-day one-off all-day');
    $timedDetailUrl = $this->detailUrlForLabel($personalized, 'Current-day one-off timed');

    $this->drupalGet($allDayDetailUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Current-day one-off all-day');
    $this->assertSession()->linkByHrefNotExists($allDayEditUrl);

    $this->drupalGet($timedDetailUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Current-day one-off timed');
    $this->assertSession()->linkByHrefNotExists($timedEditUrl);

    $this->drupalGet($allDayEditUrl);
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalGet($timedEditUrl);
    $this->assertSession()->statusCodeEquals(404);

    $this->drupalGet($futureEditUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('effective_from_date');
    $this->assertSession()->fieldExists('mode');

    $this->drupalGet($recurringEditUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('effective_from_date');
    $this->assertSession()->fieldExists('mode');

    $this->assertSame($baselineCounts, $this->allDomainCounts());
    $this->assertSame($baselineRevisions, $this->seriesRevisions([
      $currentAllDay,
      $currentTimed,
      $futureOneOff,
      $recurring,
    ]));
  }

  /**
   * Selects a timezone with enough room for a current-day timed occurrence.
   */
  private function timezoneWithCurrentDayRoom(): DateTimeZone {
    $timestamp = $this->container->get('datetime.time')->getCurrentTime();
    foreach ([
      'Pacific/Pago_Pago',
      'Pacific/Honolulu',
      'America/Los_Angeles',
      'America/Denver',
      'America/Chicago',
      'America/New_York',
      'UTC',
      'Europe/Brussels',
      'Asia/Dubai',
      'Asia/Kolkata',
      'Asia/Tokyo',
      'Australia/Sydney',
      'Pacific/Auckland',
    ] as $timezoneId) {
      $timezone = new DateTimeZone($timezoneId);
      $hour = (int) (new DateTimeImmutable('@' . $timestamp))
        ->setTimezone($timezone)
        ->format('G');
      if ($hour >= 4 && $hour <= 18) {
        return $timezone;
      }
    }

    throw new \RuntimeException('Unable to select a stable current-day test timezone.');
  }

  /**
   * Builds the time-commitment editor URL for one persisted series.
   */
  private function timeCommitmentEditUrl(ActivitySeries $series): string {
    return Url::fromRoute(
      'personal_secretary.edit_time_commitment',
      ['series' => (int) $series->id()],
    )->toString();
  }

  /**
   * Builds an occurrence-detail URL for one exact presentation item.
   *
   * @param array<int, array<string, mixed>> $items
   *   Personalized occurrence presentation items.
   * @param string $label
   *   Exact activity label to resolve.
   *
   * @return string
   *   Route URL for the matching occurrence detail.
   */
  private function detailUrlForLabel(array $items, string $label): string {
    $matches = array_values(array_filter(
      $items,
      static fn(array $item): bool => ($item['activity_label'] ?? '') === $label,
    ));
    $this->assertCount(1, $matches);
    $target = $matches[0]['responsibility_target'];

    return Url::fromRoute(
      'personal_secretary.occurrence_detail',
      [
        'series' => (int) $target['series_id'],
        'original_occurrence_key' => (string) $target['original_occurrence_key'],
      ],
    )->toString();
  }

  /**
   * Captures persisted revision IDs without mutating the supplied series.
   *
   * @param \Drupal\personal_secretary\Entity\ActivitySeries[] $series
   *   Activity series whose revision IDs must remain stable across GETs.
   *
   * @return array<int, string>
   *   Revision IDs keyed by series ID.
   */
  private function seriesRevisions(array $series): array {
    $storage = $this->container
      ->get('entity_type.manager')
      ->getStorage('personal_sec_activity_series');
    $ids = array_map(
      static fn(ActivitySeries $activity): int => (int) $activity->id(),
      $series,
    );
    $storage->resetCache($ids);

    $revisions = [];
    foreach ($ids as $id) {
      $reloaded = $storage->load($id);
      $this->assertInstanceOf(ActivitySeries::class, $reloaded);
      $revisions[$id] = (string) $reloaded->getRevisionId();
    }
    ksort($revisions);
    return $revisions;
  }

  /**
   * @param array<string, mixed> $baseline
   */
  private function assertPreservedBaseline(
    array $baseline,
    int $seriesId,
    int $exceptionId,
    int $responsibilityRuleId,
    int $responsibilityOverrideId,
    int $preparationId,
    int $userId,
    int $expectedCommitmentCount = 0,
  ): void {
    $manager = $this->container->get('entity_type.manager');
    $series = $manager->getStorage('personal_sec_activity_series')->load($seriesId);
    $exception = $manager->getStorage('personal_sec_activity_exception')->load($exceptionId);
    $rule = $manager->getStorage('personal_sec_resp_rule')->load($responsibilityRuleId);
    $override = $manager->getStorage('personal_sec_resp_override')->load($responsibilityOverrideId);
    $preparation = $manager->getStorage('personal_sec_prep_req')->load($preparationId);
    $user = $manager->getStorage('user')->load($userId);

    $this->assertInstanceOf(ActivitySeries::class, $series);
    $this->assertInstanceOf(ActivityException::class, $exception);
    $this->assertInstanceOf(ResponsibilityRule::class, $rule);
    $this->assertInstanceOf(ResponsibilityOverride::class, $override);
    $this->assertInstanceOf(PreparationRequirement::class, $preparation);
    $this->assertInstanceOf(UserInterface::class, $user);

    $this->assertSame($baseline['series_revision'], (string) $series->getRevisionId());
    $this->assertSame($baseline['series_recurrence'], $series->get('recurrence')->first()->getValue());
    $this->assertSame($baseline['series_effective_from'], (string) $series->get('effective_from')->value);
    $this->assertSame($baseline['exception_revision'], (string) $exception->getRevisionId());
    $this->assertSame($baseline['exception_status'], (string) $exception->get('status')->value);
    $this->assertSame($baseline['exception_key'], (string) $exception->get('original_occurrence_key')->value);
    $this->assertSame($baseline['responsibility_rule_revision'], (string) $rule->getRevisionId());
    $this->assertSame($baseline['responsibility_override_revision'], (string) $override->getRevisionId());
    $this->assertSame($baseline['responsibility_override_action'], (string) $override->get('action')->value);
    $this->assertSame($baseline['preparation_revision'], (string) $preparation->getRevisionId());
    $this->assertSame($baseline['user_person_target'], (string) $user->get(CurrentPersonResolver::FIELD_NAME)->target_id);

    $counts = $this->allDomainCounts();
    $expected = $baseline['counts'];
    $expected['time_commitments'] = $expectedCommitmentCount;
    $this->assertSame($expected, $counts);
  }

  /**
   * @return array<string, int>
   */
  private function allDomainCounts(): array {
    $manager = $this->container->get('entity_type.manager');
    return [
      'person' => count($manager->getStorage('personal_secretary_person')->loadMultiple()),
      'household' => count($manager->getStorage('personal_secretary_household')->loadMultiple()),
      'series' => count($manager->getStorage('personal_sec_activity_series')->loadMultiple()),
      'exceptions' => count($manager->getStorage('personal_sec_activity_exception')->loadMultiple()),
      'responsibility_rules' => count($manager->getStorage('personal_sec_resp_rule')->loadMultiple()),
      'responsibility_overrides' => count($manager->getStorage('personal_sec_resp_override')->loadMultiple()),
      'preparations' => count($manager->getStorage('personal_sec_prep_req')->loadMultiple()),
      'time_commitments' => count($manager->getStorage(TimeCommitmentRule::ENTITY_TYPE_ID)->loadMultiple()),
    ];
  }

  private function installUserPersonFieldViaEntityApi(): void {
    FieldStorageConfig::create([
      'field_name' => CurrentPersonResolver::FIELD_NAME,
      'entity_type' => 'user',
      'type' => 'entity_reference',
      'settings' => [
        'target_type' => 'personal_secretary_person',
      ],
      'cardinality' => 1,
      'translatable' => FALSE,
    ])->save();

    FieldConfig::create([
      'field_name' => CurrentPersonResolver::FIELD_NAME,
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Personal Secretary person',
      'required' => FALSE,
      'translatable' => FALSE,
      'settings' => [
        'handler' => 'default:personal_secretary_person',
        'handler_settings' => [],
      ],
    ])->save();

    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

}
