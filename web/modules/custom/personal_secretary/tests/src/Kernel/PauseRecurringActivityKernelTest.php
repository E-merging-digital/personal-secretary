<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\KernelTests\KernelTestBase;
use Drupal\personal_secretary\Entity\ActivityException;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Service\PauseRecurringActivityService;
use InvalidArgumentException;

/**
 * Proves the bounded recurring-activity pause application contract.
 *
 * @group personal_secretary
 */
final class PauseRecurringActivityKernelTest extends KernelTestBase {

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
    foreach ([
      'personal_secretary_person',
      'personal_secretary_household',
      'personal_sec_activity_series',
      'personal_sec_activity_exception',
      'personal_sec_resp_rule',
      'personal_sec_prep_req',
      'personal_sec_time_commit',
    ] as $entityTypeId) {
      $this->installEntitySchema($entityTypeId);
    }
  }

  public function testTimedPauseUsesSourceLocalDatesAndKeepsDownstreamRulesUntouched(): void {
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $pause = $this->container->get('personal_secretary.pause_recurring_activity');
    $timeline = $this->container->get('personal_secretary.revision_timeline');
    $exceptions = $this->container->get('personal_secretary.activity_exception');
    $effective = $this->container->get('personal_secretary.effective_occurrence_projection');
    $responsibilityMutations = $this->container->get('personal_secretary.responsibility_mutation');
    $preparationMutations = $this->container->get('personal_secretary.preparation_requirement_mutation');
    $timeCommitmentMutations = $this->container->get('personal_secretary.time_commitment_mutation');
    $entityTypeManager = $this->container->get('entity_type.manager');

    $this->assertInstanceOf(PauseRecurringActivityService::class, $pause);

    $timezone = new DateTimeZone('Europe/Brussels');
    $utc = new DateTimeZone('UTC');
    $person = $domain->createPerson('Synthetic pause responsible person');
    $household = $domain->createHousehold('Synthetic pause household', [(int) $person->id()]);
    $start = new DateTimeImmutable('2035-01-01 00:30:00', $timezone);
    $end = new DateTimeImmutable('2035-01-01 01:30:00', $timezone);
    $rrule = 'FREQ=DAILY;COUNT=6';
    $series = $domain->createActivitySeries(
      'Synthetic timed pause activity',
      (int) $household->id(),
      $start,
      $end,
      $rrule,
    );
    $seriesRevision = (string) $series->getRevisionId();

    $rule = $responsibilityMutations->createResponsibilityRule(
      $series,
      (int) $person->id(),
      $start,
      $end,
      $rrule,
    );
    $requirement = $preparationMutations->createPreparationRequirement(
      $series,
      'Synthetic pause preparation',
      1800,
      $start,
    );
    $commitment = $timeCommitmentMutations->createFullOccurrenceCommitment(
      $series,
      $start->setTimezone($utc),
    );
    $downstreamRevisions = [
      'responsibility' => (string) $rule->getRevisionId(),
      'preparation' => (string) $requirement->getRevisionId(),
      'time_commitment' => (string) $commitment->getRevisionId(),
    ];

    $base = $timeline->projectBaseWindow(
      $series,
      $start->setTimezone($utc)->modify('-1 second'),
      $start->modify('+7 days')->setTimezone($utc),
    );
    $byDate = $this->byLocalStartDate($base);
    $this->assertArrayHasKey('2035-01-02', $byDate);
    // 00:30 Europe/Brussels starts on the previous UTC date, proving pause
    // inclusion is source-local rather than a UTC-date shortcut.
    $this->assertSame('2035-01-01', (new DateTimeImmutable($byDate['2035-01-02']->utcStart))->format('Y-m-d'));

    $existingCancel = $exceptions->createCancel($series, $byDate['2035-01-03']);
    $rescheduledOut = $exceptions->createReschedule(
      $series,
      $byDate['2035-01-04'],
      new DateTimeImmutable('2035-01-10 12:00:00', $timezone),
      new DateTimeImmutable('2035-01-10 13:00:00', $timezone),
      'Europe/Brussels',
    );

    $preview = $pause->preview((int) $series->id(), '2035-01-02', '2035-01-04');
    $this->assertSame('Europe/Brussels', $preview['source_timezone']);
    $this->assertSame(1, $preview['occurrences_to_cancel']);
    $this->assertSame(1, $preview['already_cancelled']);
    $this->assertSame('none', $preview['conflict']);
    $this->assertSame(0, $preview['conflict_count']);

    $result = $pause->apply((int) $series->id(), '2035-01-02', '2035-01-04');
    $this->assertSame(['cancelled_count' => 1, 'already_cancelled_count' => 1], $result);

    $active = $exceptions->activeForSeries($series);
    $this->assertCount(3, $active);
    $newCancel = NULL;
    foreach ($active as $exception) {
      if (
        (string) $exception->get('action')->value === ActivityException::ACTION_CANCEL
        && (string) $exception->get('original_occurrence_key')->value === $byDate['2035-01-02']->originalOccurrenceKey
      ) {
        $newCancel = $exception;
      }
    }
    $this->assertInstanceOf(ActivityException::class, $newCancel);
    $this->assertSame($seriesRevision, (string) $newCancel->get('target_revision_id')->value);
    $this->assertSame($byDate['2035-01-02']->originalOccurrenceKey, (string) $newCancel->get('original_occurrence_key')->value);
    $this->assertSame(ActivityException::ACTION_CANCEL, (string) $existingCancel->get('action')->value);
    $this->assertSame(ActivityException::ACTION_RESCHEDULE, (string) $rescheduledOut->get('action')->value);

    $pausedWindow = $effective->project(
      $series,
      (new DateTimeImmutable('2035-01-02 00:00:00', $timezone))->setTimezone($utc),
      (new DateTimeImmutable('2035-01-05 00:00:00', $timezone))->setTimezone($utc),
    );
    $this->assertCount(0, $pausedWindow);
    $rescheduledWindow = $effective->project(
      $series,
      (new DateTimeImmutable('2035-01-10 00:00:00', $timezone))->setTimezone($utc),
      (new DateTimeImmutable('2035-01-11 00:00:00', $timezone))->setTimezone($utc),
    );
    $this->assertCount(1, $rescheduledWindow);
    $this->assertSame(ActivityException::ACTION_RESCHEDULE, $rescheduledWindow[0]->exceptionAction);
    $this->assertSame($byDate['2035-01-04']->originalOccurrenceKey, $rescheduledWindow[0]->originalOccurrenceKey);

    $seriesStorage = $entityTypeManager->getStorage('personal_sec_activity_series');
    $seriesStorage->resetCache([(int) $series->id()]);
    $reloadedSeries = $seriesStorage->load($series->id());
    $this->assertInstanceOf(ActivitySeries::class, $reloadedSeries);
    $this->assertSame($seriesRevision, (string) $reloadedSeries->getRevisionId());

    foreach ([
      'personal_sec_resp_rule' => ['id' => $rule->id(), 'revision' => $downstreamRevisions['responsibility']],
      'personal_sec_prep_req' => ['id' => $requirement->id(), 'revision' => $downstreamRevisions['preparation']],
      'personal_sec_time_commit' => ['id' => $commitment->id(), 'revision' => $downstreamRevisions['time_commitment']],
    ] as $entityTypeId => $expected) {
      $storage = $entityTypeManager->getStorage($entityTypeId);
      $this->assertSame(1, (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute());
      $storage->resetCache([(int) $expected['id']]);
      $entity = $storage->load($expected['id']);
      $this->assertNotNull($entity);
      $this->assertSame($expected['revision'], (string) $entity->getRevisionId());
    }

    $oneOff = $domain->createActivitySeries(
      'Synthetic one-off pause guard',
      (int) $household->id(),
      new DateTimeImmutable('2035-02-01 09:00:00', $timezone),
      new DateTimeImmutable('2035-02-01 10:00:00', $timezone),
      'FREQ=DAILY;COUNT=1',
    );
    $this->assertFalse($pause->canPause((int) $oneOff->id()));
    $this->assertInvalid(
      static fn() => $pause->preview((int) $series->id(), '2035-02-30', '2035-03-01'),
      'valid Y-m-d',
    );
    $this->assertInvalid(
      static fn() => $pause->preview((int) $series->id(), '2035-01-05', '2035-01-04'),
      'must not be before',
    );
  }

  public function testAllDayPauseUsesEffectiveStartDateWithoutOverlapSemantics(): void {
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $pause = $this->container->get('personal_secretary.pause_recurring_activity');
    $timeline = $this->container->get('personal_secretary.revision_timeline');
    $effective = $this->container->get('personal_secretary.effective_occurrence_projection');
    $exceptions = $this->container->get('personal_secretary.activity_exception');

    $timezone = new DateTimeZone('Europe/Brussels');
    $utc = new DateTimeZone('UTC');
    $person = $domain->createPerson('Synthetic all-day pause person');
    $household = $domain->createHousehold('Synthetic all-day pause household', [(int) $person->id()]);
    $start = new DateTimeImmutable('2035-03-03 00:00:00', $timezone);
    $series = $domain->createActivitySeries(
      'Synthetic all-day pause activity',
      (int) $household->id(),
      $start,
      $start->modify('+2 days'),
      'FREQ=WEEKLY;COUNT=3',
      '',
      [],
      ActivitySeries::TIME_MODE_ALL_DAY,
    );
    $recurrenceBefore = $series->get('recurrence')->first()->getValue();
    $this->assertSame(2, $series->allDayCivilDaySpan());

    $overlapOnly = $pause->preview((int) $series->id(), '2035-03-04', '2035-03-04');
    $this->assertSame(0, $overlapOnly['occurrences_to_cancel']);
    $this->assertSame(0, $overlapOnly['already_cancelled']);

    $base = $timeline->projectBaseWindow(
      $series,
      $start->setTimezone($utc)->modify('-1 second'),
      $start->modify('+15 days')->setTimezone($utc),
    );
    $byDate = $this->byLocalStartDate($base);
    $target = $byDate['2035-03-10'];
    $preview = $pause->preview((int) $series->id(), '2035-03-10', '2035-03-10');
    $this->assertSame(1, $preview['occurrences_to_cancel']);
    $result = $pause->apply((int) $series->id(), '2035-03-10', '2035-03-10');
    $this->assertSame(1, $result['cancelled_count']);

    $active = $exceptions->activeForSeries($series);
    $this->assertCount(1, $active);
    $this->assertSame(ActivityException::ACTION_CANCEL, (string) $active[0]->get('action')->value);
    $this->assertSame($target->originalOccurrenceKey, (string) $active[0]->get('original_occurrence_key')->value);
    $this->assertSame($target->seriesRevisionId, (string) $active[0]->get('target_revision_id')->value);

    $seriesStorage = $this->container->get('entity_type.manager')->getStorage('personal_sec_activity_series');
    $seriesStorage->resetCache([(int) $series->id()]);
    $reloaded = $seriesStorage->load($series->id());
    $this->assertInstanceOf(ActivitySeries::class, $reloaded);
    $this->assertSame(ActivitySeries::TIME_MODE_ALL_DAY, $reloaded->timeMode());
    $this->assertSame(2, $reloaded->allDayCivilDaySpan());
    $this->assertSame($recurrenceBefore, $reloaded->get('recurrence')->first()->getValue());

    $targetWindow = $effective->project(
      $reloaded,
      (new DateTimeImmutable('2035-03-10 00:00:00', $timezone))->setTimezone($utc),
      (new DateTimeImmutable('2035-03-11 00:00:00', $timezone))->setTimezone($utc),
    );
    $this->assertCount(0, $targetWindow);
  }

  public function testConfirmationRecomputesAndRejectsRescheduleMovedIntoRangeAtomically(): void {
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $pause = $this->container->get('personal_secretary.pause_recurring_activity');
    $timeline = $this->container->get('personal_secretary.revision_timeline');
    $exceptions = $this->container->get('personal_secretary.activity_exception');

    $timezone = new DateTimeZone('Europe/Brussels');
    $utc = new DateTimeZone('UTC');
    $person = $domain->createPerson('Synthetic reschedule conflict person');
    $household = $domain->createHousehold('Synthetic reschedule conflict household', [(int) $person->id()]);
    $start = new DateTimeImmutable('2035-04-01 10:00:00', $timezone);
    $series = $domain->createActivitySeries(
      'Synthetic reschedule conflict activity',
      (int) $household->id(),
      $start,
      $start->modify('+1 hour'),
      'FREQ=DAILY;COUNT=5',
    );

    $initialPreview = $pause->preview((int) $series->id(), '2035-04-02', '2035-04-03');
    $this->assertSame(2, $initialPreview['occurrences_to_cancel']);
    $this->assertSame('none', $initialPreview['conflict']);

    $base = $timeline->projectBaseWindow(
      $series,
      $start->setTimezone($utc)->modify('-1 second'),
      $start->modify('+6 days')->setTimezone($utc),
    );
    $byDate = $this->byLocalStartDate($base);
    $reschedule = $exceptions->createReschedule(
      $series,
      $byDate['2035-04-05'],
      new DateTimeImmutable('2035-04-02 15:00:00', $timezone),
      new DateTimeImmutable('2035-04-02 16:00:00', $timezone),
      'Europe/Brussels',
    );

    $this->assertInvalid(
      static fn() => $pause->apply((int) $series->id(), '2035-04-02', '2035-04-03'),
      'rescheduled occurrence',
    );

    $active = $exceptions->activeForSeries($series);
    $this->assertCount(1, $active);
    $this->assertSame($reschedule->id(), $active[0]->id());
    $this->assertSame(ActivityException::ACTION_RESCHEDULE, (string) $active[0]->get('action')->value);

    $freshPreview = $pause->preview((int) $series->id(), '2035-04-02', '2035-04-03');
    $this->assertSame(2, $freshPreview['occurrences_to_cancel']);
    $this->assertSame('rescheduled', $freshPreview['conflict']);
    $this->assertSame(1, $freshPreview['conflict_count']);
  }

  public function testCandidateCapFailsClosedWithoutTruncation(): void {
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $pause = $this->container->get('personal_secretary.pause_recurring_activity');
    $exceptions = $this->container->get('personal_secretary.activity_exception');

    $timezone = new DateTimeZone('Europe/Brussels');
    $person = $domain->createPerson('Synthetic cap person');
    $household = $domain->createHousehold('Synthetic cap household', [(int) $person->id()]);
    $start = new DateTimeImmutable('2035-05-01 09:00:00', $timezone);
    $series = $domain->createActivitySeries(
      'Synthetic cap activity',
      (int) $household->id(),
      $start,
      $start->modify('+1 hour'),
      'FREQ=DAILY;COUNT=130',
    );
    $endDate = $start->modify('+' . PauseRecurringActivityService::MAX_PAUSE_CANDIDATES . ' days')->format('Y-m-d');

    $this->assertInvalid(
      static fn() => $pause->preview((int) $series->id(), $start->format('Y-m-d'), $endDate),
      'maximum of 128',
    );
    $this->assertCount(0, $exceptions->activeForSeries($series));
  }

  /**
   * @param \Drupal\personal_secretary\Value\BaseOccurrence[] $occurrences
   *
   * @return array<string, \Drupal\personal_secretary\Value\BaseOccurrence>
   */
  private function byLocalStartDate(array $occurrences): array {
    $byDate = [];
    foreach ($occurrences as $occurrence) {
      $byDate[(new DateTimeImmutable($occurrence->sourceLocalStart))->format('Y-m-d')] = $occurrence;
    }
    return $byDate;
  }

  private function assertInvalid(callable $callback, string $messageNeedle): void {
    try {
      $callback();
      $this->fail('Expected bounded pause validation to fail closed.');
    }
    catch (InvalidArgumentException $exception) {
      $this->assertStringContainsString($messageNeedle, $exception->getMessage());
    }
  }

}
