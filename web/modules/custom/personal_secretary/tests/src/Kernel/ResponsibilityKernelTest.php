<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Database\Database;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\personal_secretary\Entity\ResponsibilityOverride;
use Drupal\personal_secretary\Entity\ResponsibilityRule;
use Drupal\personal_secretary\Service\ActivityExceptionService;
use Drupal\personal_secretary\Service\DomainMutationService;
use Drupal\personal_secretary\Service\EffectiveOccurrenceProjectionService;
use Drupal\personal_secretary\Service\EffectiveResponsibilityService;
use Drupal\personal_secretary\Service\OccurrenceProjectionService;
use Drupal\personal_secretary\Service\ResponsibilityMutationService;
use Drupal\personal_secretary\Value\EffectiveOccurrence;
use Drupal\personal_secretary\Value\EffectiveResponsibility;
use InvalidArgumentException;
use RuntimeException;

/**
 * Proves deterministic recurring responsibility and exact-target overrides.
 *
 * @group personal_secretary
 */
final class ResponsibilityKernelTest extends KernelTestBase {

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
  }

  public function testResponsibilityPrecedenceLifecycleAndRescheduleInteraction(): void {
    $entityTypeManager = $this->container->get('entity_type.manager');
    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\ResponsibilityMutationService $mutations */
    $mutations = $this->container->get('personal_secretary.responsibility_mutation');
    /** @var \Drupal\personal_secretary\Service\EffectiveResponsibilityService $resolver */
    $resolver = $this->container->get('personal_secretary.effective_responsibility');
    /** @var \Drupal\personal_secretary\Service\EffectiveOccurrenceProjectionService $effective */
    $effective = $this->container->get('personal_secretary.effective_occurrence_projection');
    /** @var \Drupal\personal_secretary\Service\OccurrenceProjectionService $base */
    $base = $this->container->get('personal_secretary.occurrence_projection');
    /** @var \Drupal\personal_secretary\Service\ActivityExceptionService $exceptions */
    $exceptions = $this->container->get('personal_secretary.activity_exception');

    $this->assertInstanceOf(ResponsibilityMutationService::class, $mutations);
    $this->assertInstanceOf(EffectiveResponsibilityService::class, $resolver);
    $this->assertTrue($entityTypeManager->getDefinition('personal_sec_resp_rule')->isRevisionable());
    $this->assertTrue($entityTypeManager->getDefinition('personal_sec_resp_override')->isRevisionable());

    $personA = $domain->createPerson('Avery Example');
    $personB = $domain->createPerson('Blake Example');
    $nonMember = $domain->createPerson('Casey Example');
    $household = $domain->createHousehold('Responsibility Example Household', [
      (int) $personA->id(),
      (int) $personB->id(),
    ]);
    $tz = new DateTimeZone('Europe/Brussels');
    $series = $domain->createActivitySeries(
      'Responsibility Example Routine',
      (int) $household->id(),
      new DateTimeImmutable('2026-01-04 10:00:00', $tz),
      new DateTimeImmutable('2026-01-04 11:00:00', $tz),
      'FREQ=WEEKLY;INTERVAL=1',
    );

    $jan11 = $this->singleEffective($effective, $series, '2026-01-11 00:00:00', '2026-01-12 00:00:00');
    $jan18 = $this->singleEffective($effective, $series, '2026-01-18 00:00:00', '2026-01-19 00:00:00');
    $jan25 = $this->singleEffective($effective, $series, '2026-01-25 00:00:00', '2026-01-26 00:00:00');

    $none = $resolver->resolve($series, $jan11);
    $this->assertSame(EffectiveResponsibility::STATE_NONE, $none->state);
    $this->assertSame(EffectiveResponsibility::SOURCE_NONE, $none->source);

    $ruleA = $mutations->createResponsibilityRule(
      $series,
      (int) $personA->id(),
      new DateTimeImmutable('2026-01-04 09:30:00', $tz),
      new DateTimeImmutable('2026-01-04 10:30:00', $tz),
      'FREQ=WEEKLY;INTERVAL=1',
    );
    $ruleARevision = (string) $ruleA->getRevisionId();
    $ruleATimestamp = (int) $ruleA->get('lifecycle_persisted_at')->value;
    $this->assertGreaterThan(0, $ruleATimestamp);

    $fromRule = $resolver->resolve($series, $jan11);
    $this->assertSame(EffectiveResponsibility::STATE_ASSIGNED, $fromRule->state);
    $this->assertSame(EffectiveResponsibility::SOURCE_RULE, $fromRule->source);
    $this->assertSame((int) $personA->id(), $fromRule->responsiblePersonId);
    $this->assertSame((int) $ruleA->id(), $fromRule->ruleId);
    $this->assertSame($ruleARevision, $fromRule->ruleRevisionId);

    try {
      $mutations->createResponsibilityRule(
        $series,
        (int) $nonMember->id(),
        new DateTimeImmutable('2026-01-04 09:30:00', $tz),
        new DateTimeImmutable('2026-01-04 10:30:00', $tz),
        'FREQ=WEEKLY;INTERVAL=1',
      );
      $this->fail('A ResponsibilityRule cannot assign a non-household Person.');
    }
    catch (InvalidArgumentException $exception) {
      $this->assertStringContainsString('Household', $exception->getMessage());
    }

    try {
      $mutations->createResponsibilityRule(
        $series,
        (int) $personA->id(),
        new DateTimeImmutable('2026-01-04 09:30:00', $tz),
        new DateTimeImmutable('2026-01-04 09:30:00', $tz),
        'FREQ=WEEKLY;INTERVAL=1',
      );
      $this->fail('ResponsibilityRule windows require positive duration.');
    }
    catch (InvalidArgumentException $exception) {
      $this->assertStringContainsString('after start', $exception->getMessage());
    }

    $ruleA = $mutations->retireResponsibilityRule(
      $ruleA,
      new DateTimeImmutable('2026-01-18 09:00:00', new DateTimeZone('UTC')),
    );
    $this->assertNotSame($ruleARevision, (string) $ruleA->getRevisionId());
    $historicalRule = $entityTypeManager
      ->getStorage('personal_sec_resp_rule')
      ->loadRevision((int) $ruleARevision);
    $this->assertInstanceOf(ResponsibilityRule::class, $historicalRule);
    $this->assertSame($ruleATimestamp, (int) $historicalRule->get('lifecycle_persisted_at')->value);
    $this->assertSame(EffectiveResponsibility::SOURCE_RULE, $resolver->resolve($series, $jan11)->source);
    $this->assertSame(EffectiveResponsibility::SOURCE_NONE, $resolver->resolve($series, $jan18)->source);

    try {
      $mutations->retireResponsibilityRule(
        $ruleA,
        new DateTimeImmutable('2026-01-25 09:00:00', new DateTimeZone('UTC')),
      );
      $this->fail('A retired ResponsibilityRule cannot be retired again.');
    }
    catch (InvalidArgumentException $exception) {
      $this->assertStringContainsString('cannot be retired again', $exception->getMessage());
    }

    $ruleB = $mutations->createResponsibilityRule(
      $series,
      (int) $personB->id(),
      new DateTimeImmutable('2026-01-04 09:30:00', $tz),
      new DateTimeImmutable('2026-01-04 10:30:00', $tz),
      'FREQ=WEEKLY;INTERVAL=2',
    );
    $jan18Rule = $resolver->resolve($series, $jan18);
    $this->assertSame((int) $personB->id(), $jan18Rule->responsiblePersonId);
    $this->assertSame((int) $ruleB->id(), $jan18Rule->ruleId);

    try {
      $mutations->createAssignOverride($series, $jan25, (int) $nonMember->id());
      $this->fail('ASSIGN_PERSON override cannot assign a non-household Person.');
    }
    catch (InvalidArgumentException $exception) {
      $this->assertStringContainsString('Household', $exception->getMessage());
    }

    $fake = new EffectiveOccurrence(
      seriesUuid: $jan25->seriesUuid,
      seriesRevisionId: $jan25->seriesRevisionId,
      originalOccurrenceKey: $jan25->originalOccurrenceKey,
      originalUtcStart: $jan25->originalUtcStart,
      originalUtcEnd: $jan25->originalUtcEnd,
      originalSourceLocalStart: $jan25->originalSourceLocalStart,
      originalSourceLocalEnd: $jan25->originalSourceLocalEnd,
      sourceTimezone: $jan25->sourceTimezone,
      effectiveUtcStart: '2026-01-25T09:05:00+00:00',
      effectiveUtcEnd: '2026-01-25T10:05:00+00:00',
      effectiveSourceLocalStart: '2026-01-25T10:05:00+01:00',
      effectiveSourceLocalEnd: '2026-01-25T11:05:00+01:00',
    );
    try {
      $mutations->createClearOverride($series, $fake);
      $this->fail('Arbitrary EffectiveOccurrence DTOs must fail closed.');
    }
    catch (InvalidArgumentException $exception) {
      $this->assertStringContainsString('currently effective', $exception->getMessage());
    }

    $override = $mutations->createAssignOverride($series, $jan18, (int) $personA->id());
    $assignRevision = (string) $override->getRevisionId();
    $assignTimestamp = (int) $override->get('lifecycle_persisted_at')->value;
    $fromOverride = $resolver->resolve($series, $jan18);
    $this->assertSame(EffectiveResponsibility::SOURCE_OVERRIDE, $fromOverride->source);
    $this->assertSame((int) $personA->id(), $fromOverride->responsiblePersonId);
    $this->assertSame((int) $override->id(), $fromOverride->overrideId);
    $this->assertSame($assignRevision, $fromOverride->overrideRevisionId);

    try {
      $mutations->createClearOverride($series, $jan18);
      $this->fail('Duplicate active ResponsibilityOverride targets must fail closed.');
    }
    catch (InvalidArgumentException $exception) {
      $this->assertStringContainsString('already targets', $exception->getMessage());
    }

    $override = $mutations->supersedeOverride(
      $override,
      $series,
      $jan18,
      ResponsibilityOverride::ACTION_CLEAR_RESPONSIBILITY,
    );
    $clearRevision = (string) $override->getRevisionId();
    $cleared = $resolver->resolve($series, $jan18);
    $this->assertSame(EffectiveResponsibility::STATE_NONE, $cleared->state);
    $this->assertSame(EffectiveResponsibility::SOURCE_OVERRIDE, $cleared->source);
    $historicalAssign = $entityTypeManager
      ->getStorage('personal_sec_resp_override')
      ->loadRevision((int) $assignRevision);
    $this->assertInstanceOf(ResponsibilityOverride::class, $historicalAssign);
    $this->assertSame($assignTimestamp, (int) $historicalAssign->get('lifecycle_persisted_at')->value);

    $override = $mutations->withdrawOverride($override);
    $this->assertSame(ResponsibilityOverride::STATUS_WITHDRAWN, (string) $override->get('status')->value);
    $fallback = $resolver->resolve($series, $jan18);
    $this->assertSame(EffectiveResponsibility::SOURCE_RULE, $fallback->source);
    $this->assertSame((int) $personB->id(), $fallback->responsiblePersonId);
    $historicalClear = $entityTypeManager
      ->getStorage('personal_sec_resp_override')
      ->loadRevision((int) $clearRevision);
    $this->assertInstanceOf(ResponsibilityOverride::class, $historicalClear);
    $this->assertSame(ResponsibilityOverride::STATUS_ACTIVE, (string) $historicalClear->get('status')->value);

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
    $feb1 = $effective->project(
      $series,
      new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC')),
      new DateTimeImmutable('2026-02-02 00:00:00', new DateTimeZone('UTC')),
    );
    $rescheduled = $this->findByOriginalKey($feb1, $jan25Base->originalOccurrenceKey);
    $rescheduledRule = $resolver->resolve($series, $rescheduled);
    $this->assertSame(EffectiveResponsibility::SOURCE_RULE, $rescheduledRule->source);
    $this->assertSame((int) $personB->id(), $rescheduledRule->responsiblePersonId);

    $rescheduledOverride = $mutations->createAssignOverride(
      $series,
      $rescheduled,
      (int) $personA->id(),
    );
    $rescheduledAssigned = $resolver->resolve($series, $rescheduled);
    $this->assertSame(EffectiveResponsibility::SOURCE_OVERRIDE, $rescheduledAssigned->source);
    $this->assertSame((int) $personA->id(), $rescheduledAssigned->responsiblePersonId);
    $this->assertSame((int) $rescheduledOverride->id(), $rescheduledAssigned->overrideId);

    $mutations->createResponsibilityRule(
      $series,
      (int) $personA->id(),
      new DateTimeImmutable('2026-01-04 09:30:00', $tz),
      new DateTimeImmutable('2026-01-04 10:30:00', $tz),
      'FREQ=WEEKLY;INTERVAL=1',
    );
    $feb15 = $this->singleEffective($effective, $series, '2026-02-15 00:00:00', '2026-02-16 00:00:00');
    try {
      $resolver->resolve($series, $feb15);
      $this->fail('Overlapping matching ResponsibilityRules must fail closed.');
    }
    catch (RuntimeException $exception) {
      $this->assertStringContainsString('Multiple ResponsibilityRules', $exception->getMessage());
    }

    $this->assertFalse($entityTypeManager->hasDefinition('personal_secretary_activity_occurrence'));
    $this->assertFalse($entityTypeManager->hasDefinition('personal_sec_effective_responsibility'));
    $schema = Database::getConnection()->schema();
    $this->assertFalse($schema->tableExists('personal_secretary_activity_occurrence'));
    $this->assertFalse($schema->tableExists('personal_secretary_effective_responsibility'));

    $anonymous = new AnonymousUserSession();
    $ordinary = new UserSession(['uid' => 2, 'roles' => []]);
    $this->assertFalse($entityTypeManager->getAccessControlHandler('personal_sec_resp_rule')->createAccess(NULL, $anonymous));
    $this->assertFalse($entityTypeManager->getAccessControlHandler('personal_sec_resp_override')->createAccess(NULL, $ordinary));
  }

  public function testTechnicallyCorruptedRuleMembershipFailsClosed(): void {
    $entityTypeManager = $this->container->get('entity_type.manager');
    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\EffectiveOccurrenceProjectionService $effective */
    $effective = $this->container->get('personal_secretary.effective_occurrence_projection');
    /** @var \Drupal\personal_secretary\Service\EffectiveResponsibilityService $resolver */
    $resolver = $this->container->get('personal_secretary.effective_responsibility');

    $member = $domain->createPerson('Drew Example');
    $nonMember = $domain->createPerson('Emery Example');
    $household = $domain->createHousehold('Corruption Example Household', [(int) $member->id()]);
    $tz = new DateTimeZone('Europe/Brussels');
    $series = $domain->createActivitySeries(
      'Corruption Example Routine',
      (int) $household->id(),
      new DateTimeImmutable('2026-03-22 10:00:00', $tz),
      new DateTimeImmutable('2026-03-22 11:00:00', $tz),
      'FREQ=WEEKLY;INTERVAL=1',
    );
    $target = $this->singleEffective($effective, $series, '2026-03-22 00:00:00', '2026-03-23 00:00:00');

    /** @var \Drupal\personal_secretary\Entity\ResponsibilityRule $corrupt */
    $corrupt = $entityTypeManager->getStorage('personal_sec_resp_rule')->create([
      'series' => $series->id(),
      'responsible_person' => $nonMember->id(),
      'recurrence' => [[
        'value' => '2026-03-22T08:30:00',
        'end_value' => '2026-03-22T09:30:00',
        'rrule' => 'FREQ=WEEKLY;INTERVAL=1',
        'timezone' => 'Europe/Brussels',
      ]],
      'lifecycle_persisted_at' => 1,
    ]);
    $corrupt->save();

    try {
      $resolver->resolve($series, $target);
      $this->fail('Technically corrupted non-member ResponsibilityRule data must fail closed.');
    }
    catch (InvalidArgumentException $exception) {
      $this->assertStringContainsString('Household', $exception->getMessage());
    }
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
