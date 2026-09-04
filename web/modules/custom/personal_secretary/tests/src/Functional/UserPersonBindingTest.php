<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigImporterFactory;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\Household;
use Drupal\personal_secretary\Entity\Person;
use Drupal\personal_secretary\Entity\PreparationRequirement;
use Drupal\personal_secretary\Entity\ResponsibilityOverride;
use Drupal\personal_secretary\Entity\ResponsibilityRule;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Value\EffectiveOccurrence;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Proves the governed Drupal User -> Person identity bridge.
 *
 * @group personal_secretary
 */
final class UserPersonBindingTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testCurrentUserBindingAndResolverContract(): void {
    $this->installUserPersonFieldViaEntityApi();

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\ResponsibilityMutationService $responsibilityMutations */
    $responsibilityMutations = $this->container->get('personal_secretary.responsibility_mutation');
    /** @var \Drupal\personal_secretary\Service\PreparationRequirementMutationService $preparationMutations */
    $preparationMutations = $this->container->get('personal_secretary.preparation_requirement_mutation');
    /** @var \Drupal\personal_secretary\Service\OccurrenceProjectionService $baseProjection */
    $baseProjection = $this->container->get('personal_secretary.occurrence_projection');
    /** @var \Drupal\personal_secretary\Service\EffectiveOccurrenceProjectionService $effectiveProjection */
    $effectiveProjection = $this->container->get('personal_secretary.effective_occurrence_projection');
    /** @var \Drupal\personal_secretary\Service\EffectiveResponsibilityService $effectiveResponsibility */
    $effectiveResponsibility = $this->container->get('personal_secretary.effective_responsibility');
    /** @var \Drupal\personal_secretary\Service\CurrentPersonResolver $currentPerson */
    $currentPerson = $this->container->get('personal_secretary.current_person');
    /** @var \Drupal\personal_secretary\Service\LinkCurrentUserToPersonService $linker */
    $linker = $this->container->get('personal_secretary.link_current_user_to_person');
    $entityTypeManager = $this->container->get('entity_type.manager');

    $utc = new DateTimeZone('UTC');
    $sourceTimezone = new DateTimeZone('Europe/Brussels');
    $nowUtc = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone($utc);
    $seriesStart = $nowUtc
      ->setTimezone($sourceTimezone)
      ->modify('+2 days')
      ->setTime(10, 0);
    $seriesEnd = $seriesStart->modify('+1 hour');

    $targetPerson = $domain->createPerson('Avery Current Person');
    $otherMember = $domain->createPerson('Blair Other Member');
    $orphanPerson = $domain->createPerson('Casey Orphan Person');
    $primaryHousehold = $domain->createHousehold(
      'Synthetic identity household',
      [(int) $targetPerson->id(), (int) $otherMember->id()],
    );
    $secondaryHousehold = $domain->createHousehold(
      'Synthetic duplicate-membership household',
      [(int) $targetPerson->id()],
    );

    $series = $domain->createActivitySeries(
      'Synthetic identity comparison activity',
      (int) $primaryHousehold->id(),
      $seriesStart,
      $seriesEnd,
      'FREQ=WEEKLY;INTERVAL=1',
    );
    $rule = $responsibilityMutations->createResponsibilityRule(
      $series,
      (int) $targetPerson->id(),
      $seriesStart,
      $seriesEnd,
      'FREQ=WEEKLY;INTERVAL=1',
    );
    $requirement = $preparationMutations->createPreparationRequirement(
      $series,
      'Prepare synthetic identity kit',
      1800,
      $seriesStart,
    );

    $windowEnd = $nowUtc->modify('+14 days');
    $baseOccurrences = $baseProjection->project($series, $nowUtc, $windowEnd);
    $this->assertGreaterThanOrEqual(2, count($baseOccurrences));
    $effectiveOccurrences = $this->effectiveByOriginalKey(
      $effectiveProjection->project($series, $nowUtc, $windowEnd),
    );
    $firstBase = $baseOccurrences[0];
    $secondBase = $baseOccurrences[1];
    $this->assertArrayHasKey($firstBase->originalOccurrenceKey, $effectiveOccurrences);
    $this->assertArrayHasKey($secondBase->originalOccurrenceKey, $effectiveOccurrences);
    $override = $responsibilityMutations->createAssignOverride(
      $series,
      $effectiveOccurrences[$secondBase->originalOccurrenceKey],
      (int) $otherMember->id(),
    );

    $targetId = (int) $targetPerson->id();
    $targetUuid = $targetPerson->uuid();

    $this->expectResolverFailure(
      fn() => $currentPerson->resolve($this->container->get('current_user')),
      'Anonymous account must fail closed.',
    );

    $linkUrl = Url::fromRoute('personal_secretary.link_current_user_to_person')->toString();
    $this->drupalGet($linkUrl);
    $this->assertSession()->statusCodeEquals(403);

    $authorized = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->assertInstanceOf(UserInterface::class, $authorized);
    $this->drupalLogin($authorized);

    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Link my account to household member');
    $this->assertSession()->linkByHrefExists($linkUrl);

    $userEditUrl = '/user/' . $authorized->id() . '/edit';
    $this->drupalGet($userEditUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementNotExists(
      'css',
      '[name^="' . CurrentPersonResolver::FIELD_NAME . '"]',
    );

    $userStorage = $entityTypeManager->getStorage('user');
    $userStorage->resetCache([(int) $authorized->id()]);
    $persistedAuthorized = $userStorage->load($authorized->id());
    $this->assertInstanceOf(UserInterface::class, $persistedAuthorized);
    $this->assertTrue($persistedAuthorized->get(CurrentPersonResolver::FIELD_NAME)->isEmpty());

    $this->drupalGet($linkUrl);
    $this->assertSession()->statusCodeEquals(200);
    $personSelect = $this->assertSession()->fieldExists('person_id');
    $this->assertSession()->buttonExists('Link my account to household member');
    $this->assertCount(1, $personSelect->findAll('css', 'option[value="' . $targetId . '"]'));
    $this->assertCount(1, $personSelect->findAll('css', 'option[value="' . $otherMember->id() . '"]'));
    $this->assertCount(0, $personSelect->findAll('css', 'option[value="' . $orphanPerson->id() . '"]'));

    $userStorage->resetCache([(int) $authorized->id()]);
    $persistedAuthorized = $userStorage->load($authorized->id());
    $this->assertInstanceOf(UserInterface::class, $persistedAuthorized);
    $this->assertTrue($persistedAuthorized->get(CurrentPersonResolver::FIELD_NAME)->isEmpty());

    $accountSwitcher = $this->container->get('account_switcher');
    $accountSwitcher->switchTo($authorized);
    try {
      try {
        $linker->prepare((int) $orphanPerson->id());
        $this->fail('An orphan Person must not be linkable.');
      }
      catch (InvalidArgumentException) {
        // Expected: only current persisted Household members are linkable.
      }
      try {
        $linker->prepare(999999);
        $this->fail('A forged Person ID must not be linkable.');
      }
      catch (InvalidArgumentException) {
        // Expected: forged IDs are absent from the re-resolved member directory.
      }
    }
    finally {
      $accountSwitcher->switchBack();
    }

    $this->drupalGet($linkUrl);
    $this->submitForm([
      'person_id' => (string) $targetId,
    ], 'Link my account to household member');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);

    $userStorage->resetCache([(int) $authorized->id()]);
    $persistedAuthorized = $userStorage->load($authorized->id());
    $this->assertInstanceOf(UserInterface::class, $persistedAuthorized);
    $this->assertSame(
      $targetId,
      (int) $persistedAuthorized->get(CurrentPersonResolver::FIELD_NAME)->target_id,
    );

    $resolvedTarget = $currentPerson->resolve($authorized);
    $this->assertSame($targetId, (int) $resolvedTarget->id());
    $this->assertSame($targetUuid, $resolvedTarget->uuid());

    $responsibility = $effectiveResponsibility->resolve(
      $series,
      $effectiveOccurrences[$firstBase->originalOccurrenceKey],
    );
    $this->assertSame($targetId, $responsibility->responsiblePersonId);
    $this->assertSame((int) $resolvedTarget->id(), $responsibility->responsiblePersonId);

    $accountSwitcher->switchTo($authorized);
    try {
      $this->assertFalse(
        $linker->link($targetId),
        'Linking the same Person must be a no-op with no User save.',
      );
    }
    finally {
      $accountSwitcher->switchBack();
    }

    $this->drupalGet($linkUrl);
    $this->submitForm([
      'person_id' => (string) $otherMember->id(),
    ], 'Link my account to household member');
    $this->assertSame(
      (int) $otherMember->id(),
      (int) $currentPerson->resolve($authorized)->id(),
    );

    $this->drupalGet($linkUrl);
    $this->submitForm([
      'person_id' => (string) $targetId,
    ], 'Link my account to household member');
    $this->assertSame($targetId, (int) $currentPerson->resolve($authorized)->id());

    $secondUser = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->assertInstanceOf(UserInterface::class, $secondUser);
    $accountSwitcher->switchTo($secondUser);
    try {
      $this->assertTrue($linker->link($targetId));
    }
    finally {
      $accountSwitcher->switchBack();
    }
    $this->assertSame($targetId, (int) $currentPerson->resolve($secondUser)->id());
    $this->assertSame($targetId, (int) $currentPerson->resolve($authorized)->id());

    $unlinkedUser = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->assertInstanceOf(UserInterface::class, $unlinkedUser);
    $this->expectResolverFailure(
      fn() => $currentPerson->resolve($unlinkedUser),
      'Unlinked authenticated User must fail closed.',
    );

    $blockedUser = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->assertInstanceOf(UserInterface::class, $blockedUser);
    $blockedUser->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => $targetId]);
    $blockedUser->block();
    $blockedUser->save();
    $this->expectResolverFailure(
      fn() => $currentPerson->resolve($blockedUser),
      'Blocked User must fail closed.',
    );

    $staleUser = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->assertInstanceOf(UserInterface::class, $staleUser);
    $staleUser->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => 999999]);
    $staleUser->save();
    $this->expectResolverFailure(
      fn() => $currentPerson->resolve($staleUser),
      'Stale Person reference must fail closed.',
    );

    $personStorage = $entityTypeManager->getStorage('personal_secretary_person');
    $householdStorage = $entityTypeManager->getStorage('personal_secretary_household');
    $seriesStorage = $entityTypeManager->getStorage('personal_sec_activity_series');
    $ruleStorage = $entityTypeManager->getStorage('personal_sec_resp_rule');
    $overrideStorage = $entityTypeManager->getStorage('personal_sec_resp_override');
    $preparationStorage = $entityTypeManager->getStorage('personal_sec_prep_req');

    $personCount = count($personStorage->loadMultiple());
    $householdCount = count($householdStorage->loadMultiple());
    $seriesCount = count($seriesStorage->loadMultiple());
    $ruleCount = count($ruleStorage->loadMultiple());
    $overrideCount = count($overrideStorage->loadMultiple());
    $preparationCount = count($preparationStorage->loadMultiple());
    $seriesRevision = (string) $series->getRevisionId();
    $ruleRevision = (string) $rule->getRevisionId();
    $overrideRevision = (string) $override->getRevisionId();
    $requirementRevision = (string) $requirement->getRevisionId();

    $userStorage->resetCache([(int) $secondUser->id()]);
    $deletableUser = $userStorage->load($secondUser->id());
    $this->assertInstanceOf(UserInterface::class, $deletableUser);
    $deletableUser->delete();

    $personStorage->resetCache();
    $householdStorage->resetCache();
    $seriesStorage->resetCache();
    $ruleStorage->resetCache();
    $overrideStorage->resetCache();
    $preparationStorage->resetCache();

    $this->assertCount($personCount, $personStorage->loadMultiple());
    $this->assertCount($householdCount, $householdStorage->loadMultiple());
    $this->assertCount($seriesCount, $seriesStorage->loadMultiple());
    $this->assertCount($ruleCount, $ruleStorage->loadMultiple());
    $this->assertCount($overrideCount, $overrideStorage->loadMultiple());
    $this->assertCount($preparationCount, $preparationStorage->loadMultiple());

    $persistedPerson = $personStorage->load($targetId);
    $this->assertInstanceOf(Person::class, $persistedPerson);
    $this->assertSame($targetUuid, $persistedPerson->uuid());

    $persistedPrimaryHousehold = $householdStorage->load($primaryHousehold->id());
    $persistedSecondaryHousehold = $householdStorage->load($secondaryHousehold->id());
    $this->assertInstanceOf(Household::class, $persistedPrimaryHousehold);
    $this->assertInstanceOf(Household::class, $persistedSecondaryHousehold);

    $persistedSeries = $seriesStorage->load($series->id());
    $persistedRule = $ruleStorage->load($rule->id());
    $persistedOverride = $overrideStorage->load($override->id());
    $persistedRequirement = $preparationStorage->load($requirement->id());
    $this->assertInstanceOf(ActivitySeries::class, $persistedSeries);
    $this->assertInstanceOf(ResponsibilityRule::class, $persistedRule);
    $this->assertInstanceOf(ResponsibilityOverride::class, $persistedOverride);
    $this->assertInstanceOf(PreparationRequirement::class, $persistedRequirement);
    $this->assertSame($seriesRevision, (string) $persistedSeries->getRevisionId());
    $this->assertSame($ruleRevision, (string) $persistedRule->getRevisionId());
    $this->assertSame($overrideRevision, (string) $persistedOverride->getRevisionId());
    $this->assertSame($requirementRevision, (string) $persistedRequirement->getRevisionId());
  }

  public function testExistingInstallConfigImportCreatesBindingField(): void {
    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');

    $targetPerson = $domain->createPerson('Existing Install Person');
    $household = $domain->createHousehold(
      'Existing install household',
      [(int) $targetPerson->id()],
    );

    $personStorage = $this->container->get('entity_type.manager')
      ->getStorage('personal_secretary_person');
    $householdStorage = $this->container->get('entity_type.manager')
      ->getStorage('personal_secretary_household');
    $personCountBefore = count($personStorage->loadMultiple());
    $householdCountBefore = count($householdStorage->loadMultiple());
    $targetUuid = $targetPerson->uuid();

    $this->assertNull(
      FieldStorageConfig::loadByName('user', CurrentPersonResolver::FIELD_NAME),
      'Baseline site must not already contain the candidate field storage.',
    );
    $this->assertNull(
      FieldConfig::loadByName('user', 'user', CurrentPersonResolver::FIELD_NAME),
      'Baseline site must not already contain the candidate field config.',
    );

    $this->importCanonicalUserPersonFieldConfig();

    $this->assertInstanceOf(
      FieldStorageConfig::class,
      FieldStorageConfig::loadByName('user', CurrentPersonResolver::FIELD_NAME),
    );
    $this->assertInstanceOf(
      FieldConfig::class,
      FieldConfig::loadByName('user', 'user', CurrentPersonResolver::FIELD_NAME),
    );

    $personStorage->resetCache();
    $householdStorage->resetCache();
    $this->assertCount($personCountBefore, $personStorage->loadMultiple());
    $this->assertCount($householdCountBefore, $householdStorage->loadMultiple());

    $persistedPerson = $personStorage->load($targetPerson->id());
    $persistedHousehold = $householdStorage->load($household->id());
    $this->assertInstanceOf(Person::class, $persistedPerson);
    $this->assertInstanceOf(Household::class, $persistedHousehold);
    $this->assertSame($targetUuid, $persistedPerson->uuid());
    $this->assertSame(
      [(int) $targetPerson->id()],
      array_map(
        static fn(array $item): int => (int) $item['target_id'],
        $persistedHousehold->get('members')->getValue(),
      ),
    );

    $existingUser = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->assertInstanceOf(UserInterface::class, $existingUser);

    /** @var \Drupal\personal_secretary\Service\LinkCurrentUserToPersonService $linker */
    $linker = $this->container->get('personal_secretary.link_current_user_to_person');
    /** @var \Drupal\personal_secretary\Service\CurrentPersonResolver $currentPerson */
    $currentPerson = $this->container->get('personal_secretary.current_person');
    $accountSwitcher = $this->container->get('account_switcher');

    $accountSwitcher->switchTo($existingUser);
    try {
      $this->assertTrue($linker->link((int) $targetPerson->id()));
    }
    finally {
      $accountSwitcher->switchBack();
    }

    $resolved = $currentPerson->resolve($existingUser);
    $this->assertSame((int) $targetPerson->id(), (int) $resolved->id());
    $this->assertSame($targetUuid, $resolved->uuid());
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

  private function importCanonicalUserPersonFieldConfig(): void {
    $activeStorage = $this->container->get('config.storage');
    $sourceStorage = new MemoryStorage();

    foreach ($activeStorage->listAll() as $name) {
      $data = $activeStorage->read($name);
      if (is_array($data)) {
        $sourceStorage->write($name, $data);
      }
    }

    foreach ([
      'field.storage.user.field_personal_secretary_person',
      'field.field.user.user.field_personal_secretary_person',
    ] as $name) {
      $path = dirname(DRUPAL_ROOT) . '/config/sync/' . $name . '.yml';
      $contents = file_get_contents($path);
      $this->assertNotFalse($contents, 'Candidate canonical field config must be readable.');
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
   * @param \Drupal\personal_secretary\Value\EffectiveOccurrence[] $occurrences
   *
   * @return array<string, \Drupal\personal_secretary\Value\EffectiveOccurrence>
   */
  private function effectiveByOriginalKey(array $occurrences): array {
    $indexed = [];
    foreach ($occurrences as $occurrence) {
      $this->assertInstanceOf(EffectiveOccurrence::class, $occurrence);
      $indexed[$occurrence->originalOccurrenceKey] = $occurrence;
    }
    return $indexed;
  }

  private function expectResolverFailure(callable $callback, string $message): void {
    try {
      $callback();
      $this->fail($message);
    }
    catch (InvalidArgumentException | RuntimeException) {
      // Expected fail-closed identity resolution.
    }
  }

}
