<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use Drupal\personal_secretary\Entity\CalendarAccountConnection;
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

}
