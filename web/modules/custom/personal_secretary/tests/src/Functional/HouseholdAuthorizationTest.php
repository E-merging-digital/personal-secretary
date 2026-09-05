<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigImporterFactory;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\Household;
use Drupal\personal_secretary\Entity\Person;
use Drupal\personal_secretary\Entity\PreparationRequirement;
use Drupal\personal_secretary\Entity\ResponsibilityRule;
use Drupal\personal_secretary\Entity\TimeCommitmentRule;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use InvalidArgumentException;

/**
 * Proves explicit User -> Household authorization and scoped My upcoming reads.
 *
 * @group personal_secretary
 */
final class HouseholdAuthorizationTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testAuthorizationFieldAndServiceBoundaries(): void {
    $this->installHouseholdAuthorizationFieldViaEntityApi();

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\HouseholdAuthorizationService $authorization */
    $authorization = $this->container->get('personal_secretary.household_authorization');

    $person = $domain->createPerson('Synthetic authorization person');
    $h1 = $domain->createHousehold('Synthetic authorization H1', [(int) $person->id()]);
    $h2 = $domain->createHousehold('Synthetic authorization H2', [(int) $person->id()]);

    $storage = FieldStorageConfig::loadByName('user', HouseholdAuthorizationService::FIELD_NAME);
    $field = FieldConfig::loadByName('user', 'user', HouseholdAuthorizationService::FIELD_NAME);
    $this->assertInstanceOf(FieldStorageConfig::class, $storage);
    $this->assertInstanceOf(FieldConfig::class, $field);
    $this->assertSame('personal_secretary_household', $storage->getSetting('target_type'));
    $this->assertSame(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED, $storage->getCardinality());
    $this->assertFalse($storage->isTranslatable());
    $this->assertFalse($field->isRequired());
    $this->assertFalse($field->isTranslatable());

    $this->assertSame([], $authorization->authorizedHouseholdIds(new AnonymousUserSession()));

    $ordinaryWithoutPermission = $this->drupalCreateUser();
    $this->assertInstanceOf(UserInterface::class, $ordinaryWithoutPermission);
    $ordinaryWithoutPermission->set(HouseholdAuthorizationService::FIELD_NAME, [
      ['target_id' => (int) $h1->id()],
    ]);
    $ordinaryWithoutPermission->save();
    $this->assertSame([], $authorization->authorizedHouseholdIds($ordinaryWithoutPermission));

    $ordinary = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $ordinary);
    $ordinary->set(HouseholdAuthorizationService::FIELD_NAME, [
      ['target_id' => (int) $h1->id()],
    ]);
    $ordinary->save();

    $this->assertSame([(int) $h1->id()], $authorization->authorizedHouseholdIds($ordinary));
    $this->assertTrue($authorization->isAuthorized($ordinary, $h1));
    $this->assertFalse($authorization->isAuthorized($ordinary, $h2));
    $this->assertSame((int) $h1->id(), (int) $authorization->requireAuthorized($ordinary, (int) $h1->id())->id());
    try {
      $authorization->requireAuthorized($ordinary, (int) $h2->id());
      $this->fail('A Household without an explicit grant must fail closed.');
    }
    catch (InvalidArgumentException) {
      // Expected.
    }

    // Household authorization itself does not require a CurrentPerson mapping.
    $this->assertFalse($ordinary->hasField(CurrentPersonResolver::FIELD_NAME));
    $this->assertSame([(int) $h1->id()], $authorization->authorizedHouseholdIds($ordinary));

    $blocked = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $blocked);
    $blocked->set(HouseholdAuthorizationService::FIELD_NAME, [
      ['target_id' => (int) $h1->id()],
    ]);
    $blocked->block();
    $blocked->save();
    $this->assertSame([], $authorization->authorizedHouseholdIds($blocked));

    $admin = $this->drupalCreateUser([HouseholdAuthorizationService::ADMIN_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $admin);
    $this->assertSame(
      [(int) $h1->id(), (int) $h2->id()],
      $authorization->authorizedHouseholdIds($admin),
      'The explicit administrative bypass may see all current persisted Households.',
    );

    $this->drupalLogin($ordinary);
    $this->drupalGet('/user/' . $ordinary->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementNotExists(
      'css',
      '[name^="' . HouseholdAuthorizationService::FIELD_NAME . '"]',
    );
  }

  public function testAdministrativeGrantSurfaceAndGrantIndependence(): void {
    $this->installUserPersonFieldViaEntityApi();
    $this->installHouseholdAuthorizationFieldViaEntityApi();

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\ManageHouseholdAuthorizationService $manage */
    $manage = $this->container->get('personal_secretary.manage_household_authorization');
    /** @var \Drupal\personal_secretary\Service\HouseholdAuthorizationService $authorization */
    $authorization = $this->container->get('personal_secretary.household_authorization');

    $person = $domain->createPerson('Synthetic grant person');
    $otherPerson = $domain->createPerson('Synthetic grant relink person');
    $h1 = $domain->createHousehold(
      'Synthetic grant H1',
      [(int) $person->id(), (int) $otherPerson->id()],
    );
    $h2 = $domain->createHousehold(
      'Synthetic grant H2',
      [(int) $person->id(), (int) $otherPerson->id()],
    );

    $target = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $target);
    $target->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $person->id()]);
    $target->save();

    $ordinary = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $ordinary);
    $this->drupalLogin($ordinary);
    $this->drupalGet('/personal-secretary/admin/household-access');
    $this->assertSession()->statusCodeEquals(403);

    $admin = $this->drupalCreateUser([HouseholdAuthorizationService::ADMIN_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $admin);
    $this->drupalLogout();
    $this->drupalLogin($admin);

    $userStorage = $this->container->get('entity_type.manager')->getStorage('user');
    $targetId = (int) $target->id();
    $personBefore = (int) $target->get(CurrentPersonResolver::FIELD_NAME)->target_id;

    $this->drupalGet('/personal-secretary/admin/household-access');
    $this->assertSession()->statusCodeEquals(200);
    $userStorage->resetCache([$targetId]);
    $persistedTarget = $userStorage->load($targetId);
    $this->assertInstanceOf(UserInterface::class, $persistedTarget);
    $this->assertSame([], $this->grantIds($persistedTarget), 'GET must not mutate grants.');

    $this->submitForm([
      'target_user' => (string) $targetId,
      'household_ids[' . $h1->id() . ']' => (string) $h1->id(),
    ], 'Save Household access');
    $this->assertSession()->statusCodeEquals(200);

    $userStorage->resetCache([$targetId]);
    $persistedTarget = $userStorage->load($targetId);
    $this->assertInstanceOf(UserInterface::class, $persistedTarget);
    $this->assertSame([(int) $h1->id()], $this->grantIds($persistedTarget));
    $this->assertSame($personBefore, (int) $persistedTarget->get(CurrentPersonResolver::FIELD_NAME)->target_id);

    $accountSwitcher = $this->container->get('account_switcher');
    $accountSwitcher->switchTo($admin);
    try {
      $this->assertFalse(
        $manage->replaceAuthorizationSet($targetId, [(int) $h1->id()]),
        'Replacing an identical grant set must be a no-op.',
      );
      try {
        $manage->replaceAuthorizationSet($targetId, [999999]);
        $this->fail('A forged Household ID must be rejected.');
      }
      catch (InvalidArgumentException) {
        // Expected.
      }
    }
    finally {
      $accountSwitcher->switchBack();
    }

    $this->drupalGet('/personal-secretary/admin/household-access');
    $this->submitForm([
      'target_user' => (string) $targetId,
    ], 'Save Household access');
    $userStorage->resetCache([$targetId]);
    $persistedTarget = $userStorage->load($targetId);
    $this->assertInstanceOf(UserInterface::class, $persistedTarget);
    $this->assertSame([], $this->grantIds($persistedTarget));
    $this->assertSame($personBefore, (int) $persistedTarget->get(CurrentPersonResolver::FIELD_NAME)->target_id);

    // A User -> Person relink is independent from the Household grant truth.
    $accountSwitcher->switchTo($admin);
    try {
      $this->assertTrue($manage->replaceAuthorizationSet($targetId, [(int) $h2->id()]));
    }
    finally {
      $accountSwitcher->switchBack();
    }
    $userStorage->resetCache([$targetId]);
    $persistedTarget = $userStorage->load($targetId);
    $this->assertInstanceOf(UserInterface::class, $persistedTarget);
    $persistedTarget->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $otherPerson->id()]);
    $persistedTarget->save();
    $this->assertSame([(int) $h2->id()], $authorization->authorizedHouseholdIds($persistedTarget));

    // Household.members is domain relationship state, not authorization truth.
    $h2->set('members', [['target_id' => (int) $otherPerson->id()]]);
    $h2->save();
    $userStorage->resetCache([$targetId]);
    $persistedTarget = $userStorage->load($targetId);
    $this->assertInstanceOf(UserInterface::class, $persistedTarget);
    $this->assertSame([(int) $h2->id()], $this->grantIds($persistedTarget));
  }

  public function testOrdinaryMyUpcomingScopesHouseholdsBeforePersonalization(): void {
    $this->installUserPersonFieldViaEntityApi();
    $this->installHouseholdAuthorizationFieldViaEntityApi();

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\ManageHouseholdAuthorizationService $manage */
    $manage = $this->container->get('personal_secretary.manage_household_authorization');

    $person = $domain->createPerson('Synthetic shared current person');
    $otherPerson = $domain->createPerson('Synthetic alternate person');
    $h1 = $domain->createHousehold(
      'Synthetic scoped H1',
      [(int) $person->id(), (int) $otherPerson->id()],
    );
    $h2 = $domain->createHousehold(
      'Synthetic scoped H2',
      [(int) $person->id(), (int) $otherPerson->id()],
    );

    $sourceTimezone = new DateTimeZone('Europe/Brussels');
    $nowUtc = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone(new DateTimeZone('UTC'));
    $nowLocal = $nowUtc->setTimezone($sourceTimezone);

    $this->createSeriesWithRuleAndPreparation(
      'H1 authorized activity',
      'H1 authorized preparation',
      (int) $h1->id(),
      (int) $person->id(),
      $nowLocal->modify('+1 day')->setTime(9, 0),
      $nowUtc,
    );
    $this->createSeriesWithRuleAndPreparation(
      'H2 confidential activity',
      'H2 confidential preparation',
      (int) $h2->id(),
      (int) $person->id(),
      $nowLocal->modify('+2 days')->setTime(10, 0),
      $nowUtc,
    );

    $admin = $this->drupalCreateUser([HouseholdAuthorizationService::ADMIN_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $admin);
    $accountSwitcher = $this->container->get('account_switcher');

    $userA = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $userA);
    $userA->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $person->id()]);
    $userA->save();

    $userB = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $userB);
    $userB->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $person->id()]);
    $userB->save();

    $unlinked = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $unlinked);

    $accountSwitcher->switchTo($admin);
    try {
      $this->assertTrue($manage->replaceAuthorizationSet((int) $userA->id(), [(int) $h1->id()]));
      $this->assertTrue($manage->replaceAuthorizationSet((int) $unlinked->id(), [(int) $h1->id()]));
    }
    finally {
      $accountSwitcher->switchBack();
    }

    // User A has H1 only: H2 labels and preparation content never enter the page.
    $this->drupalLogin($userA);
    $this->drupalGet('/personal-secretary/upcoming/mine');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('H1 authorized activity');
    $this->assertSession()->pageTextContains('H1 authorized preparation');
    $this->assertSession()->pageTextNotContains('H2 confidential activity');
    $this->assertSession()->pageTextNotContains('H2 confidential preparation');
    $this->assertSession()->linkNotExists('Change recurring schedule');
    $this->assertSession()->linkNotExists('Change recurring responsibility');
    $this->assertSession()->linkNotExists('Change time commitment');
    $this->assertSession()->linkNotExists('Change responsibility');
    $this->assertSession()->linkNotExists('Reschedule occurrence');
    $this->assertSession()->linkNotExists('Cancel occurrence');

    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/personal-secretary/activities/add');
    $this->assertSession()->statusCodeEquals(403);

    // User B maps to the same Person but receives no grant via that identity.
    $this->drupalLogout();
    $this->drupalLogin($userB);
    $this->drupalGet('/personal-secretary/upcoming/mine');
    $this->assertSession()->statusCodeEquals(403);

    // An explicit H2 grant for B remains independent from A and exposes H2 only.
    $accountSwitcher->switchTo($admin);
    try {
      $this->assertTrue($manage->replaceAuthorizationSet((int) $userB->id(), [(int) $h2->id()]));
    }
    finally {
      $accountSwitcher->switchBack();
    }
    $this->drupalGet('/personal-secretary/upcoming/mine');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('H2 confidential activity');
    $this->assertSession()->pageTextNotContains('H1 authorized activity');

    // One User may explicitly aggregate multiple authorized Households.
    $accountSwitcher->switchTo($admin);
    try {
      $this->assertTrue($manage->replaceAuthorizationSet(
        (int) $userA->id(),
        [(int) $h1->id(), (int) $h2->id()],
      ));
    }
    finally {
      $accountSwitcher->switchBack();
    }
    $this->drupalLogout();
    $this->drupalLogin($userA);
    $this->drupalGet('/personal-secretary/upcoming/mine');
    $this->assertSession()->pageTextContains('H1 authorized activity');
    $this->assertSession()->pageTextContains('H2 confidential activity');

    // Revocation takes effect on the very next read.
    $accountSwitcher->switchTo($admin);
    try {
      $this->assertTrue($manage->replaceAuthorizationSet((int) $userA->id(), [(int) $h2->id()]));
    }
    finally {
      $accountSwitcher->switchBack();
    }
    $this->drupalGet('/personal-secretary/upcoming/mine');
    $this->assertSession()->pageTextNotContains('H1 authorized activity');
    $this->assertSession()->pageTextNotContains('H1 authorized preparation');
    $this->assertSession()->pageTextContains('H2 confidential activity');

    // A granted but unlinked User gets fail-closed remediation, never domain data.
    $this->drupalLogout();
    $this->drupalLogin($unlinked);
    $this->drupalGet('/personal-secretary/upcoming/mine');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Link your account to a Household member to see My upcoming.');
    $this->assertSession()->pageTextNotContains('H1 authorized activity');
    $this->assertSession()->pageTextNotContains('H1 authorized preparation');
    $this->assertSession()->pageTextNotContains('H2 confidential activity');
    $this->assertSession()->linkNotExists('Link my account to household member');

    // Existing privileged Household-wide Upcoming remains available and global.
    $this->drupalLogout();
    $this->drupalLogin($admin);
    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('H1 authorized activity');
    $this->assertSession()->pageTextContains('H2 confidential activity');
  }

  public function testExistingInstallConfigImportAddsAuthorizationWithoutBackfill(): void {
    $this->installUserPersonFieldViaEntityApi();

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\ResponsibilityMutationService $responsibilityMutations */
    $responsibilityMutations = $this->container->get('personal_secretary.responsibility_mutation');
    /** @var \Drupal\personal_secretary\Service\PreparationRequirementMutationService $preparationMutations */
    $preparationMutations = $this->container->get('personal_secretary.preparation_requirement_mutation');
    /** @var \Drupal\personal_secretary\Service\TimeCommitmentMutationService $timeCommitmentMutations */
    $timeCommitmentMutations = $this->container->get('personal_secretary.time_commitment_mutation');

    $person = $domain->createPerson('Existing authorization Person');
    $household = $domain->createHousehold('Existing authorization Household', [(int) $person->id()]);

    $nowUtc = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone(new DateTimeZone('UTC'));
    $start = $nowUtc->modify('+2 days');
    $series = $domain->createActivitySeries(
      'Existing authorization activity',
      (int) $household->id(),
      $start,
      $start->modify('+1 hour'),
      'FREQ=DAILY;COUNT=1',
    );
    $rule = $responsibilityMutations->createResponsibilityRule(
      $series,
      (int) $person->id(),
      $start,
      $start->modify('+1 hour'),
      'FREQ=DAILY;COUNT=1',
    );
    $requirement = $preparationMutations->createPreparationRequirement(
      $series,
      'Existing authorization preparation',
      1800,
      $nowUtc->modify('-1 day'),
    );
    $commitment = $timeCommitmentMutations->createFullOccurrenceCommitment(
      $series,
      $nowUtc->modify('+1 hour'),
    );

    $existingUser = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $existingUser);
    $existingUser->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $person->id()]);
    $existingUser->save();

    $this->assertNull(FieldStorageConfig::loadByName('user', HouseholdAuthorizationService::FIELD_NAME));
    $this->assertNull(FieldConfig::loadByName('user', 'user', HouseholdAuthorizationService::FIELD_NAME));

    $snapshot = [
      'person_count' => count($this->container->get('entity_type.manager')->getStorage('personal_secretary_person')->loadMultiple()),
      'household_count' => count($this->container->get('entity_type.manager')->getStorage('personal_secretary_household')->loadMultiple()),
      'series_count' => count($this->container->get('entity_type.manager')->getStorage('personal_sec_activity_series')->loadMultiple()),
      'rule_count' => count($this->container->get('entity_type.manager')->getStorage('personal_sec_resp_rule')->loadMultiple()),
      'preparation_count' => count($this->container->get('entity_type.manager')->getStorage('personal_sec_prep_req')->loadMultiple()),
      'commitment_count' => count($this->container->get('entity_type.manager')->getStorage(TimeCommitmentRule::ENTITY_TYPE_ID)->loadMultiple()),
      'person_uuid' => $person->uuid(),
      'series_revision' => (string) $series->getRevisionId(),
      'rule_revision' => (string) $rule->getRevisionId(),
      'requirement_revision' => (string) $requirement->getRevisionId(),
      'commitment_revision' => (string) $commitment->getRevisionId(),
    ];

    $this->importCanonicalHouseholdAuthorizationFieldConfig();

    $storage = FieldStorageConfig::loadByName('user', HouseholdAuthorizationService::FIELD_NAME);
    $field = FieldConfig::loadByName('user', 'user', HouseholdAuthorizationService::FIELD_NAME);
    $this->assertInstanceOf(FieldStorageConfig::class, $storage);
    $this->assertInstanceOf(FieldConfig::class, $field);
    $this->assertSame('personal_secretary_household', $storage->getSetting('target_type'));
    $this->assertSame(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED, $storage->getCardinality());

    $entityTypeManager = $this->container->get('entity_type.manager');
    $userStorage = $entityTypeManager->getStorage('user');
    $userStorage->resetCache([(int) $existingUser->id()]);
    $persistedUser = $userStorage->load($existingUser->id());
    $this->assertInstanceOf(UserInterface::class, $persistedUser);
    $this->assertSame((int) $person->id(), (int) $persistedUser->get(CurrentPersonResolver::FIELD_NAME)->target_id);
    $this->assertSame([], $this->grantIds($persistedUser), 'Config import must not infer or backfill any Household grant.');

    $personStorage = $entityTypeManager->getStorage('personal_secretary_person');
    $householdStorage = $entityTypeManager->getStorage('personal_secretary_household');
    $seriesStorage = $entityTypeManager->getStorage('personal_sec_activity_series');
    $ruleStorage = $entityTypeManager->getStorage('personal_sec_resp_rule');
    $preparationStorage = $entityTypeManager->getStorage('personal_sec_prep_req');
    $commitmentStorage = $entityTypeManager->getStorage(TimeCommitmentRule::ENTITY_TYPE_ID);
    foreach ([$personStorage, $householdStorage, $seriesStorage, $ruleStorage, $preparationStorage, $commitmentStorage] as $storageObject) {
      $storageObject->resetCache();
    }

    $this->assertCount($snapshot['person_count'], $personStorage->loadMultiple());
    $this->assertCount($snapshot['household_count'], $householdStorage->loadMultiple());
    $this->assertCount($snapshot['series_count'], $seriesStorage->loadMultiple());
    $this->assertCount($snapshot['rule_count'], $ruleStorage->loadMultiple());
    $this->assertCount($snapshot['preparation_count'], $preparationStorage->loadMultiple());
    $this->assertCount($snapshot['commitment_count'], $commitmentStorage->loadMultiple());

    $persistedPerson = $personStorage->load($person->id());
    $persistedSeries = $seriesStorage->load($series->id());
    $persistedRule = $ruleStorage->load($rule->id());
    $persistedRequirement = $preparationStorage->load($requirement->id());
    $persistedCommitment = $commitmentStorage->load($commitment->id());
    $this->assertInstanceOf(Person::class, $persistedPerson);
    $this->assertInstanceOf(ActivitySeries::class, $persistedSeries);
    $this->assertInstanceOf(ResponsibilityRule::class, $persistedRule);
    $this->assertInstanceOf(PreparationRequirement::class, $persistedRequirement);
    $this->assertInstanceOf(TimeCommitmentRule::class, $persistedCommitment);
    $this->assertSame($snapshot['person_uuid'], $persistedPerson->uuid());
    $this->assertSame($snapshot['series_revision'], (string) $persistedSeries->getRevisionId());
    $this->assertSame($snapshot['rule_revision'], (string) $persistedRule->getRevisionId());
    $this->assertSame($snapshot['requirement_revision'], (string) $persistedRequirement->getRevisionId());
    $this->assertSame($snapshot['commitment_revision'], (string) $persistedCommitment->getRevisionId());
  }

  private function createSeriesWithRuleAndPreparation(
    string $activityLabel,
    string $preparationLabel,
    int $householdId,
    int $responsiblePersonId,
    DateTimeImmutable $start,
    DateTimeImmutable $nowUtc,
  ): ActivitySeries {
    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\ResponsibilityMutationService $responsibilityMutations */
    $responsibilityMutations = $this->container->get('personal_secretary.responsibility_mutation');
    /** @var \Drupal\personal_secretary\Service\PreparationRequirementMutationService $preparationMutations */
    $preparationMutations = $this->container->get('personal_secretary.preparation_requirement_mutation');

    $series = $domain->createActivitySeries(
      $activityLabel,
      $householdId,
      $start,
      $start->modify('+1 hour'),
      'FREQ=DAILY;COUNT=1',
    );
    $responsibilityMutations->createResponsibilityRule(
      $series,
      $responsiblePersonId,
      $start,
      $start->modify('+1 hour'),
      'FREQ=DAILY;COUNT=1',
    );
    $preparationMutations->createPreparationRequirement(
      $series,
      $preparationLabel,
      1800,
      $nowUtc->modify('-1 day'),
    );

    return $series;
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

  private function installHouseholdAuthorizationFieldViaEntityApi(): void {
    FieldStorageConfig::create([
      'field_name' => HouseholdAuthorizationService::FIELD_NAME,
      'entity_type' => 'user',
      'type' => 'entity_reference',
      'settings' => [
        'target_type' => 'personal_secretary_household',
      ],
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
      'translatable' => FALSE,
    ])->save();

    FieldConfig::create([
      'field_name' => HouseholdAuthorizationService::FIELD_NAME,
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Personal Secretary households',
      'required' => FALSE,
      'translatable' => FALSE,
      'settings' => [
        'handler' => 'default:personal_secretary_household',
        'handler_settings' => [],
      ],
    ])->save();

    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  private function importCanonicalHouseholdAuthorizationFieldConfig(): void {
    $activeStorage = $this->container->get('config.storage');
    $sourceStorage = new MemoryStorage();

    foreach ($activeStorage->listAll() as $name) {
      $data = $activeStorage->read($name);
      if (is_array($data)) {
        $sourceStorage->write($name, $data);
      }
    }

    $fieldName = HouseholdAuthorizationService::FIELD_NAME;
    foreach ([
      'field.storage.user.' . $fieldName,
      'field.field.user.user.' . $fieldName,
    ] as $name) {
      $path = dirname(DRUPAL_ROOT) . '/config/sync/' . $name . '.yml';
      $contents = file_get_contents($path);
      $this->assertNotFalse($contents, 'Candidate Household authorization config must be readable.');
      $data = Yaml::decode((string) $contents);
      $this->assertIsArray($data);
      $sourceStorage->write($name, $data);
    }

    $storageComparer = new StorageComparer($sourceStorage, $activeStorage);
    $importer = $this->container
      ->get(ConfigImporterFactory::class)
      ->get($storageComparer->createChangelist());
    $importer->import();

    $this->assertSame([], $importer->getErrors());
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * @return int[]
   */
  private function grantIds(UserInterface $user): array {
    $ids = [];
    foreach ($user->get(HouseholdAuthorizationService::FIELD_NAME) as $item) {
      $id = (int) ($item->target_id ?? 0);
      if ($id > 0) {
        $ids[] = $id;
      }
    }
    sort($ids, SORT_NUMERIC);
    return $ids;
  }

}
