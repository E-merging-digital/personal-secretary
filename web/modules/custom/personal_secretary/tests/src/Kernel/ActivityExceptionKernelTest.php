<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Database\Database;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\personal_secretary\Entity\ActivityException;
use Drupal\personal_secretary\Service\ActivityExceptionService;
use Drupal\personal_secretary\Service\DomainMutationService;
use Drupal\personal_secretary\Service\EffectiveOccurrenceProjectionService;
use Drupal\personal_secretary\Service\OccurrenceProjectionService;
use Drupal\personal_secretary\Service\RevisionTimelineService;
use InvalidArgumentException;
use TypeError;

/**
 * Proves effective series revisions and audited ActivityException semantics.
 *
 * @group personal_secretary
 */
final class ActivityExceptionKernelTest extends KernelTestBase {

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

  public function testEffectiveRevisionTimelineAndExceptionLifecycle(): void {
    $entityTypeManager = $this->container->get('entity_type.manager');
    /** @var \Drupal\personal_secretary\Service\DomainMutationService $mutations */
    $mutations = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\RevisionTimelineService $timeline */
    $timeline = $this->container->get('personal_secretary.revision_timeline');
    /** @var \Drupal\personal_secretary\Service\OccurrenceProjectionService $baseProjection */
    $baseProjection = $this->container->get('personal_secretary.occurrence_projection');
    /** @var \Drupal\personal_secretary\Service\ActivityExceptionService $exceptions */
    $exceptions = $this->container->get('personal_secretary.activity_exception');
    /** @var \Drupal\personal_secretary\Service\EffectiveOccurrenceProjectionService $effective */
    $effective = $this->container->get('personal_secretary.effective_occurrence_projection');

    $this->assertInstanceOf(DomainMutationService::class, $mutations);
    $this->assertInstanceOf(RevisionTimelineService::class, $timeline);
    $this->assertInstanceOf(ActivityExceptionService::class, $exceptions);
    $this->assertInstanceOf(EffectiveOccurrenceProjectionService::class, $effective);

    $person = $mutations->createPerson('Morgan Example');
    $household = $mutations->createHousehold('Timeline Example Household', [(int) $person->id()]);
    $tz = new DateTimeZone('Europe/Brussels');
    $series = $mutations->createActivitySeries(
      'Timeline Example Routine',
      (int) $household->id(),
      new DateTimeImmutable('2026-01-04 10:00:00', $tz),
      new DateTimeImmutable('2026-01-04 11:00:00', $tz),
      'FREQ=WEEKLY;INTERVAL=1;COUNT=20',
    );
    $seriesStorage = $entityTypeManager->getStorage('personal_sec_activity_series');
    $exceptionStorage = $entityTypeManager->getStorage('personal_sec_activity_exception');

    $revisionA = (string) $series->getRevisionId();
    $this->assertSame('2026-01-04T09:00:00', (string) $series->get('effective_from')->value);

    $baseA = $timeline->projectBaseWindow(
      $series,
      new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')),
      new DateTimeImmutable('2026-03-01 00:00:00', new DateTimeZone('UTC')),
    );
    $byKey = [];
    foreach ($baseA as $occurrence) {
      $byKey[$occurrence->originalOccurrenceKey] = $occurrence;
    }

    $preBoundaryTarget = $byKey['2026-01-18T09:00:00Z'];
    $postBoundaryTarget = $byKey['2026-02-08T09:00:00Z'];

    $preBoundaryCancel = $exceptions->createCancel($series, $preBoundaryTarget);
    $postBoundaryReschedule = $exceptions->createReschedule(
      $series,
      $postBoundaryTarget,
      new DateTimeImmutable('2026-02-08 15:00:00', new DateTimeZone('UTC')),
      new DateTimeImmutable('2026-02-08 16:00:00', new DateTimeZone('UTC')),
      'Europe/Brussels',
    );
    $preBoundaryActiveTimestamp = (int) $preBoundaryCancel->get('lifecycle_persisted_at')->value;
    $postBoundaryActiveTimestamp = (int) $postBoundaryReschedule->get('lifecycle_persisted_at')->value;
    $this->assertGreaterThan(0, $preBoundaryActiveTimestamp);
    $this->assertGreaterThan(0, $postBoundaryActiveTimestamp);
    $postBoundaryActiveRevision = (string) $postBoundaryReschedule->getRevisionId();

    try {
      $exceptions->createCancel($series, $postBoundaryTarget);
      $this->fail('Duplicate active ActivityException target must fail closed.');
    }
    catch (InvalidArgumentException $exception) {
      $this->assertStringContainsString('already targets', $exception->getMessage());
    }

    try {
      $exceptions->createReschedule(
        $series,
        $byKey['2026-01-25T09:00:00Z'],
        new DateTimeImmutable('2026-01-25 15:00:00', new DateTimeZone('UTC')),
        new DateTimeImmutable('2026-01-25 16:00:00', new DateTimeZone('UTC')),
        'America/New_York',
      );
      $this->fail('Cross-timezone reschedule must fail closed.');
    }
    catch (InvalidArgumentException $exception) {
      $this->assertStringContainsString('Cross-timezone', $exception->getMessage());
    }

    try {
      /** @phpstan-ignore-next-line */
      $exceptions->createCancel($series, 1);
      $this->fail('Ordinal-only targets must be rejected by the typed mutation boundary.');
    }
    catch (TypeError) {
      $this->addToAssertionCount(1);
    }

    $boundary = new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC'));
    $series = $mutations->updateActivitySeriesRecurrence(
      $series,
      new DateTimeImmutable('2026-01-04 10:00:00', $tz),
      new DateTimeImmutable('2026-01-04 11:00:00', $tz),
      'FREQ=WEEKLY;INTERVAL=1;COUNT=24',
      $boundary,
    );
    $revisionB = (string) $series->getRevisionId();
    $this->assertNotSame($revisionA, $revisionB);

    try {
      $mutations->updateActivitySeriesRecurrence(
        $series,
        new DateTimeImmutable('2026-01-04 10:00:00', $tz),
        new DateTimeImmutable('2026-01-04 11:00:00', $tz),
        'FREQ=WEEKLY;INTERVAL=1;COUNT=25',
        $boundary,
      );
      $this->fail('Non-increasing effective-from must fail closed.');
    }
    catch (InvalidArgumentException $exception) {
      $this->assertStringContainsString('strictly increasing', $exception->getMessage());
    }

    $historicalA = $seriesStorage->loadRevision((int) $revisionA);
    $this->assertNotNull($historicalA);
    $this->assertSame('2026-01-04T09:00:00', (string) $historicalA->get('effective_from')->value);

    $crossBoundary = $timeline->projectBaseWindow(
      $series,
      new DateTimeImmutable('2026-01-25 00:00:00', new DateTimeZone('UTC')),
      new DateTimeImmutable('2026-02-15 00:00:00', new DateTimeZone('UTC')),
    );
    $this->assertSame($revisionA, $crossBoundary[0]->seriesRevisionId);
    $this->assertSame('2026-01-25T09:00:00Z', $crossBoundary[0]->originalOccurrenceKey);
    $this->assertSame($revisionB, $crossBoundary[1]->seriesRevisionId);
    $this->assertSame('2026-02-01T09:00:00Z', $crossBoundary[1]->originalOccurrenceKey);
    $this->assertSame($revisionB, $crossBoundary[2]->seriesRevisionId);
    $this->assertSame('2026-02-08T09:00:00Z', $crossBoundary[2]->originalOccurrenceKey);

    $reloadedPreBoundary = $exceptionStorage->load($preBoundaryCancel->id());
    $this->assertSame(ActivityException::STATUS_ACTIVE, (string) $reloadedPreBoundary->get('status')->value);
    $this->assertSame($preBoundaryActiveTimestamp, (int) $reloadedPreBoundary->get('lifecycle_persisted_at')->value);

    /** @var \Drupal\personal_secretary\Entity\ActivityException $orphan */
    $orphan = $exceptionStorage->load($postBoundaryReschedule->id());
    $this->assertSame(ActivityException::STATUS_ORPHANED, (string) $orphan->get('status')->value);
    $orphanTimestamp = (int) $orphan->get('lifecycle_persisted_at')->value;
    $this->assertGreaterThan(0, $orphanTimestamp);
    $this->assertGreaterThanOrEqual($postBoundaryActiveTimestamp, $orphanTimestamp);
    $orphanRevision = (string) $orphan->getRevisionId();
    $this->assertNotSame($postBoundaryActiveRevision, $orphanRevision);
    $activeHistory = $exceptionStorage->loadRevision((int) $postBoundaryActiveRevision);
    $this->assertSame(ActivityException::STATUS_ACTIVE, (string) $activeHistory->get('status')->value);
    $this->assertSame($postBoundaryActiveTimestamp, (int) $activeHistory->get('lifecycle_persisted_at')->value);

    // The new revision deliberately generates the same Feb 8 UTC instant. The
    // old-revision exception is still orphaned and therefore is not auto-retargeted.
    $afterEdit = $effective->project(
      $series,
      new DateTimeImmutable('2026-02-08 00:00:00', new DateTimeZone('UTC')),
      new DateTimeImmutable('2026-02-09 00:00:00', new DateTimeZone('UTC')),
    );
    $this->assertCount(1, $afterEdit);
    $this->assertSame($revisionB, $afterEdit[0]->seriesRevisionId);
    $this->assertSame('2026-02-08T09:00:00Z', $afterEdit[0]->originalOccurrenceKey);
    $this->assertNull($afterEdit[0]->exceptionUuid);

    // An arbitrary historical revision can still be explicitly projected, but
    // it cannot be used as a current ActivityException target after the boundary.
    $historicalCandidates = $baseProjection->project(
      $historicalA,
      new DateTimeImmutable('2026-02-08 00:00:00', new DateTimeZone('UTC')),
      new DateTimeImmutable('2026-02-09 00:00:00', new DateTimeZone('UTC')),
    );
    $this->assertCount(1, $historicalCandidates);
    try {
      $exceptions->createCancel($series, $historicalCandidates[0]);
      $this->fail('Arbitrary historical target must fail closed.');
    }
    catch (InvalidArgumentException $exception) {
      $this->assertStringContainsString('currently effective', $exception->getMessage());
    }

    $effectiveTarget = $crossBoundary[2];
    $reconciled = $exceptions->reconcile($orphan, $series, $effectiveTarget);
    $reconciledRevision = (string) $reconciled->getRevisionId();
    $reconciledTimestamp = (int) $reconciled->get('lifecycle_persisted_at')->value;
    $this->assertGreaterThan(0, $reconciledTimestamp);
    $this->assertGreaterThanOrEqual($orphanTimestamp, $reconciledTimestamp);
    $this->assertNotSame($orphanRevision, $reconciledRevision);
    $this->assertSame(ActivityException::STATUS_ACTIVE, (string) $reconciled->get('status')->value);
    $this->assertSame($revisionB, (string) $reconciled->get('target_revision_id')->value);
    $orphanHistory = $exceptionStorage->loadRevision((int) $orphanRevision);
    $this->assertSame(ActivityException::STATUS_ORPHANED, (string) $orphanHistory->get('status')->value);
    $this->assertSame($orphanTimestamp, (int) $orphanHistory->get('lifecycle_persisted_at')->value);
    $this->assertSame(ActivityException::STATUS_ACTIVE, (string) $exceptionStorage->loadRevision((int) $postBoundaryActiveRevision)->get('status')->value);
    $this->assertSame($postBoundaryActiveTimestamp, (int) $exceptionStorage->loadRevision((int) $postBoundaryActiveRevision)->get('lifecycle_persisted_at')->value);

    $afterReconciliation = $effective->project(
      $series,
      new DateTimeImmutable('2026-02-08 00:00:00', new DateTimeZone('UTC')),
      new DateTimeImmutable('2026-02-09 00:00:00', new DateTimeZone('UTC')),
    );
    $this->assertCount(1, $afterReconciliation);
    $this->assertSame('2026-02-08T15:00:00+00:00', $afterReconciliation[0]->effectiveUtcStart);
    $this->assertSame($reconciled->uuid(), $afterReconciliation[0]->exceptionUuid);
    $this->assertSame(ActivityException::ACTION_RESCHEDULE, $afterReconciliation[0]->exceptionAction);

    $otherSeries = $mutations->createActivitySeries(
      'Other Example Routine',
      (int) $household->id(),
      new DateTimeImmutable('2026-01-04 13:00:00', $tz),
      new DateTimeImmutable('2026-01-04 14:00:00', $tz),
      'FREQ=WEEKLY;INTERVAL=1;COUNT=10',
    );
    $otherTarget = $timeline->projectBaseWindow(
      $otherSeries,
      new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC')),
      new DateTimeImmutable('2026-02-02 00:00:00', new DateTimeZone('UTC')),
    )[0];

    // Create a fresh orphan on the first series so cross-series reconciliation
    // can be tested against an actual orphaned current revision.
    $futureTarget = $timeline->projectBaseWindow(
      $series,
      new DateTimeImmutable('2026-02-15 00:00:00', new DateTimeZone('UTC')),
      new DateTimeImmutable('2026-02-16 00:00:00', new DateTimeZone('UTC')),
    )[0];
    $futureCancel = $exceptions->createCancel($series, $futureTarget);
    $series = $mutations->updateActivitySeriesRecurrence(
      $series,
      new DateTimeImmutable('2026-01-04 10:00:00', $tz),
      new DateTimeImmutable('2026-01-04 11:00:00', $tz),
      'FREQ=WEEKLY;INTERVAL=1;COUNT=28',
      new DateTimeImmutable('2026-02-10 00:00:00', new DateTimeZone('UTC')),
    );
    $futureOrphan = $exceptionStorage->load($futureCancel->id());
    $this->assertSame(ActivityException::STATUS_ORPHANED, (string) $futureOrphan->get('status')->value);
    try {
      $exceptions->reconcile($futureOrphan, $otherSeries, $otherTarget);
      $this->fail('Cross-series reconciliation must fail closed.');
    }
    catch (InvalidArgumentException $exception) {
      $this->assertStringContainsString('cannot cross', $exception->getMessage());
    }

    $anonymous = new AnonymousUserSession();
    $ordinary = new UserSession(['uid' => 2, 'roles' => []]);
    $exceptionAccess = $entityTypeManager->getAccessControlHandler('personal_sec_activity_exception');
    $this->assertFalse($exceptionAccess->createAccess(NULL, $anonymous));
    $this->assertFalse($exceptionAccess->createAccess(NULL, $ordinary));
    $this->assertFalse($futureOrphan->access('update', $anonymous));
    $this->assertFalse($futureOrphan->access('update', $ordinary));

    $this->assertFalse($entityTypeManager->hasDefinition('personal_secretary_activity_occurrence'));
    $this->assertFalse(Database::getConnection()->schema()->tableExists('personal_secretary_activity_occurrence'));
  }

  public function testCancelRescheduleAndWindowCorrectOverlay(): void {
    /** @var \Drupal\personal_secretary\Service\DomainMutationService $mutations */
    $mutations = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\RevisionTimelineService $timeline */
    $timeline = $this->container->get('personal_secretary.revision_timeline');
    /** @var \Drupal\personal_secretary\Service\ActivityExceptionService $exceptions */
    $exceptions = $this->container->get('personal_secretary.activity_exception');
    /** @var \Drupal\personal_secretary\Service\EffectiveOccurrenceProjectionService $effective */
    $effective = $this->container->get('personal_secretary.effective_occurrence_projection');

    $person = $mutations->createPerson('Taylor Example');
    $household = $mutations->createHousehold('Overlay Example Household', [(int) $person->id()]);
    $tz = new DateTimeZone('Europe/Brussels');
    $series = $mutations->createActivitySeries(
      'Overlay Example Routine',
      (int) $household->id(),
      new DateTimeImmutable('2026-04-05 10:00:00', $tz),
      new DateTimeImmutable('2026-04-05 11:00:00', $tz),
      'FREQ=WEEKLY;INTERVAL=1;COUNT=8',
    );

    $base = $timeline->projectBaseWindow(
      $series,
      new DateTimeImmutable('2026-04-01 00:00:00', new DateTimeZone('UTC')),
      new DateTimeImmutable('2026-06-01 00:00:00', new DateTimeZone('UTC')),
    );
    $targets = [];
    foreach ($base as $occurrence) {
      $targets[$occurrence->originalOccurrenceKey] = $occurrence;
    }

    $cancelTarget = $targets['2026-04-12T08:00:00Z'];
    $cancel = $exceptions->createCancel($series, $cancelTarget);
    $cancelWindow = $effective->project(
      $series,
      new DateTimeImmutable('2026-04-12 00:00:00', new DateTimeZone('UTC')),
      new DateTimeImmutable('2026-04-13 00:00:00', new DateTimeZone('UTC')),
    );
    $this->assertCount(0, $cancelWindow);
    $this->assertSame(ActivityException::ACTION_CANCEL, (string) $cancel->get('action')->value);

    $rescheduleTarget = $targets['2026-04-19T08:00:00Z'];
    $reschedule = $exceptions->createReschedule(
      $series,
      $rescheduleTarget,
      new DateTimeImmutable('2026-04-19 14:00:00', new DateTimeZone('UTC')),
      new DateTimeImmutable('2026-04-19 15:00:00', new DateTimeZone('UTC')),
      'Europe/Brussels',
    );
    $rescheduledWindow = $effective->project(
      $series,
      new DateTimeImmutable('2026-04-19 00:00:00', new DateTimeZone('UTC')),
      new DateTimeImmutable('2026-04-20 00:00:00', new DateTimeZone('UTC')),
    );
    $this->assertCount(1, $rescheduledWindow);
    $this->assertSame('2026-04-19T14:00:00+00:00', $rescheduledWindow[0]->effectiveUtcStart);
    $this->assertSame('Europe/Brussels', $rescheduledWindow[0]->sourceTimezone);
    $this->assertSame($reschedule->uuid(), $rescheduledWindow[0]->exceptionUuid);
    $this->assertStringContainsString('T16:00:00+02:00', $rescheduledWindow[0]->effectiveSourceLocalStart);

    $outsideTarget = $targets['2026-05-03T08:00:00Z'];
    $exceptions->createReschedule(
      $series,
      $outsideTarget,
      new DateTimeImmutable('2026-04-21 12:00:00', new DateTimeZone('UTC')),
      new DateTimeImmutable('2026-04-21 13:00:00', new DateTimeZone('UTC')),
      'Europe/Brussels',
    );
    $movedIntoWindow = $effective->project(
      $series,
      new DateTimeImmutable('2026-04-21 00:00:00', new DateTimeZone('UTC')),
      new DateTimeImmutable('2026-04-22 00:00:00', new DateTimeZone('UTC')),
    );
    $this->assertCount(1, $movedIntoWindow);
    $this->assertSame('2026-05-03T08:00:00Z', $movedIntoWindow[0]->originalOccurrenceKey);
    $this->assertSame('2026-04-21T12:00:00+00:00', $movedIntoWindow[0]->effectiveUtcStart);

    $insideTarget = $targets['2026-04-26T08:00:00Z'];
    $exceptions->createReschedule(
      $series,
      $insideTarget,
      new DateTimeImmutable('2026-05-10 12:00:00', new DateTimeZone('UTC')),
      new DateTimeImmutable('2026-05-10 13:00:00', new DateTimeZone('UTC')),
      'Europe/Brussels',
    );
    $movedOutOfWindow = $effective->project(
      $series,
      new DateTimeImmutable('2026-04-26 00:00:00', new DateTimeZone('UTC')),
      new DateTimeImmutable('2026-04-27 00:00:00', new DateTimeZone('UTC')),
    );
    $this->assertCount(0, $movedOutOfWindow);

    $limited = $effective->project(
      $series,
      new DateTimeImmutable('2026-04-01 00:00:00', new DateTimeZone('UTC')),
      new DateTimeImmutable('2026-06-01 00:00:00', new DateTimeZone('UTC')),
      2,
    );
    $this->assertCount(2, $limited);
    $this->assertTrue($limited[0]->effectiveUtcStart <= $limited[1]->effectiveUtcStart);
  }

}
