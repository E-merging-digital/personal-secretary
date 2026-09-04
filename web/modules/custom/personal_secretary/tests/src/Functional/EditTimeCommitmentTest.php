<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\personal_secretary\Entity\ActivityException;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\PreparationRequirement;
use Drupal\personal_secretary\Entity\ResponsibilityOverride;
use Drupal\personal_secretary\Entity\ResponsibilityRule;
use Drupal\personal_secretary\Entity\TimeCommitmentRule;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\EditTimeCommitmentService;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;

/**
 * Proves the governed future series-level time-commitment surface.
 *
 * @group personal_secretary
 */
final class EditTimeCommitmentTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testFutureTimeCommitmentLifecyclePreservesExistingDomain(): void {
    $this->installUserPersonFieldViaEntityApi();

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\ResponsibilityMutationService $responsibility */
    $responsibility = $this->container->get('personal_secretary.responsibility_mutation');
    /** @var \Drupal\personal_secretary\Service\PreparationRequirementMutationService $preparation */
    $preparation = $this->container->get('personal_secretary.preparation_requirement_mutation');
    /** @var \Drupal\personal_secretary\Service\OccurrenceProjectionService $baseProjection */
    $baseProjection = $this->container->get('personal_secretary.occurrence_projection');
    /** @var \Drupal\personal_secretary\Service\EffectiveOccurrenceProjectionService $effectiveProjection */
    $effectiveProjection = $this->container->get('personal_secretary.effective_occurrence_projection');
    /** @var \Drupal\personal_secretary\Service\ActivityExceptionService $exceptions */
    $exceptions = $this->container->get('personal_secretary.activity_exception');

    $entityTypeManager = $this->container->get('entity_type.manager');
    $timezone = new DateTimeZone('Europe/Brussels');
    $nowLocal = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone($timezone);
    $firstStart = $nowLocal->modify('+1 day')->setTime(9, 0);
    $firstEnd = $firstStart->modify('+1 hour');
    $secondStart = $firstStart->modify('+1 day');
    $thirdStart = $firstStart->modify('+2 days');

    $personA = $domain->createPerson('Time Commitment Current Person');
    $personB = $domain->createPerson('Time Commitment Other Person');
    $household = $domain->createHousehold(
      'Time Commitment Household',
      [(int) $personA->id(), (int) $personB->id()],
    );
    $series = $domain->createActivitySeries(
      'Time Commitment Activity',
      (int) $household->id(),
      $firstStart,
      $firstEnd,
      'FREQ=DAILY;COUNT=5',
    );
    $responsibilityRule = $responsibility->createResponsibilityRule(
      $series,
      (int) $personA->id(),
      $firstStart,
      $firstEnd,
      'FREQ=DAILY;COUNT=5',
    );
    $preparationRequirement = $preparation->createPreparationRequirement(
      $series,
      'Prepare time commitment fixture',
      1800,
      $firstStart->modify('-1 day'),
    );

    $thirdUtc = $thirdStart->setTimezone(new DateTimeZone('UTC'));
    $thirdBase = $baseProjection->project(
      $series,
      $thirdUtc->modify('-1 hour'),
      $thirdUtc->modify('+2 hours'),
    )[0];
    $rescheduledStart = (new DateTimeImmutable($thirdBase->utcStart))->modify('+2 hours');
    $rescheduledEnd = (new DateTimeImmutable($thirdBase->utcEnd))->modify('+2 hours');
    $activityException = $exceptions->createReschedule(
      $series,
      $thirdBase,
      $rescheduledStart,
      $rescheduledEnd,
      $thirdBase->sourceTimezone,
    );
    $rescheduledOccurrence = $effectiveProjection->project(
      $series,
      $rescheduledStart->modify('-1 second'),
      $rescheduledEnd->modify('+1 second'),
    )[0];
    $responsibilityOverride = $responsibility->createAssignOverride(
      $series,
      $rescheduledOccurrence,
      (int) $personB->id(),
    );

    $authorized = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->assertInstanceOf(UserInterface::class, $authorized);
    $authorized->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => $personA->id()]);
    $authorized->save();

    $seriesId = (int) $series->id();
    $editUrl = Url::fromRoute(
      'personal_secretary.edit_time_commitment',
      ['series' => $seriesId],
    )->toString();

    $commitmentStorage = $entityTypeManager->getStorage(TimeCommitmentRule::ENTITY_TYPE_ID);

    $baseline = [
      'series_revision' => (string) $series->getRevisionId(),
      'series_recurrence' => $series->get('recurrence')->first()->getValue(),
      'series_effective_from' => (string) $series->get('effective_from')->value,
      'exception_revision' => (string) $activityException->getRevisionId(),
      'exception_status' => (string) $activityException->get('status')->value,
      'exception_key' => (string) $activityException->get('original_occurrence_key')->value,
      'responsibility_rule_revision' => (string) $responsibilityRule->getRevisionId(),
      'responsibility_override_revision' => (string) $responsibilityOverride->getRevisionId(),
      'responsibility_override_action' => (string) $responsibilityOverride->get('action')->value,
      'preparation_revision' => (string) $preparationRequirement->getRevisionId(),
      'user_person_target' => (string) $authorized->get(CurrentPersonResolver::FIELD_NAME)->target_id,
      'counts' => $this->allDomainCounts(),
    ];

    $this->drupalGet($editUrl);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($authorized);

    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Change time commitment');
    $this->assertSession()->linkByHrefExists($editUrl);

    $this->drupalGet('/personal-secretary/upcoming/mine');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Change time commitment');
    $this->assertSession()->linkByHrefExists($editUrl);

    $this->drupalGet($editUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Time Commitment Activity');
    $this->assertSession()->pageTextContains('Europe/Brussels');
    $this->assertSession()->pageTextContains('Time commitment at the next affected occurrence');
    $this->assertSession()->fieldExists('effective_from_date');
    $this->assertSession()->fieldExists('mode');
    $this->assertSession()->fieldNotExists('series');
    $this->assertSession()->fieldNotExists('person');
    $this->assertSession()->fieldNotExists('source_timezone');
    $this->assertCount(0, $commitmentStorage->loadMultiple());
    $this->assertPreservedBaseline($baseline, $seriesId, (int) $activityException->id(), (int) $responsibilityRule->id(), (int) $responsibilityOverride->id(), (int) $preparationRequirement->id(), (int) $authorized->id());

    // NONE -> FULL: the transition is the first EffectiveOccurrence on/after
    // the requested local date, not local midnight and not a series revision.
    $this->submitForm([
      'effective_from_date' => $firstStart->format('Y-m-d'),
      'mode' => EditTimeCommitmentService::MODE_FULL_OCCURRENCE,
    ], 'Save time commitment');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $rules = array_values($commitmentStorage->loadMultiple());
    $this->assertCount(1, $rules);
    $commitment = $rules[0];
    $this->assertInstanceOf(TimeCommitmentRule::class, $commitment);
    $this->assertSame(TimeCommitmentRule::MODE_FULL_OCCURRENCE, (string) $commitment->get('mode')->value);
    $this->assertSame(
      $firstStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s'),
      (string) $commitment->get('effective_from')->value,
    );
    $this->assertTrue($commitment->get('effective_until')->isEmpty());
    $createdRevision = (string) $commitment->getRevisionId();
    $this->assertPreservedBaseline($baseline, $seriesId, (int) $activityException->id(), (int) $responsibilityRule->id(), (int) $responsibilityOverride->id(), (int) $preparationRequirement->id(), (int) $authorized->id(), 1);

    // FULL -> FULL at the same effective occurrence is a true no-op.
    $this->drupalGet($editUrl);
    $this->submitForm([
      'effective_from_date' => $firstStart->format('Y-m-d'),
      'mode' => EditTimeCommitmentService::MODE_FULL_OCCURRENCE,
    ], 'Save time commitment');
    $commitmentAfterNoop = $commitmentStorage->load($commitment->id());
    $this->assertInstanceOf(TimeCommitmentRule::class, $commitmentAfterNoop);
    $this->assertSame($createdRevision, (string) $commitmentAfterNoop->getRevisionId());
    $this->assertCount(1, $commitmentStorage->loadMultiple());

    // FULL -> NONE retires the existing rule at the second real occurrence;
    // NONE is represented by absence of a matching interval, never a new row.
    $this->drupalGet($editUrl);
    $this->submitForm([
      'effective_from_date' => $secondStart->format('Y-m-d'),
      'mode' => EditTimeCommitmentService::MODE_NONE,
    ], 'Save time commitment');
    $retired = $commitmentStorage->load($commitment->id());
    $this->assertInstanceOf(TimeCommitmentRule::class, $retired);
    $this->assertNotSame($createdRevision, (string) $retired->getRevisionId());
    $this->assertSame(
      $secondStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s'),
      (string) $retired->get('effective_until')->value,
    );
    $this->assertCount(1, $commitmentStorage->loadMultiple());

    $this->assertPreservedBaseline($baseline, $seriesId, (int) $activityException->id(), (int) $responsibilityRule->id(), (int) $responsibilityOverride->id(), (int) $preparationRequirement->id(), (int) $authorized->id(), 1);
  }

  /**
   * @param array<string, mixed> $baseline
   */
  private function assertPreservedBaseline(
    array $baseline,
    int $seriesId,
    int $exceptionId,
    int $responsibilityRuleId,
    int $responsibilityOverrideId,
    int $preparationId,
    int $userId,
    int $expectedCommitmentCount = 0,
  ): void {
    $manager = $this->container->get('entity_type.manager');
    $series = $manager->getStorage('personal_sec_activity_series')->load($seriesId);
    $exception = $manager->getStorage('personal_sec_activity_exception')->load($exceptionId);
    $rule = $manager->getStorage('personal_sec_resp_rule')->load($responsibilityRuleId);
    $override = $manager->getStorage('personal_sec_resp_override')->load($responsibilityOverrideId);
    $preparation = $manager->getStorage('personal_sec_prep_req')->load($preparationId);
    $user = $manager->getStorage('user')->load($userId);

    $this->assertInstanceOf(ActivitySeries::class, $series);
    $this->assertInstanceOf(ActivityException::class, $exception);
    $this->assertInstanceOf(ResponsibilityRule::class, $rule);
    $this->assertInstanceOf(ResponsibilityOverride::class, $override);
    $this->assertInstanceOf(PreparationRequirement::class, $preparation);
    $this->assertInstanceOf(UserInterface::class, $user);

    $this->assertSame($baseline['series_revision'], (string) $series->getRevisionId());
    $this->assertSame($baseline['series_recurrence'], $series->get('recurrence')->first()->getValue());
    $this->assertSame($baseline['series_effective_from'], (string) $series->get('effective_from')->value);
    $this->assertSame($baseline['exception_revision'], (string) $exception->getRevisionId());
    $this->assertSame($baseline['exception_status'], (string) $exception->get('status')->value);
    $this->assertSame($baseline['exception_key'], (string) $exception->get('original_occurrence_key')->value);
    $this->assertSame($baseline['responsibility_rule_revision'], (string) $rule->getRevisionId());
    $this->assertSame($baseline['responsibility_override_revision'], (string) $override->getRevisionId());
    $this->assertSame($baseline['responsibility_override_action'], (string) $override->get('action')->value);
    $this->assertSame($baseline['preparation_revision'], (string) $preparation->getRevisionId());
    $this->assertSame($baseline['user_person_target'], (string) $user->get(CurrentPersonResolver::FIELD_NAME)->target_id);

    $counts = $this->allDomainCounts();
    $expected = $baseline['counts'];
    $expected['time_commitments'] = $expectedCommitmentCount;
    $this->assertSame($expected, $counts);
  }

  /**
   * @return array<string, int>
   */
  private function allDomainCounts(): array {
    $manager = $this->container->get('entity_type.manager');
    return [
      'person' => count($manager->getStorage('personal_secretary_person')->loadMultiple()),
      'household' => count($manager->getStorage('personal_secretary_household')->loadMultiple()),
      'series' => count($manager->getStorage('personal_sec_activity_series')->loadMultiple()),
      'exceptions' => count($manager->getStorage('personal_sec_activity_exception')->loadMultiple()),
      'responsibility_rules' => count($manager->getStorage('personal_sec_resp_rule')->loadMultiple()),
      'responsibility_overrides' => count($manager->getStorage('personal_sec_resp_override')->loadMultiple()),
      'preparations' => count($manager->getStorage('personal_sec_prep_req')->loadMultiple()),
      'time_commitments' => count($manager->getStorage(TimeCommitmentRule::ENTITY_TYPE_ID)->loadMultiple()),
    ];
  }

  private function installUserPersonFieldViaEntityApi(): void {
    FieldStorageConfig::create([
      'field_name' => CurrentPersonResolver::FIELD_NAME,
      'entity_type' => 'user',
      'type' => 'entity_reference',
      'settings' => [
        'target_type' => 'personal_secretary_person',
      ],
      'cardinality' => 1,
      'translatable' => FALSE,
    ])->save();

    FieldConfig::create([
      'field_name' => CurrentPersonResolver::FIELD_NAME,
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Personal Secretary person',
      'required' => FALSE,
      'translatable' => FALSE,
      'settings' => [
        'handler' => 'default:personal_secretary_person',
        'handler_settings' => [],
      ],
    ])->save();

    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

}
