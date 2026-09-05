<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\KernelTests\KernelTestBase;

/**
 * Proves true overlap projection without changing start-inside projection.
 *
 * @group personal_secretary
 */
final class EffectiveOccurrenceOverlapKernelTest extends KernelTestBase {

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
  }

  public function testTrueOverlapAndExceptionOverlayAreBounded(): void {
    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\EffectiveOccurrenceProjectionService $effective */
    $effective = $this->container->get('personal_secretary.effective_occurrence_projection');
    /** @var \Drupal\personal_secretary\Service\RevisionTimelineService $timeline */
    $timeline = $this->container->get('personal_secretary.revision_timeline');
    /** @var \Drupal\personal_secretary\Service\ActivityExceptionService $exceptions */
    $exceptions = $this->container->get('personal_secretary.activity_exception');

    $person = $domain->createPerson('Synthetic overlap person');
    $household = $domain->createHousehold('Synthetic overlap household', [(int) $person->id()]);
    $utc = new DateTimeZone('UTC');
    $windowStart = new DateTimeImmutable('2026-04-01 00:00:00', $utc);
    $windowEnd = new DateTimeImmutable('2026-04-02 00:00:00', $utc);

    $startedBefore = $domain->createActivitySeries(
      'Started before Today',
      (int) $household->id(),
      new DateTimeImmutable('2026-03-31 23:00:00', $utc),
      new DateTimeImmutable('2026-04-01 01:00:00', $utc),
      'FREQ=DAILY;COUNT=1',
    );
    $this->assertSame([], $effective->project($startedBefore, $windowStart, $windowEnd));
    $overlap = $effective->projectOverlapping($startedBefore, $windowStart, $windowEnd);
    $this->assertCount(1, $overlap);
    $this->assertSame('2026-03-31T23:00:00+00:00', $overlap[0]->effectiveUtcStart);
    $this->assertSame('2026-04-01T01:00:00+00:00', $overlap[0]->effectiveUtcEnd);

    $endsAtStart = $domain->createActivitySeries(
      'Ends at Today start',
      (int) $household->id(),
      new DateTimeImmutable('2026-03-31 23:00:00', $utc),
      new DateTimeImmutable('2026-04-01 00:00:00', $utc),
      'FREQ=DAILY;COUNT=1',
    );
    $this->assertSame([], $effective->projectOverlapping($endsAtStart, $windowStart, $windowEnd));

    $startsAtEnd = $domain->createActivitySeries(
      'Starts at Tomorrow',
      (int) $household->id(),
      new DateTimeImmutable('2026-04-02 00:00:00', $utc),
      new DateTimeImmutable('2026-04-02 01:00:00', $utc),
      'FREQ=DAILY;COUNT=1',
    );
    $this->assertSame([], $effective->projectOverlapping($startsAtEnd, $windowStart, $windowEnd));

    $spansIntoTomorrow = $domain->createActivitySeries(
      'Spans into tomorrow',
      (int) $household->id(),
      new DateTimeImmutable('2026-04-01 23:00:00', $utc),
      new DateTimeImmutable('2026-04-02 01:00:00', $utc),
      'FREQ=DAILY;COUNT=1',
    );
    $this->assertCount(1, $effective->projectOverlapping($spansIntoTomorrow, $windowStart, $windowEnd));

    $cancelled = $domain->createActivitySeries(
      'Cancelled overlap',
      (int) $household->id(),
      new DateTimeImmutable('2026-03-31 23:30:00', $utc),
      new DateTimeImmutable('2026-04-01 00:30:00', $utc),
      'FREQ=DAILY;COUNT=1',
    );
    $cancelTarget = $timeline->projectBaseWindow(
      $cancelled,
      new DateTimeImmutable('2026-03-31 00:00:00', $utc),
      $windowEnd,
    )[0];
    $exceptions->createCancel($cancelled, $cancelTarget);
    $this->assertSame([], $effective->projectOverlapping($cancelled, $windowStart, $windowEnd));

    $rescheduledInto = $domain->createActivitySeries(
      'Rescheduled into Today',
      (int) $household->id(),
      new DateTimeImmutable('2026-04-03 10:00:00', $utc),
      new DateTimeImmutable('2026-04-03 11:00:00', $utc),
      'FREQ=DAILY;COUNT=1',
    );
    $intoTarget = $timeline->projectBaseWindow(
      $rescheduledInto,
      new DateTimeImmutable('2026-04-03 00:00:00', $utc),
      new DateTimeImmutable('2026-04-04 00:00:00', $utc),
    )[0];
    $exceptions->createReschedule(
      $rescheduledInto,
      $intoTarget,
      new DateTimeImmutable('2026-04-01 12:00:00', $utc),
      new DateTimeImmutable('2026-04-01 13:00:00', $utc),
      'UTC',
    );
    $into = $effective->projectOverlapping($rescheduledInto, $windowStart, $windowEnd);
    $this->assertCount(1, $into);
    $this->assertSame('2026-04-01T12:00:00+00:00', $into[0]->effectiveUtcStart);

    $rescheduledOut = $domain->createActivitySeries(
      'Rescheduled out of Today',
      (int) $household->id(),
      new DateTimeImmutable('2026-04-01 14:00:00', $utc),
      new DateTimeImmutable('2026-04-01 15:00:00', $utc),
      'FREQ=DAILY;COUNT=1',
    );
    $outTarget = $timeline->projectBaseWindow(
      $rescheduledOut,
      $windowStart,
      $windowEnd,
    )[0];
    $exceptions->createReschedule(
      $rescheduledOut,
      $outTarget,
      new DateTimeImmutable('2026-04-03 14:00:00', $utc),
      new DateTimeImmutable('2026-04-03 15:00:00', $utc),
      'UTC',
    );
    $this->assertSame([], $effective->projectOverlapping($rescheduledOut, $windowStart, $windowEnd));
  }

}
