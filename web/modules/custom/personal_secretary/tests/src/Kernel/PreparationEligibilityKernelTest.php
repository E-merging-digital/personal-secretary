<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Database\Database;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\personal_secretary\Entity\PreparationRequirement;
use Drupal\personal_secretary\Service\ActivityExceptionService;
use Drupal\personal_secretary\Service\DomainMutationService;
use Drupal\personal_secretary\Service\EffectiveOccurrenceProjectionService;
use Drupal\personal_secretary\Service\OccurrenceProjectionService;
use Drupal\personal_secretary\Service\PreparationEligibilityService;
use Drupal\personal_secretary\Service\PreparationRequirementMutationService;
use Drupal\personal_secretary\Service\ResponsibilityMutationService;
use Drupal\personal_secretary\Value\EffectiveOccurrence;
use InvalidArgumentException;

/**
 * Proves reusable preparation requirements and deterministic eligibility.
 *
 * @group personal_secretary
 */
final class PreparationEligibilityKernelTest extends KernelTestBase {

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

  public function testPreparationRequirementLifecycleAndEligibility(): void {
    $entityTypeManager = $this->container->get('entity_type.manager');
    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\ResponsibilityMutationService $responsibilityMutations */
    $responsibilityMutations = $this->container->get('personal_secretary.responsibility_mutation');
    /** @var \Drupal\personal_secretary\Service\PreparationRequirementMutationService $requirementMutations */
    $requirementMutations = $this->container->get('personal_secretary.preparation_requirement_mutation');
    /** @var \Drupal\personal_secretary\Service\PreparationEligibilityService $eligibility */
    $eligibility = $this->container->get('personal_secretary.preparation_eligibility');
    /** @var \Drupal\personal_secretary\Service\EffectiveOccurrenceProjectionService $effective */
    $effective = $this->container->get('personal_secretary.effective_occurrence_projection');
    /** @var \Drupal\personal_secretary\Service\OccurrenceProjectionService $base */
    $base = $this->container->get('personal_secretary.occurrence_projection');
    /** @var \Drupal\personal_secretary\Service\ActivityExceptionService $exceptions */
    $exceptions = $this->container->get('personal_secretary.activity_exception');

    $this->assertInstanceOf(PreparationRequirementMutationService::class, $requirementMutations);
    $this->assertInstanceOf(PreparationEligibilityService::class, $eligibility);
    $this->assertTrue($entityTypeManager->getDefinition('personal_sec_prep_req')->isRevisionable());

    $person = $domain->createPerson('Parker Example');
    $household = $domain->createHousehold('Preparation Example Household', [(int) $person->id()]);
    $tz = new DateTimeZone('Europe/Brussels');
    $series = $domain->createActivitySeries(
      'Preparation Example Routine',
      (int) $household->id(),
      new DateTimeImmutable('2026-01-04 10:00:00', $tz),
      new DateTimeImmutable('2026-01-04 11:00:00', $tz),
      'FREQ=WEEKLY;INTERVAL=1',
    );
    $responsibilityMutations->createResponsibilityRule(
      $series,
      (int) $person->id(),
      new DateTimeImmutable('2026-01-04 09:30:00', $tz),
      new DateTimeImmutable('2026-01-04 10:30:00', $tz),
      'FREQ=WEEKLY;INTERVAL=1',
    );

    $requirement = $requirementMutations->createPreparationRequirement(
      $series,
      'Pack synthetic equipment',
      3600,
      new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')),
    );
    $initialRevision = (string) $requirement->getRevisionId();
    $initialTimestamp = (int) $requirement->get('lifecycle_persisted_at')->value;
    $this->assertGreaterThan(0, $initialTimestamp);

    try {
      $requirementMutations->createPreparationRequirement(
        $series,
        'Invalid negative lead time',
        -1,
        new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')),
      );
      $this->fail('Negative PreparationRequirement lead time must fail closed.');
    }
    catch (InvalidArgumentException $exception) {
      $this->assertStringContainsString('zero or greater', $exception->getMessage());
    }

    try {
      $requirementMutations->createPreparationRequirement(
        $series,
        'Invalid effective window',
        0,
        new DateTimeImmutable('2026-01-10 00:00:00', new DateTimeZone('UTC')),
        new DateTimeImmutable('2026-01-10 00:00:00', new DateTimeZone('UTC')),
      );
      $this->fail('Non-positive PreparationRequirement effective windows must fail closed.');
    }
    catch (InvalidArgumentException $exception) {
      $this->assertStringContainsString('after effective-from', $exception->getMessage());
    }

    $jan11 = $this->singleEffective($effective, $series, '2026-01-11 00:00:00', '2026-01-12 00:00:00');
    $assigned = $eligibility->derive($series, $jan11);
    $this->assertCount(1, $assigned);
    $this->assertSame((int) $requirement->id(), $assigned[0]->requirementId);
    $this->assertSame($initialRevision, $assigned[0]->requirementRevisionId);
    $this->assertSame('Pack synthetic equipment', $assigned[0]->requirementLabel);
    $this->assertSame((int) $person->id(), $assigned[0]->responsiblePersonId);
    $this->assertSame($person->uuid(), $assigned[0]->responsiblePersonUuid);
    $this->assertSame(3600, $assigned[0]->leadTimeSeconds);
    $this->assertSame('2026-01-11T08:00:00+00:00', $assigned[0]->dueAtUtc);

    $noResponsibilitySeries = $domain->createActivitySeries(
      'Preparation Example Unassigned Routine',
      (int) $household->id(),
      new DateTimeImmutable('2026-01-11 15:00:00', $tz),
      new DateTimeImmutable('2026-01-11 16:00:00', $tz),
      'FREQ=WEEKLY;INTERVAL=1',
    );
    $requirementMutations->createPreparationRequirement(
      $noResponsibilitySeries,
      'Prepare only when assigned',
      900,
      new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')),
    );
    $unassignedOccurrence = $this->singleEffective(
      $effective,
      $noResponsibilitySeries,
      '2026-01-11 00:00:00',
      '2026-01-12 00:00:00',
    );
    $this->assertSame([], $eligibility->derive($noResponsibilitySeries, $unassignedOccurrence));

    $jan18 = $this->singleEffective($effective, $series, '2026-01-18 00:00:00', '2026-01-19 00:00:00');
    $responsibilityMutations->createClearOverride($series, $jan18);
    $this->assertSame([], $eligibility->derive($series, $jan18));

    $jan25Base = $base->project(
      $series,
      new DateTimeImmutable('2026-01-25 00:00:00', new DateTimeZone('UTC')),
      new DateTimeImmutable('2026-01-26 00:00:00', new DateTimeZone('UTC')),
    )[0];
    $exceptions->createReschedule(
      $series,
      $jan25Base,
      new DateTimeImmutable('2026-02-01 09:00:00', new DateTimeZone('UTC')),
      new DateTimeImmutable('2026-02-01 10:00:00', new DateTimeZone('UTC')),
      'Europe/Brussels',
    );
    $rescheduled = $this->findByOriginalKey(
      $effective->project(
        $series,
        new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC')),
        new DateTimeImmutable('2026-02-02 00:00:00', new DateTimeZone('UTC')),
      ),
      $jan25Base->originalOccurrenceKey,
    );

    $lateRequirement = $requirementMutations->createPreparationRequirement(
      $series,
      'Add synthetic late preparation',
      1800,
      new DateTimeImmutable('2026-02-01 08:30:00', new DateTimeZone('UTC')),
    );
    $rescheduledResults = $eligibility->derive($series, $rescheduled);
    $this->assertCount(2, $rescheduledResults);
    $this->assertSame('2026-02-01T09:00:00+00:00', $rescheduledResults[0]->effectiveUtcStart);
    $this->assertSame('2026-02-01T08:00:00+00:00', $rescheduledResults[0]->dueAtUtc);
    $this->assertSame((int) $lateRequirement->id(), $rescheduledResults[1]->requirementId);
    $this->assertSame('2026-02-01T08:30:00+00:00', $rescheduledResults[1]->dueAtUtc);

    $feb8Base = $base->project(
      $series,
      new DateTimeImmutable('2026-02-08 00:00:00', new DateTimeZone('UTC')),
      new DateTimeImmutable('2026-02-09 00:00:00', new DateTimeZone('UTC')),
    )[0];
    $exceptions->createCancel($series, $feb8Base);
    $this->assertCount(0, $effective->project(
      $series,
      new DateTimeImmutable('2026-02-08 00:00:00', new DateTimeZone('UTC')),
      new DateTimeImmutable('2026-02-09 00:00:00', new DateTimeZone('UTC')),
    ));

    $requirement = $requirementMutations->retirePreparationRequirement(
      $requirement,
      new DateTimeImmutable('2026-01-18 09:00:00', new DateTimeZone('UTC')),
    );
    $this->assertNotSame($initialRevision, (string) $requirement->getRevisionId());
    $historical = $entityTypeManager
      ->getStorage('personal_sec_prep_req')
      ->loadRevision((int) $initialRevision);
    $this->assertInstanceOf(PreparationRequirement::class, $historical);
    $this->assertSame($initialTimestamp, (int) $historical->get('lifecycle_persisted_at')->value);
    $this->assertCount(1, $eligibility->derive($series, $jan11));
    $afterCutoff = $eligibility->derive($series, $rescheduled);
    $this->assertCount(1, $afterCutoff);
    $this->assertSame((int) $lateRequirement->id(), $afterCutoff[0]->requirementId);

    try {
      $requirementMutations->retirePreparationRequirement(
        $requirement,
        new DateTimeImmutable('2026-02-15 09:00:00', new DateTimeZone('UTC')),
      );
      $this->fail('A retired PreparationRequirement cannot be retired twice.');
    }
    catch (InvalidArgumentException $exception) {
      $this->assertStringContainsString('cannot be retired again', $exception->getMessage());
    }

    $anonymous = new AnonymousUserSession();
    $this->assertFalse($entityTypeManager->getAccessControlHandler('personal_sec_prep_req')->createAccess(NULL, $anonymous));

    $this->assertFalse($entityTypeManager->hasDefinition('personal_sec_preparation_eligibility'));
    $this->assertFalse($entityTypeManager->hasDefinition('personal_sec_preparation_occurrence'));
    $schema = Database::getConnection()->schema();
    $this->assertFalse($schema->tableExists('personal_secretary_preparation_eligibility'));
    $this->assertFalse($schema->tableExists('personal_secretary_preparation_occurrence'));
  }

  private function singleEffective(
    EffectiveOccurrenceProjectionService $effective,
    $series,
    string $windowStart,
    string $windowEnd,
  ): EffectiveOccurrence {
    $occurrences = $effective->project(
      $series,
      new DateTimeImmutable($windowStart, new DateTimeZone('UTC')),
      new DateTimeImmutable($windowEnd, new DateTimeZone('UTC')),
    );
    $this->assertCount(1, $occurrences);
    return $occurrences[0];
  }

  /**
   * @param \Drupal\personal_secretary\Value\EffectiveOccurrence[] $occurrences
   */
  private function findByOriginalKey(array $occurrences, string $key): EffectiveOccurrence {
    foreach ($occurrences as $occurrence) {
      if ($occurrence->originalOccurrenceKey === $key) {
        return $occurrence;
      }
    }
    $this->fail('Expected EffectiveOccurrence was not projected.');
  }

}
