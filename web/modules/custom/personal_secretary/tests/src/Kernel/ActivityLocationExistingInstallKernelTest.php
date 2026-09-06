<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\KernelTests\KernelTestBase;
use Drupal\personal_secretary\Entity\ActivitySeries;

/**
 * Proves update 11004 adds location without changing existing ActivitySeries.
 *
 * @group personal_secretary
 */
final class ActivityLocationExistingInstallKernelTest extends KernelTestBase {

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
    ] as $entityTypeId) {
      $this->installEntitySchema($entityTypeId);
    }
  }

  public function testUpdate11004PreservesExistingSeriesState(): void {
    $manager = $this->container->get('entity_type.manager');
    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $start = new DateTimeImmutable('2030-01-15 09:00:00', new DateTimeZone('Europe/Brussels'));

    $person = $domain->createPerson('Existing location-update person');
    $household = $domain->createHousehold('Existing location-update household', [(int) $person->id()]);
    $series = $domain->createActivitySeries(
      'Existing location-update activity',
      (int) $household->id(),
      $start,
      $start->modify('+1 hour'),
      'FREQ=WEEKLY;INTERVAL=1',
    );

    $seriesId = (int) $series->id();
    $revisionId = (string) $series->getRevisionId();
    $recurrence = $series->get('recurrence')->getValue();
    $effectiveFrom = (string) $series->get('effective_from')->value;

    $updateManager = $this->container->get('entity.definition_update_manager');
    $installedLocation = $updateManager->getFieldStorageDefinition('location', 'personal_sec_activity_series');
    $this->assertNotNull($installedLocation);
    $updateManager->uninstallFieldStorageDefinition($installedLocation);
    $this->assertNull($updateManager->getFieldStorageDefinition('location', 'personal_sec_activity_series'));

    $this->assertNotFalse($this->container->get('module_handler')->loadInclude('personal_secretary', 'install'));
    $result = personal_secretary_update_11004();
    $this->assertSame(
      'Installed the optional ActivitySeries location field with zero data backfill.',
      $result,
    );

    $location = $updateManager->getFieldStorageDefinition('location', 'personal_sec_activity_series');
    $this->assertNotNull($location);
    $this->assertSame('string', $location->getType());
    $this->assertSame(255, $location->getSetting('max_length'));
    $this->assertFalse($location->isRequired());
    $this->assertFalse($location->isRevisionable());

    $manager->clearCachedDefinitions();
    $storage = $manager->getStorage('personal_sec_activity_series');
    $storage->resetCache([$seriesId]);
    $persisted = $storage->load($seriesId);
    $this->assertInstanceOf(ActivitySeries::class, $persisted);
    $this->assertSame($revisionId, (string) $persisted->getRevisionId());
    $this->assertSame($recurrence, $persisted->get('recurrence')->getValue());
    $this->assertSame($effectiveFrom, (string) $persisted->get('effective_from')->value);
    $this->assertTrue($persisted->get('location')->isEmpty());
  }

}
