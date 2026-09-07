<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\personal_secretary\Entity\PreparationReminderDelivery;

/**
 * Proves update 11007 installs only sparse delivery state with zero backfill.
 *
 * @group personal_secretary
 */
final class PreparationReminderExistingInstallKernelTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'datetime', 'datetime_range', 'date_recur', 'personal_secretary',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
  }

  public function testUpdate11007InstallsSparseDeliveryWithoutBackfill(): void {
    $manager = $this->container->get('entity.definition_update_manager');
    $installed = $manager->getEntityType(PreparationReminderDelivery::ENTITY_TYPE_ID);
    if ($installed !== NULL) {
      $manager->uninstallEntityType($installed);
    }
    $this->assertNull($manager->getEntityType(PreparationReminderDelivery::ENTITY_TYPE_ID));

    $userStorage = $this->container->get('entity_type.manager')->getStorage('user');
    $user = $userStorage->create(['name' => 'synthetic-existing-user', 'status' => 1]);
    $user->save();
    $userCount = count($userStorage->loadMultiple());

    $this->assertNotFalse($this->container->get('module_handler')->loadInclude('personal_secretary', 'install'));
    $result = personal_secretary_update_11007();
    $this->assertSame('Installed sparse PreparationReminderDelivery state with zero delivery backfill.', $result);
    $this->assertNotNull($manager->getEntityType(PreparationReminderDelivery::ENTITY_TYPE_ID));

    $this->container->get('entity_type.manager')->clearCachedDefinitions();
    $deliveryStorage = $this->container->get('entity_type.manager')->getStorage(PreparationReminderDelivery::ENTITY_TYPE_ID);
    $this->assertCount(0, $deliveryStorage->loadMultiple());
    $this->assertCount($userCount, $userStorage->loadMultiple());

    $this->assertSame('The PreparationReminderDelivery entity type is already installed.', personal_secretary_update_11007());
  }
}
