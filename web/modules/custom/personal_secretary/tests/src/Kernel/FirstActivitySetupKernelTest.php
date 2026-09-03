<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\KernelTests\KernelTestBase;
use Drupal\personal_secretary\Service\FirstActivitySetupService;
use InvalidArgumentException;

/**
 * Proves the first-activity setup transaction leaves no partial domain state.
 *
 * @group personal_secretary
 */
final class FirstActivitySetupKernelTest extends KernelTestBase {

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
    $this->installEntitySchema('personal_sec_resp_rule');
    $this->installEntitySchema('personal_sec_resp_override');
    $this->installEntitySchema('personal_sec_prep_req');
  }

  public function testGovernedDownstreamFailureRollsBackWholeSetup(): void {
    /** @var \Drupal\personal_secretary\Service\FirstActivitySetupService $setup */
    $setup = $this->container->get('personal_secretary.first_activity_setup');
    $this->assertInstanceOf(FirstActivitySetupService::class, $setup);

    $timezone = new DateTimeZone('Europe/Brussels');
    try {
      $setup->createFirstActivity(
        'Rollback Synthetic Household',
        'Rollback Synthetic Person',
        'Rollback Synthetic Activity',
        new DateTimeImmutable('2026-09-07 09:00:00', $timezone),
        new DateTimeImmutable('2026-09-07 10:00:00', $timezone),
        'Trigger governed preparation validation',
        -1,
      );
      $this->fail('Negative preparation lead time must fail through the governed preparation mutation service.');
    }
    catch (InvalidArgumentException $exception) {
      $this->assertStringContainsString('zero or greater', $exception->getMessage());
    }

    $entityTypeManager = $this->container->get('entity_type.manager');
    foreach ([
      'personal_secretary_person',
      'personal_secretary_household',
      'personal_sec_activity_series',
      'personal_sec_resp_rule',
      'personal_sec_prep_req',
    ] as $entityTypeId) {
      $count = $entityTypeManager
        ->getStorage($entityTypeId)
        ->getQuery()
        ->accessCheck(FALSE)
        ->count()
        ->execute();
      $this->assertSame(0, (int) $count, sprintf('%s must have no partial rows after rollback.', $entityTypeId));
    }
  }

}
