<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\KernelTests\KernelTestBase;
use Drupal\personal_secretary\Entity\ActivitySeries;

/**
 * Proves update 11006 adds deterministic TIMED mode to existing series.
 *
 * @group personal_secretary
 */
final class AllDayExistingInstallKernelTest extends KernelTestBase {

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
      'user',
      'personal_secretary_person',
      'personal_secretary_household',
      'personal_sec_activity_series',
      'personal_sec_activity_exception',
    ] as $entityTypeId) {
      $this->installEntitySchema($entityTypeId);
    }
  }

  public function testUpdate11006PreservesExistingSeriesAndRevisions(): void {
    $manager = $this->container->get('entity_type.manager');
    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $timezone = new DateTimeZone('Europe/Brussels');
    $start = new DateTimeImmutable('2030-02-12 09:00:00', $timezone);

    $personA = $domain->createPerson('Existing all-day update person A');
    $personB = $domain->createPerson('Existing all-day update person B');
    $household = $domain->createHousehold('Existing all-day update household', [
      (int) $personA->id(),
      (int) $personB->id(),
    ]);
    $series = $domain->createActivitySeries(
      'Existing timed activity',
      (int) $household->id(),
      $start,
      $start->modify('+1 hour'),
      'FREQ=WEEKLY;INTERVAL=1',
      'Existing location',
      [(int) $personB->id()],
    );

    $seriesId = (int) $series->id();
    $seriesUuid = $series->uuid();
    $originalRevisionId = (int) $series->getRevisionId();
    $originalRecurrence = $series->get('recurrence')->getValue();
    $originalEffectiveFrom = (string) $series->get('effective_from')->value;
    $originalLocation = (string) $series->get('location')->value;
    $originalConcerned = $series->get('concerned_persons')->getValue();

    $revisedStart = $start->modify('+7 days')->setTime(10, 0);
    $series = $domain->updateActivitySeriesRecurrence(
      $series,
      $revisedStart,
      $revisedStart->modify('+90 minutes'),
      'FREQ=WEEKLY;INTERVAL=1',
      $revisedStart,
    );
    $latestRevisionId = (int) $series->getRevisionId();
    $latestRecurrence = $series->get('recurrence')->getValue();
    $latestEffectiveFrom = (string) $series->get('effective_from')->value;
    $latestConcerned = $series->get('concerned_persons')->getValue();
    $this->assertNotSame($originalRevisionId, $latestRevisionId);
    $this->assertSame(ActivitySeries::TIME_MODE_TIMED, $series->timeMode());

    $updateManager = $this->container->get('entity.definition_update_manager');
    $installedField = $updateManager->getFieldStorageDefinition('time_mode', 'personal_sec_activity_series');
    $this->assertNotNull($installedField);
    $updateManager->uninstallFieldStorageDefinition($installedField);
    $this->assertNull($updateManager->getFieldStorageDefinition('time_mode', 'personal_sec_activity_series'));

    $this->assertNotFalse($this->container->get('module_handler')->loadInclude('personal_secretary', 'install'));
    $result = personal_secretary_update_11006();
    $this->assertSame(
      'Installed the ActivitySeries time-mode field with deterministic TIMED legacy default and zero timestamp inference.',
      $result,
    );

    $field = $updateManager->getFieldStorageDefinition('time_mode', 'personal_sec_activity_series');
    $this->assertNotNull($field);
    $this->assertSame('string', $field->getType());
    $this->assertTrue($field->isRequired());
    $this->assertTrue($field->isRevisionable());
    $this->assertSame(16, $field->getSetting('max_length'));

    $manager->clearCachedDefinitions();
    $storage = $manager->getStorage('personal_sec_activity_series');
    $storage->resetCache([$seriesId]);
    $persisted = $storage->load($seriesId);
    $this->assertInstanceOf(ActivitySeries::class, $persisted);
    $this->assertSame($seriesId, (int) $persisted->id());
    $this->assertSame($seriesUuid, $persisted->uuid());
    $this->assertSame($latestRevisionId, (int) $persisted->getRevisionId());
    $this->assertEquals($latestRecurrence, $persisted->get('recurrence')->getValue());
    $this->assertSame($latestEffectiveFrom, (string) $persisted->get('effective_from')->value);
    $this->assertSame($originalLocation, (string) $persisted->get('location')->value);
    $this->assertSame($latestConcerned, $persisted->get('concerned_persons')->getValue());
    $this->assertSame(ActivitySeries::TIME_MODE_TIMED, $persisted->timeMode());
    $this->assertSame(ActivitySeries::TIME_MODE_TIMED, (string) $persisted->get('time_mode')->value);

    $historical = $storage->loadRevision($originalRevisionId);
    $this->assertInstanceOf(ActivitySeries::class, $historical);
    $this->assertSame($seriesId, (int) $historical->id());
    $this->assertSame($seriesUuid, $historical->uuid());
    $this->assertSame($originalRevisionId, (int) $historical->getRevisionId());
    $this->assertEquals($originalRecurrence, $historical->get('recurrence')->getValue());
    $this->assertSame($originalEffectiveFrom, (string) $historical->get('effective_from')->value);
    $this->assertSame($originalLocation, (string) $historical->get('location')->value);
    $this->assertSame($originalConcerned, $historical->get('concerned_persons')->getValue());
    $this->assertSame(ActivitySeries::TIME_MODE_TIMED, $historical->timeMode());
    $this->assertSame(ActivitySeries::TIME_MODE_TIMED, (string) $historical->get('time_mode')->value);
  }

}
