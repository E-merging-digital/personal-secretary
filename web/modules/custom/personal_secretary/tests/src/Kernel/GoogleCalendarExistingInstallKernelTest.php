<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use Drupal\personal_secretary\Entity\CalendarAccountConnection;
use Drupal\personal_secretary\Entity\GoogleCalendarProjection;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves update 11009 installs Google connection state without backfill.
 *
 * @group personal_secretary
 */
#[RunTestsInSeparateProcesses]
final class GoogleCalendarExistingInstallKernelTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'datetime',
    'datetime_range',
    'date_recur',
    'key',
    'oauth2_client',
    'easy_encryption',
    'personal_secretary',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
  }

  public function testUpdate11009InstallsConnectionWithoutBackfill(): void {
    $manager = $this->container->get(
      'entity.definition_update_manager',
    );

    $installed = $manager->getEntityType(
      CalendarAccountConnection::ENTITY_TYPE_ID,
    );

    if ($installed !== NULL) {
      $manager->uninstallEntityType($installed);
    }

    $this->assertNull(
      $manager->getEntityType(
        CalendarAccountConnection::ENTITY_TYPE_ID,
      ),
    );

    $userStorage = $this->container
      ->get('entity_type.manager')
      ->getStorage('user');

    $user = $userStorage->create([
      'name' => 'synthetic-existing-calendar-user',
      'status' => 1,
    ]);
    $user->save();

    $userCount = count($userStorage->loadMultiple());

    $moduleHandler = $this->container->get('module_handler');

    $this->assertTrue(
      $moduleHandler->moduleExists('oauth2_client'),
    );
    $this->assertTrue(
      $moduleHandler->moduleExists('easy_encryption'),
    );

    $this->assertNotFalse(
      $moduleHandler->loadInclude(
        'personal_secretary',
        'install',
      ),
    );

    $result = personal_secretary_update_11009();

    $this->assertSame(
      'Installed CalendarAccountConnection with zero data/token backfill.',
      $result,
    );

    $this->assertNotNull(
      $manager->getEntityType(
        CalendarAccountConnection::ENTITY_TYPE_ID,
      ),
    );

    $this->container
      ->get('entity_type.manager')
      ->clearCachedDefinitions();

    $connectionStorage = $this->container
      ->get('entity_type.manager')
      ->getStorage(CalendarAccountConnection::ENTITY_TYPE_ID);

    $this->assertCount(
      0,
      $connectionStorage->loadMultiple(),
      'Existing users must not receive synthetic Google connections.',
    );

    $this->assertCount(
      $userCount,
      $userStorage->loadMultiple(),
      'Existing product/domain data must be preserved.',
    );

    $this->assertTrue(
      Database::getConnection()
        ->schema()
        ->tableExists('personal_sec_calendar_connection'),
    );

    $this->assertSame(
      'The CalendarAccountConnection entity type is already installed.',
      personal_secretary_update_11009(),
    );
  }

  public function testUpdate11011InstallsUniqueMappingWithZeroBackfill(): void {
    $manager = $this->container->get('entity.definition_update_manager');
    $installed = $manager->getEntityType(GoogleCalendarProjection::ENTITY_TYPE_ID);
    if ($installed !== NULL) {
      $manager->uninstallEntityType($installed);
    }
    $this->container->get('module_handler')->loadInclude('personal_secretary', 'install');
    $this->assertSame('Installed Google Calendar projection with zero backfill.', personal_secretary_update_11011());
    $definition = $manager->getEntityType(GoogleCalendarProjection::ENTITY_TYPE_ID);
    $this->assertNotNull($definition);
    $this->container->get('entity_type.manager')->clearCachedDefinitions();
    $storage = $this->container->get('entity_type.manager')->getStorage(GoogleCalendarProjection::ENTITY_TYPE_ID);
    $this->assertCount(0, $storage->loadMultiple());
    $this->assertTrue(Database::getConnection()->schema()->tableExists('personal_sec_google_projection'));
    foreach (GoogleCalendarProjection::baseFieldDefinitions($definition) as $name => $field) {
      $installedField = $manager->getFieldStorageDefinition($name, GoogleCalendarProjection::ENTITY_TYPE_ID);
      $this->assertNotNull($installedField);
      $this->assertSame($field->getType(), $installedField->getType());
      $this->assertSame($field->getSettings(), $installedField->getSettings());
    }
    $values = [
      'owner_user' => 1, 'provider' => 'google', 'target' => 'primary',
      'series_uuid' => 'synthetic-series', 'original_occurrence_key' => '2031-03-01T09:00:00Z',
      'provider_subject_id' => 'synthetic-subject',
      'event_id' => 'ps' . hash('sha256', 'synthetic-event'), 'etag' => '"synthetic-etag"',
      'payload_fingerprint' => hash('sha256', 'synthetic-payload'), 'state' => GoogleCalendarProjection::ACTIVE,
    ];
    $first = $storage->create($values);
    $first->save();
    // Direct insertion specifically proves the database uniqueness boundary,
    // independently of the entity preSave guard.
    $row = Database::getConnection()->select('personal_sec_google_projection', 'p')->fields('p')->execute()->fetchAssoc();
    unset($row['id']);
    $row['uuid'] = '12345678-1234-4234-8234-123456789abc';
    try {
      Database::getConnection()->insert('personal_sec_google_projection')->fields($row)->execute();
      $this->fail('The database must reject duplicate owner/occurrence mappings.');
    }
    catch (\Drupal\Core\Database\IntegrityConstraintViolationException) {
      $this->addToAssertionCount(1);
    }
    $this->assertSame('The Google Calendar projection entity type is already installed.', personal_secretary_update_11011());
  }

}
