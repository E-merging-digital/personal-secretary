<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\KernelTests\KernelTestBase;
use Drupal\personal_secretary\Entity\PersonalTask;
use Drupal\personal_secretary\Entity\PreparationCompletion;

/**
 * Proves update 11003 installs completion schema with zero backfill.
 *
 * @group personal_secretary
 */
final class PreparationCompletionExistingInstallKernelTest extends KernelTestBase {

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

    // Model an installed current-main site: every pre-11003 domain schema exists,
    // while the newly discovered PreparationCompletion schema does not yet exist.
    foreach ([
      'user',
      'personal_secretary_person',
      'personal_secretary_household',
      'personal_sec_activity_series',
      'personal_sec_activity_exception',
      'personal_sec_resp_rule',
      'personal_sec_resp_override',
      'personal_sec_prep_req',
      'personal_sec_time_commit',
      'personal_sec_task',
    ] as $entityTypeId) {
      $this->installEntitySchema($entityTypeId);
    }
  }

  public function testUpdate11003PreservesExistingSyntheticDomainState(): void {
    $manager = $this->container->get('entity_type.manager');
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $responsibilityMutations = $this->container->get('personal_secretary.responsibility_mutation');
    $preparationMutations = $this->container->get('personal_secretary.preparation_requirement_mutation');
    $timeCommitmentMutations = $this->container->get('personal_secretary.time_commitment_mutation');
    $now = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone(new DateTimeZone('UTC'));

    $person = $domain->createPerson('Existing install synthetic person');
    $household = $domain->createHousehold('Existing install synthetic household', [(int) $person->id()]);
    $start = $now->modify('+2 days');
    $series = $domain->createActivitySeries(
      'Existing install synthetic activity',
      (int) $household->id(),
      $start,
      $start->modify('+1 hour'),
      'FREQ=DAILY;COUNT=1',
    );
    $responsibility = $responsibilityMutations->createResponsibilityRule(
      $series,
      (int) $person->id(),
      $start,
      $start->modify('+1 hour'),
      'FREQ=DAILY;COUNT=1',
    );
    $requirement = $preparationMutations->createPreparationRequirement(
      $series,
      'Existing install synthetic preparation',
      3600,
      $now->modify('-1 day'),
    );
    $commitment = $timeCommitmentMutations->createFullOccurrenceCommitment(
      $series,
      $now->modify('+1 day'),
    );

    $task = $manager->getStorage(PersonalTask::ENTITY_TYPE_ID)->create([
      'title' => 'Existing install synthetic task',
      'household' => ['target_id' => (int) $household->id()],
      'assigned_person' => ['target_id' => (int) $person->id()],
      'due_mode' => PersonalTask::DUE_NONE,
      'status' => PersonalTask::STATUS_OPEN,
    ]);
    $this->assertInstanceOf(PersonalTask::class, $task);
    $task->save();

    $snapshot = [
      'person' => $this->storageSnapshot('personal_secretary_person'),
      'household' => $this->storageSnapshot('personal_secretary_household'),
      'series' => $this->storageSnapshot('personal_sec_activity_series'),
      'responsibility' => $this->storageSnapshot('personal_sec_resp_rule'),
      'requirement' => $this->storageSnapshot('personal_sec_prep_req'),
      'commitment' => $this->storageSnapshot('personal_sec_time_commit'),
      'task' => $this->storageSnapshot('personal_sec_task'),
    ];
    $revisionSnapshot = [
      'series' => (string) $series->getRevisionId(),
      'responsibility' => (string) $responsibility->getRevisionId(),
      'requirement' => (string) $requirement->getRevisionId(),
      'commitment' => (string) $commitment->getRevisionId(),
    ];

    $updateManager = $this->container->get('entity.definition_update_manager');
    $this->assertNull($updateManager->getEntityType(PreparationCompletion::ENTITY_TYPE_ID));
    $this->assertFalse($this->container->get('database')->schema()->tableExists('personal_secretary_preparation_completion'));

    $this->assertNotFalse($this->container->get('module_handler')->loadInclude('personal_secretary', 'install'));
    $result = personal_secretary_update_11003();
    $this->assertSame(
      'Installed the PreparationCompletion fieldable entity type with zero completion backfill.',
      $result,
    );

    $this->assertNotNull($updateManager->getEntityType(PreparationCompletion::ENTITY_TYPE_ID));
    $this->assertTrue($this->container->get('database')->schema()->tableExists('personal_secretary_preparation_completion'));
    $manager->clearCachedDefinitions();
    $this->assertFalse($manager->getDefinition(PreparationCompletion::ENTITY_TYPE_ID)->isRevisionable());
    $completionStorage = $manager->getStorage(PreparationCompletion::ENTITY_TYPE_ID);
    $this->assertSame([], $completionStorage->getQuery()->accessCheck(FALSE)->execute());

    foreach ($snapshot as $name => $expected) {
      $entityType = match ($name) {
        'person' => 'personal_secretary_person',
        'household' => 'personal_secretary_household',
        'series' => 'personal_sec_activity_series',
        'responsibility' => 'personal_sec_resp_rule',
        'requirement' => 'personal_sec_prep_req',
        'commitment' => 'personal_sec_time_commit',
        'task' => 'personal_sec_task',
      };
      $this->assertSame($expected, $this->storageSnapshot($entityType), sprintf('%s state changed during update 11003.', $name));
    }

    $this->assertSame($revisionSnapshot['series'], (string) $manager->getStorage('personal_sec_activity_series')->load($series->id())?->getRevisionId());
    $this->assertSame($revisionSnapshot['responsibility'], (string) $manager->getStorage('personal_sec_resp_rule')->load($responsibility->id())?->getRevisionId());
    $this->assertSame($revisionSnapshot['requirement'], (string) $manager->getStorage('personal_sec_prep_req')->load($requirement->id())?->getRevisionId());
    $this->assertSame($revisionSnapshot['commitment'], (string) $manager->getStorage('personal_sec_time_commit')->load($commitment->id())?->getRevisionId());
  }

  /**
   * @return array{count:int,ids:int[]}
   */
  private function storageSnapshot(string $entityTypeId): array {
    $storage = $this->container->get('entity_type.manager')->getStorage($entityTypeId);
    $ids = array_map('intval', array_values($storage->getQuery()->accessCheck(FALSE)->execute()));
    sort($ids, SORT_NUMERIC);
    return ['count' => count($ids), 'ids' => $ids];
  }

}
