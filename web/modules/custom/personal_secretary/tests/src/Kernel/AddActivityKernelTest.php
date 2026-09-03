<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\KernelTests\KernelTestBase;
use Drupal\personal_secretary\Service\AddActivityService;
use InvalidArgumentException;

/**
 * Proves failed repeat activity creation preserves the existing domain state.
 *
 * @group personal_secretary
 */
final class AddActivityKernelTest extends KernelTestBase {

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

  public function testMembershipFailureRollsBackAttemptedActivityAndPreservesContext(): void {
    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\ResponsibilityMutationService $responsibilityMutations */
    $responsibilityMutations = $this->container->get('personal_secretary.responsibility_mutation');
    /** @var \Drupal\personal_secretary\Service\AddActivityService $addActivity */
    $addActivity = $this->container->get('personal_secretary.add_activity');
    $this->assertInstanceOf(AddActivityService::class, $addActivity);

    $timezone = new DateTimeZone('Europe/Brussels');
    $member = $domain->createPerson('Existing Member');
    $outsidePerson = $domain->createPerson('Existing Outside Person');
    $household = $domain->createHousehold('Existing Household', [(int) $member->id()]);
    $priorStart = new DateTimeImmutable('2026-09-07 09:00:00', $timezone);
    $priorEnd = new DateTimeImmutable('2026-09-07 10:00:00', $timezone);
    $priorSeries = $domain->createActivitySeries(
      'Existing Prior Activity',
      (int) $household->id(),
      $priorStart,
      $priorEnd,
      'FREQ=WEEKLY;INTERVAL=1',
    );
    $responsibilityMutations->createResponsibilityRule(
      $priorSeries,
      (int) $member->id(),
      $priorStart,
      $priorEnd,
      'FREQ=WEEKLY;INTERVAL=1',
    );

    try {
      $addActivity->addWeeklyActivity(
        (int) $household->id(),
        (int) $outsidePerson->id(),
        'Attempted Invalid Activity',
        new DateTimeImmutable('2026-09-08 14:00:00', $timezone),
        new DateTimeImmutable('2026-09-08 15:00:00', $timezone),
        'Unreached preparation',
        30,
      );
      $this->fail('A non-member responsible Person must fail through governed responsibility validation.');
    }
    catch (InvalidArgumentException $exception) {
      $this->assertStringContainsString('must belong', $exception->getMessage());
    }

    $entityTypeManager = $this->container->get('entity_type.manager');
    $this->assertCount(2, $entityTypeManager->getStorage('personal_secretary_person')->loadMultiple());
    $this->assertCount(1, $entityTypeManager->getStorage('personal_secretary_household')->loadMultiple());
    $this->assertCount(1, $entityTypeManager->getStorage('personal_sec_activity_series')->loadMultiple());
    $this->assertCount(1, $entityTypeManager->getStorage('personal_sec_resp_rule')->loadMultiple());
    $this->assertCount(0, $entityTypeManager->getStorage('personal_sec_prep_req')->loadMultiple());

    $this->assertSame('Existing Member', $entityTypeManager
      ->getStorage('personal_secretary_person')
      ->load($member->id())
      ?->label());
    $this->assertSame('Existing Outside Person', $entityTypeManager
      ->getStorage('personal_secretary_person')
      ->load($outsidePerson->id())
      ?->label());
    $this->assertSame('Existing Household', $entityTypeManager
      ->getStorage('personal_secretary_household')
      ->load($household->id())
      ?->label());
    $this->assertSame('Existing Prior Activity', $entityTypeManager
      ->getStorage('personal_sec_activity_series')
      ->load($priorSeries->id())
      ?->label());
  }

}
