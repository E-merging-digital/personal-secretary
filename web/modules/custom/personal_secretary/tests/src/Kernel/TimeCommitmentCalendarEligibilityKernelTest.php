<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\personal_secretary\Entity\ActivityException;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\PreparationRequirement;
use Drupal\personal_secretary\Entity\ResponsibilityOverride;
use Drupal\personal_secretary\Entity\ResponsibilityRule;
use Drupal\personal_secretary\Entity\TimeCommitmentRule;
use Drupal\personal_secretary\Value\CalendarProjectionCandidate;
use Drupal\KernelTests\KernelTestBase;
use InvalidArgumentException;
use RuntimeException;

/**
 * Proves provider-agnostic time commitment and calendar eligibility.
 *
 * @group personal_secretary
 */
final class TimeCommitmentCalendarEligibilityKernelTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'datetime',
    'datetime_range',
    'date_recur',
    'personal_secretary',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('personal_secretary_person');
    $this->installEntitySchema('personal_secretary_household');
    $this->installEntitySchema('personal_sec_activity_series');
    $this->installEntitySchema('personal_sec_activity_exception');
    $this->installEntitySchema('personal_sec_resp_rule');
    $this->installEntitySchema('personal_sec_resp_override');
    $this->installEntitySchema('personal_sec_prep_req');
  }

  public function testUpgradeAndEligibilityContract(): void {
    $manager = $this->container->get('entity_type.manager');
    $database = $this->container->get('database');
    $time = $this->container->get('datetime.time');
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $responsibility = $this->container->get('personal_secretary.responsibility_mutation');
    $preparation = $this->container->get('personal_secretary.preparation_requirement_mutation');
    $baseProjection = $this->container->get('personal_secretary.occurrence_projection');
    $effectiveProjection = $this->container->get('personal_secretary.effective_occurrence_projection');
    $exceptions = $this->container->get('personal_secretary.activity_exception');

    $utc = new DateTimeZone('UTC');
    $sourceTimezone = new DateTimeZone('Europe/Brussels');
    $nowLocal = (new DateTimeImmutable('@' . $time->getCurrentTime()))->setTimezone($sourceTimezone);
    $firstStart = $nowLocal->modify('+2 days')->setTime(9, 0);
    $firstEnd = $firstStart->modify('+1 hour');

    $personA = $domain->createPerson('Calendar A');
    $personB = $domain->createPerson('Calendar B');
    $household = $domain->createHousehold(
      'Calendar Household',
      [(int) $personA->id(), (int) $personB->id()],
    );
    $seriesA = $domain->createActivitySeries(
      'Calendar Series A',
      (int) $household->id(),
      $firstStart,
      $firstEnd,
      'FREQ=DAILY;COUNT=10',
    );
    $ruleA = $responsibility->createResponsibilityRule(
      $seriesA,
      (int) $personA->id(),
      $firstStart,
      $firstEnd,
      'FREQ=DAILY;COUNT=10',
    );
    $preparationRequirement = $preparation->createPreparationRequirement(
      $seriesA,
      'Prepare calendar fixture',
      1800,
      $firstStart->modify('-1 day'),
    );

    $thirdStart = $firstStart->modify('+2 days')->setTimezone($utc);
    $thirdBase = $baseProjection->project(
      $seriesA,
      $thirdStart->modify('-1 hour'),
      $thirdStart->modify('+2 hours'),
    )[0];
    $rescheduledThirdStart = (new DateTimeImmutable($thirdBase->utcStart))->modify('+2 hours');
    $rescheduledThirdEnd = (new DateTimeImmutable($thirdBase->utcEnd))->modify('+2 hours');
    $activityException = $exceptions->createReschedule(
      $seriesA,
      $thirdBase,
      $rescheduledThirdStart,
      $rescheduledThirdEnd,
      $thirdBase->sourceTimezone,
    );
    $rescheduledThird = $effectiveProjection->project(
      $seriesA,
      $rescheduledThirdStart->modify('-1 second'),
      $rescheduledThirdEnd->modify('+1 second'),
    )[0];
    $responsibilityOverride = $responsibility->createAssignOverride(
      $seriesA,
      $rescheduledThird,
      (int) $personB->id(),
    );

    $beforeUpgrade = [
      'series_id' => (int) $seriesA->id(),
      'series_revision' => (string) $seriesA->getRevisionId(),
      'rule_id' => (int) $ruleA->id(),
      'rule_revision' => (string) $ruleA->getRevisionId(),
      'exception_id' => (int) $activityException->id(),
      'exception_revision' => (string) $activityException->getRevisionId(),
      'override_id' => (int) $responsibilityOverride->id(),
      'override_revision' => (string) $responsibilityOverride->getRevisionId(),
      'preparation_id' => (int) $preparationRequirement->id(),
      'preparation_revision' => (string) $preparationRequirement->getRevisionId(),
    ];

    $this->assertFalse($database->schema()->tableExists('personal_secretary_time_commitment'));
    $this->assertFalse($database->schema()->tableExists('personal_secretary_time_commitment_revision'));

    $this->container->get('module_handler')->loadInclude('personal_secretary', 'install');
    $this->assertTrue(function_exists('personal_secretary_update_11001'));
    personal_secretary_update_11001();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    $this->assertTrue($database->schema()->tableExists('personal_secretary_time_commitment'));
    $this->assertTrue($database->schema()->tableExists('personal_secretary_time_commitment_revision'));
    $commitmentStorage = $manager->getStorage(TimeCommitmentRule::ENTITY_TYPE_ID);
    $this->assertCount(0, $commitmentStorage->loadMultiple());

    $seriesAfterUpgrade = $manager->getStorage('personal_sec_activity_series')->load($beforeUpgrade['series_id']);
    $ruleAfterUpgrade = $manager->getStorage('personal_sec_resp_rule')->load($beforeUpgrade['rule_id']);
    $exceptionAfterUpgrade = $manager->getStorage('personal_sec_activity_exception')->load($beforeUpgrade['exception_id']);
    $overrideAfterUpgrade = $manager->getStorage('personal_sec_resp_override')->load($beforeUpgrade['override_id']);
    $preparationAfterUpgrade = $manager->getStorage('personal_sec_prep_req')->load($beforeUpgrade['preparation_id']);
    $this->assertInstanceOf(ActivitySeries::class, $seriesAfterUpgrade);
    $this->assertInstanceOf(ResponsibilityRule::class, $ruleAfterUpgrade);
    $this->assertInstanceOf(ActivityException::class, $exceptionAfterUpgrade);
    $this->assertInstanceOf(ResponsibilityOverride::class, $overrideAfterUpgrade);
    $this->assertInstanceOf(PreparationRequirement::class, $preparationAfterUpgrade);
    $this->assertSame($beforeUpgrade['series_revision'], (string) $seriesAfterUpgrade->getRevisionId());
    $this->assertSame($beforeUpgrade['rule_revision'], (string) $ruleAfterUpgrade->getRevisionId());
    $this->assertSame($beforeUpgrade['exception_revision'], (string) $exceptionAfterUpgrade->getRevisionId());
    $this->assertSame($beforeUpgrade['override_revision'], (string) $overrideAfterUpgrade->getRevisionId());
    $this->assertSame($beforeUpgrade['preparation_revision'], (string) $preparationAfterUpgrade->getRevisionId());

    $eligibility = $this->container->get('personal_secretary.calendar_eligibility');
    $timeCommitment = $this->container->get('personal_secretary.time_commitment');
    $commitmentMutations = $this->container->get('personal_secretary.time_commitment_mutation');

    $firstOccurrence = $effectiveProjection->project(
      $seriesA,
      $firstStart->setTimezone($utc)->modify('-1 second'),
      $firstEnd->setTimezone($utc)->modify('+1 second'),
    )[0];

    // Existing upgraded data has no rule and therefore remains fail-closed.
    $this->assertNull($eligibility->evaluate($seriesA, $firstOccurrence, $personA));
    $this->assertNull($timeCommitment->resolve($seriesA, $firstOccurrence));

    $fullA = $commitmentMutations->createFullOccurrenceCommitment(
      $seriesA,
      new DateTimeImmutable($firstOccurrence->effectiveUtcStart),
    );
    $candidateA = $eligibility->evaluate($seriesA, $firstOccurrence, $personA);
    $this->assertInstanceOf(CalendarProjectionCandidate::class, $candidateA);
    $this->assertSame((int) $seriesA->id(), $candidateA->seriesId);
    $this->assertSame($seriesA->uuid(), $candidateA->seriesUuid);
    $this->assertSame($firstOccurrence->seriesRevisionId, $candidateA->seriesRevisionId);
    $this->assertSame($firstOccurrence->originalOccurrenceKey, $candidateA->originalOccurrenceKey);
    $this->assertSame($firstOccurrence->effectiveUtcStart, $candidateA->effectiveUtcStart);
    $this->assertSame($firstOccurrence->effectiveUtcEnd, $candidateA->effectiveUtcEnd);
    $this->assertSame((int) $personA->id(), $candidateA->currentPersonId);
    $this->assertSame((int) $fullA->id(), $candidateA->timeCommitmentRuleId);
    $this->assertNull($eligibility->evaluate($seriesA, $firstOccurrence, $personB));

    // An override away from CurrentPerson excludes the otherwise committed occurrence.
    $this->assertNull($eligibility->evaluate($seriesA, $rescheduledThird, $personA));

    // CLEAR responsibility remains not eligible.
    $secondStart = $firstStart->modify('+1 day')->setTimezone($utc);
    $secondOccurrence = $effectiveProjection->project(
      $seriesA,
      $secondStart->modify('-1 second'),
      $secondStart->modify('+2 hours'),
    )[0];
    $responsibility->createClearOverride($seriesA, $secondOccurrence);
    $this->assertNull($eligibility->evaluate($seriesA, $secondOccurrence, $personA));

    // Recurring B overridden to CurrentPerson A becomes eligible when FULL applies.
    $seriesBStart = $firstStart->modify('+20 minutes');
    $seriesB = $domain->createActivitySeries(
      'Calendar Series B',
      (int) $household->id(),
      $seriesBStart,
      $seriesBStart->modify('+1 hour'),
      'FREQ=DAILY;COUNT=3',
    );
    $responsibility->createResponsibilityRule(
      $seriesB,
      (int) $personB->id(),
      $seriesBStart,
      $seriesBStart->modify('+1 hour'),
      'FREQ=DAILY;COUNT=3',
    );
    $seriesBOccurrence = $effectiveProjection->project(
      $seriesB,
      $seriesBStart->setTimezone($utc)->modify('-1 second'),
      $seriesBStart->setTimezone($utc)->modify('+2 hours'),
    )[0];
    $commitmentMutations->createFullOccurrenceCommitment(
      $seriesB,
      new DateTimeImmutable($seriesBOccurrence->effectiveUtcStart),
    );
    $this->assertNull($eligibility->evaluate($seriesB, $seriesBOccurrence, $personA));
    $responsibility->createAssignOverride($seriesB, $seriesBOccurrence, (int) $personA->id());
    $this->assertInstanceOf(
      CalendarProjectionCandidate::class,
      $eligibility->evaluate($seriesB, $seriesBOccurrence, $personA),
    );

    // The mutation boundary rejects a second open-ended interval before write.
    try {
      $commitmentMutations->createFullOccurrenceCommitment(
        $seriesB,
        (new DateTimeImmutable($seriesBOccurrence->effectiveUtcStart))->modify('+1 day'),
      );
      $this->fail('Overlapping TimeCommitmentRule creation must be rejected.');
    }
    catch (InvalidArgumentException) {
      $this->assertCount(2, $commitmentStorage->loadMultiple());
    }

    // A corrupted overlapping persisted state is independently fail-closed.
    $overlap = $commitmentStorage->create([
      'series' => $seriesB->id(),
      'mode' => TimeCommitmentRule::MODE_FULL_OCCURRENCE,
      'effective_from' => (new DateTimeImmutable($seriesBOccurrence->effectiveUtcStart))->format('Y-m-d\\TH:i:s'),
      'lifecycle_persisted_at' => $time->getCurrentTime(),
    ]);
    $overlap->save();
    try {
      $timeCommitment->resolve($seriesB, $seriesBOccurrence);
      $this->fail('Ambiguous TimeCommitmentRule state must fail closed.');
    }
    catch (RuntimeException) {
      $this->assertCount(3, $commitmentStorage->loadMultiple());
    }

    // Reschedule crosses a commitment boundary and uses the moved effective window.
    $seriesCStart = $firstStart->modify('+40 minutes');
    $seriesC = $domain->createActivitySeries(
      'Calendar Series C',
      (int) $household->id(),
      $seriesCStart,
      $seriesCStart->modify('+1 hour'),
      'FREQ=DAILY;COUNT=3',
    );
    $responsibility->createResponsibilityRule(
      $seriesC,
      (int) $personA->id(),
      $seriesCStart,
      $seriesCStart->modify('+1 hour'),
      'FREQ=DAILY;COUNT=3',
    );
    $seriesCBase = $baseProjection->project(
      $seriesC,
      $seriesCStart->setTimezone($utc)->modify('-1 second'),
      $seriesCStart->setTimezone($utc)->modify('+2 hours'),
    )[0];
    $movedStart = (new DateTimeImmutable($seriesCBase->utcStart))->modify('+2 hours');
    $movedEnd = (new DateTimeImmutable($seriesCBase->utcEnd))->modify('+2 hours');
    $commitmentMutations->createFullOccurrenceCommitment(
      $seriesC,
      (new DateTimeImmutable($seriesCBase->utcStart))->modify('+1 hour'),
    );
    $exceptions->createReschedule(
      $seriesC,
      $seriesCBase,
      $movedStart,
      $movedEnd,
      $seriesCBase->sourceTimezone,
    );
    $movedOccurrence = $effectiveProjection->project(
      $seriesC,
      $movedStart->modify('-1 second'),
      $movedEnd->modify('+1 second'),
    )[0];
    // Existing responsibility semantics do not infer that a recurring rule
    // follows a moved occurrence outside its own applicability window. Make
    // the responsibility for this exact rescheduled occurrence explicit so
    // this assertion isolates the time-commitment/reschedule contract.
    $responsibility->createAssignOverride($seriesC, $movedOccurrence, (int) $personA->id());
    $movedCandidate = $eligibility->evaluate($seriesC, $movedOccurrence, $personA);
    $this->assertInstanceOf(CalendarProjectionCandidate::class, $movedCandidate);
    $this->assertSame($seriesCBase->originalOccurrenceKey, $movedCandidate->originalOccurrenceKey);
    $this->assertSame($movedStart->format(DATE_ATOM), $movedCandidate->effectiveUtcStart);
    $this->assertSame($movedEnd->format(DATE_ATOM), $movedCandidate->effectiveUtcEnd);

    // A cancelled occurrence produces no EffectiveOccurrence and therefore no candidate.
    $seriesCSecondStart = $seriesCStart->modify('+1 day')->setTimezone($utc);
    $seriesCSecondBase = $baseProjection->project(
      $seriesC,
      $seriesCSecondStart->modify('-1 second'),
      $seriesCSecondStart->modify('+2 hours'),
    )[0];
    $exceptions->createCancel($seriesC, $seriesCSecondBase);
    $cancelled = $effectiveProjection->project(
      $seriesC,
      $seriesCSecondStart->modify('-1 second'),
      $seriesCSecondStart->modify('+2 hours'),
    );
    $this->assertSame([], $cancelled);
  }

}
