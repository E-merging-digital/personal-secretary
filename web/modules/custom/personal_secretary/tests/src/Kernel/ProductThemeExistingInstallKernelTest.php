<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use Drupal\block\Entity\Block;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves update 11010 repairs existing product-theme block state.
 *
 * @group personal_secretary
 */
#[RunTestsInSeparateProcesses]
final class ProductThemeExistingInstallKernelTest extends KernelTestBase {

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
    'block',
    'personal_secretary',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->container
      ->get('theme_installer')
      ->install(['personal_secretary_product']);
  }

  public function testUpdate11010RestoresOnlyRegionAndStatus(): void {
    $targets = [
      'personal_secretary_product_content' => [
        'plugin' => 'system_main_block',
        'region' => 'content',
        'weight' => 0,
        'settings' => [
          'id' => 'system_main_block',
          'label' => 'Main page content',
          'label_display' => '0',
          'provider' => 'system',
        ],
      ],
      'personal_secretary_product_messages' => [
        'plugin' => 'system_messages_block',
        'region' => 'highlighted',
        'weight' => -10,
        'settings' => [
          'id' => 'system_messages_block',
          'label' => 'Status messages',
          'label_display' => '0',
          'provider' => 'system',
        ],
      ],
      'personal_secretary_product_page_title' => [
        'plugin' => 'page_title_block',
        'region' => 'content',
        'weight' => -10,
        'settings' => [
          'id' => 'page_title_block',
          'label' => 'Page title',
          'label_display' => '0',
          'provider' => 'core',
        ],
      ],
      'personal_secretary_product_shell' => [
        'plugin' => 'personal_secretary_product_shell',
        'region' => 'header',
        'weight' => -20,
        'settings' => [
          'id' => 'personal_secretary_product_shell',
          'label' => 'Personal Secretary product shell',
          'label_display' => '0',
          'provider' => 'personal_secretary',
        ],
      ],
    ];

    $storage = $this->container
      ->get('entity_type.manager')
      ->getStorage('block');

    foreach ($targets as $id => $expected) {
      Block::create([
        'id' => $id,
        'langcode' => 'en',
        'status' => TRUE,
        'theme' => 'personal_secretary_product',
        'region' => $expected['region'],
        'weight' => $expected['weight'],
        'provider' => NULL,
        'plugin' => $expected['plugin'],
        'settings' => $expected['settings'],
        'visibility' => [],
      ])->save();
    }

    // Simulate the diagnosed B/C/D state: all blocks disabled in the
    // product theme's default region.
    foreach (array_keys($targets) as $id) {
      $block = $storage->load($id);
      $this->assertInstanceOf(Block::class, $block);
      $block
        ->setRegion('header')
        ->disable()
        ->save();
    }

    $before = [];
    $configFactory = $this->container->get('config.factory');
    foreach ($targets as $id => $expected) {
      $storage->resetCache([$id]);
      $block = $storage->load($id);
      $this->assertInstanceOf(Block::class, $block);
      $this->assertSame($id, $block->id());
      $this->assertSame($expected['plugin'], $block->getPluginId());
      $this->assertSame('header', $block->getRegion());
      $this->assertFalse($block->status());

      $configName = 'block.block.' . $id;
      $configFactory->reset($configName);
      $before[$id] = $configFactory
        ->get($configName)
        ->getRawData();
    }

    $moduleHandler = $this->container->get('module_handler');
    $this->assertNotFalse(
      $moduleHandler->loadInclude(
        'personal_secretary',
        'install',
      ),
    );

    $message = personal_secretary_update_11010();
    $this->assertSame(
      'Restored Personal Secretary product-theme block placement on existing installs.',
      $message,
    );

    $firstPass = [];
    foreach ($targets as $id => $expected) {
      $storage->resetCache([$id]);
      $block = $storage->load($id);
      $this->assertInstanceOf(Block::class, $block);
      $this->assertSame($id, $block->id());
      $this->assertSame($expected['plugin'], $block->getPluginId());
      $this->assertSame($expected['region'], $block->getRegion());
      $this->assertTrue($block->status());

      $configName = 'block.block.' . $id;
      $configFactory->reset($configName);
      $after = $configFactory
        ->get($configName)
        ->getRawData();

      $beforeUnrelated = $before[$id];
      $afterUnrelated = $after;
      unset(
        $beforeUnrelated['region'],
        $beforeUnrelated['status'],
        $afterUnrelated['region'],
        $afterUnrelated['status'],
      );
      $this->assertSame($beforeUnrelated, $afterUnrelated);

      $firstPass[$id] = $after;
    }

    // Direct repeat invocation is safe and leaves the corrected state intact.
    $this->assertSame(
      'Restored Personal Secretary product-theme block placement on existing installs.',
      personal_secretary_update_11010(),
    );

    foreach (array_keys($targets) as $id) {
      $configName = 'block.block.' . $id;
      $configFactory->reset($configName);
      $this->assertSame(
        $firstPass[$id],
        $configFactory->get($configName)->getRawData(),
      );
    }
  }

}
