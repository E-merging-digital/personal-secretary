<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\personal_secretary\Entity\ActivitySeries;

/**
 * Proves update 11005 adds concerned Persons without changing existing series.
 *
 * @group personal_secretary
 */
final class ConcernedPersonsExistingInstallKernelTest extends KernelTestBase {

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

  public function testUpdate11005PreservesExistingSeriesState(): void {
    $manager = $this->container->get('entity_type.manager');
    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $start = new DateTimeImmutable('2030-02-12 09:00:00', new DateTimeZone('Europe/Brussels'));

    $person = $domain->createPerson('Existing concerned-update person');
    $household = $domain->createHousehold('Existing concerned-update household', [(int) $person->id()]);
    $series = $domain->createActivitySeries(
      'Existing concerned-update activity',
      (int) $household->id(),
      $start,
      $start->modify('+1 hour'),
      'FREQ=WEEKLY;INTERVAL=1',
    );

    $seriesId = (int) $series->id();
    $revisionId = (string) $series->getRevisionId();
    $recurrence = $series->get('recurrence')->getValue();
    $effectiveFrom = (string) $series->get('effective_from')->value;
    $this->assertTrue($series->get('concerned_persons')->isEmpty());

    $updateManager = $this->container->get('entity.definition_update_manager');
    $installedField = $updateManager->getFieldStorageDefinition('concerned_persons', 'personal_sec_activity_series');
    $this->assertNotNull($installedField);
    $updateManager->uninstallFieldStorageDefinition($installedField);
    $this->assertNull($updateManager->getFieldStorageDefinition('concerned_persons', 'personal_sec_activity_series'));

    $this->assertNotFalse($this->container->get('module_handler')->loadInclude('personal_secretary', 'install'));
    $result = personal_secretary_update_11005();
    $this->assertSame(
      'Installed the optional ActivitySeries concerned-person field with zero inference or backfill.',
      $result,
    );

    $field = $updateManager->getFieldStorageDefinition('concerned_persons', 'personal_sec_activity_series');
    $this->assertNotNull($field);
    $this->assertSame('entity_reference', $field->getType());
    $this->assertSame('personal_secretary_person', $field->getSetting('target_type'));
    $this->assertFalse($field->isRequired());
    $this->assertTrue($field->isRevisionable());
    $this->assertSame(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED, $field->getCardinality());

    $manager->clearCachedDefinitions();
    $storage = $manager->getStorage('personal_sec_activity_series');
    $storage->resetCache([$seriesId]);
    $persisted = $storage->load($seriesId);
    $this->assertInstanceOf(ActivitySeries::class, $persisted);
    $this->assertSame($revisionId, (string) $persisted->getRevisionId());
    $this->assertSame($recurrence, $persisted->get('recurrence')->getValue());
    $this->assertSame($effectiveFrom, (string) $persisted->get('effective_from')->value);
    $this->assertTrue($persisted->get('concerned_persons')->isEmpty());
  }

}
