<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\Household;
use Drupal\personal_secretary\Entity\Person;
use Drupal\personal_secretary\Entity\ResponsibilityOverride;
use Drupal\personal_secretary\Entity\ResponsibilityRule;
use Drupal\personal_secretary\Value\EffectiveResponsibility;
use InvalidArgumentException;

/**
 * Proves one Household-member Person label can change without identity drift.
 *
 * @group personal_secretary
 */
final class RenameHouseholdMemberTest extends BrowserTestBase {

  protected static $modules = ['block', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testRenameHouseholdMemberPreservesIdentityAndReferences(): void {
    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\RenameHouseholdMemberService $renamer */
    $renamer = $this->container->get('personal_secretary.rename_household_member');
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
    /** @var \Drupal\personal_secretary\Service\PreparationEligibilityService $preparationEligibility */
    $preparationEligibility = $this->container->get('personal_secretary.preparation_eligibility');
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

    $targetPerson = $domain->createPerson('Avery Original Name');
    $otherMember = $domain->createPerson('Blair Household Member');
    $orphanPerson = $domain->createPerson('Casey Orphan Person');
    $primaryHousehold = $domain->createHousehold(
      'Synthetic primary household',
      [(int) $targetPerson->id(), (int) $otherMember->id()],
    );
    $secondaryHousehold = $domain->createHousehold(
      'Synthetic secondary household',
      [(int) $targetPerson->id()],
    );

    $series = $domain->createActivitySeries(
      'Synthetic rename propagation activity',
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
      'Prepare synthetic rename kit',
      3600,
      $seriesStart,
    );

    $windowEnd = $nowUtc->modify('+14 days');
    $baseOccurrences = $baseProjection->project($series, $nowUtc, $windowEnd);
    $this->assertGreaterThanOrEqual(2, count($baseOccurrences));
    $firstBase = $baseOccurrences[0];
    $secondBase = $baseOccurrences[1];
    $effectiveBefore = $this->effectiveByOriginalKey(
      $effectiveProjection->project($series, $nowUtc, $windowEnd),
    );
    $this->assertArrayHasKey($firstBase->originalOccurrenceKey, $effectiveBefore);
    $this->assertArrayHasKey($secondBase->originalOccurrenceKey, $effectiveBefore);
    $override = $responsibilityMutations->createAssignOverride(
      $series,
      $effectiveBefore[$secondBase->originalOccurrenceKey],
      (int) $otherMember->id(),
    );

    $targetId = (int) $targetPerson->id();
    $targetUuid = $targetPerson->uuid();
    $seriesId = (int) $series->id();
    $ruleId = (int) $rule->id();
    $overrideId = (int) $override->id();
    $requirementId = (int) $requirement->id();

    $personStorage = $entityTypeManager->getStorage('personal_secretary_person');
    $householdStorage = $entityTypeManager->getStorage('personal_secretary_household');
    $seriesStorage = $entityTypeManager->getStorage('personal_sec_activity_series');
    $ruleStorage = $entityTypeManager->getStorage('personal_sec_resp_rule');
    $overrideStorage = $entityTypeManager->getStorage('personal_sec_resp_override');
    $preparationStorage = $entityTypeManager->getStorage('personal_sec_prep_req');

    $personCountBefore = count($personStorage->loadMultiple());
    $householdCountBefore = count($householdStorage->loadMultiple());
    $householdStateBefore = $this->householdState($householdStorage);
    $seriesCountBefore = count($seriesStorage->loadMultiple());
    $seriesRevisionBefore = (string) $series->getRevisionId();
    $seriesRecurrenceBefore = $series->get('recurrence')->first()?->getValue();
    $this->assertIsArray($seriesRecurrenceBefore);
    $ruleCountBefore = count($ruleStorage->loadMultiple());
    $ruleRevisionBefore = (string) $rule->getRevisionId();
    $ruleTargetBefore = (int) $rule->get('responsible_person')->target_id;
    $ruleEffectiveUntilBefore = $rule->get('effective_until')->getValue();
    $overrideCountBefore = count($overrideStorage->loadMultiple());
    $overrideRevisionBefore = (string) $override->getRevisionId();
    $overrideTargetBefore = (int) $override->get('responsible_person')->target_id;
    $overrideStatusBefore = (string) $override->get('status')->value;
    $preparationCountBefore = count($preparationStorage->loadMultiple());
    $preparationRevisionBefore = (string) $requirement->getRevisionId();
    $preparationStateBefore = $requirement->toArray();

    $responsibilityBefore = $effectiveResponsibility->resolve(
      $series,
      $effectiveBefore[$firstBase->originalOccurrenceKey],
    );
    $this->assertSame(EffectiveResponsibility::SOURCE_RULE, $responsibilityBefore->source);
    $this->assertSame($targetId, $responsibilityBefore->responsiblePersonId);
    $preparationsBefore = $preparationEligibility->derive(
      $series,
      $effectiveBefore[$firstBase->originalOccurrenceKey],
    );
    $this->assertCount(1, $preparationsBefore);
    $this->assertSame($targetId, $preparationsBefore[0]->responsiblePersonId);
    $preparationDueBefore = $preparationsBefore[0]->dueAtUtc;

    $renameUrl = Url::fromRoute('personal_secretary.rename_household_member')->toString();
    $occurrenceResponsibilityUrl = Url::fromRoute(
      'personal_secretary.responsibility_occurrence',
      [
        'series' => $seriesId,
        'original_occurrence_key' => $firstBase->originalOccurrenceKey,
      ],
    )->toString();
    $recurringResponsibilityUrl = Url::fromRoute(
      'personal_secretary.edit_recurring_responsibility',
      ['series' => $seriesId],
    )->toString();

    $this->drupalGet($renameUrl);
    $this->assertSession()->statusCodeEquals(403);

    $authorized = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->drupalLogin($authorized);

    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Rename household member');
    $this->assertSession()->linkByHrefExists($renameUrl);

    $this->drupalGet($renameUrl);
    $this->assertSession()->statusCodeEquals(200);
    $personSelect = $this->assertSession()->fieldExists('person_id');
    $newNameField = $this->assertSession()->fieldExists('new_name');
    $this->assertSame('255', $newNameField->getAttribute('maxlength'));
    $this->assertTrue($newNameField->hasAttribute('required'));
    $this->assertSession()->buttonExists('Rename household member');
    $this->assertCount(1, $personSelect->findAll('css', 'option[value="' . $targetId . '"]'));
    $this->assertCount(1, $personSelect->findAll('css', 'option[value="' . $otherMember->id() . '"]'));
    $this->assertCount(0, $personSelect->findAll('css', 'option[value="' . $orphanPerson->id() . '"]'));

    $this->assertPersonUnchanged($personStorage, $targetId, $targetUuid, 'Avery Original Name', $personCountBefore);
    $this->assertSame($householdStateBefore, $this->householdState($householdStorage));

    $this->submitForm([
      'person_id' => (string) $targetId,
      'new_name' => '',
    ], 'Rename household member');
    $this->assertPersonUnchanged($personStorage, $targetId, $targetUuid, 'Avery Original Name', $personCountBefore);

    try {
      $renamer->prepare($targetId, str_repeat('x', 256));
      $this->fail('A Person name longer than 255 characters must fail closed.');
    }
    catch (InvalidArgumentException) {
      // Expected: name length is validated before the single Person write.
    }
    $this->assertPersonUnchanged($personStorage, $targetId, $targetUuid, 'Avery Original Name', $personCountBefore);

    try {
      $renamer->renameMember((int) $orphanPerson->id(), 'Casey Must Stay Orphan');
      $this->fail('An orphan Person must not be renameable through the Household-member flow.');
    }
    catch (InvalidArgumentException) {
      // Expected: current Household membership is re-resolved server-side.
    }
    try {
      $renamer->renameMember(999999, 'Forged Person');
      $this->fail('A forged Person identity must fail closed.');
    }
    catch (InvalidArgumentException) {
      // Expected: forged identities are not in the current Household-member directory.
    }
    $this->assertSame('Casey Orphan Person', (string) $personStorage->load($orphanPerson->id())?->label());
    $this->assertPersonUnchanged($personStorage, $targetId, $targetUuid, 'Avery Original Name', $personCountBefore);

    $this->drupalGet($renameUrl);
    $this->submitForm([
      'person_id' => (string) $targetId,
      'new_name' => '  Avery Corrected Name  ',
    ], 'Rename household member');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);

    $personStorage->resetCache([$targetId]);
    $renamedPerson = $personStorage->load($targetId);
    $this->assertInstanceOf(Person::class, $renamedPerson);
    $this->assertSame($targetId, (int) $renamedPerson->id());
    $this->assertSame($targetUuid, $renamedPerson->uuid());
    $this->assertSame('Avery Corrected Name', (string) $renamedPerson->label());
    $this->assertCount($personCountBefore, $personStorage->loadMultiple());
    $this->assertCount(0, $personStorage->loadByProperties(['name' => 'Avery Original Name']));

    $this->assertCount($householdCountBefore, $householdStorage->loadMultiple());
    $this->assertSame($householdStateBefore, $this->householdState($householdStorage));
    $this->assertArrayHasKey((int) $primaryHousehold->id(), $householdStateBefore);
    $this->assertArrayHasKey((int) $secondaryHousehold->id(), $householdStateBefore);

    $seriesStorage->resetCache([$seriesId]);
    $currentSeries = $seriesStorage->load($seriesId);
    $this->assertInstanceOf(ActivitySeries::class, $currentSeries);
    $this->assertCount($seriesCountBefore, $seriesStorage->loadMultiple());
    $this->assertSame($seriesRevisionBefore, (string) $currentSeries->getRevisionId());
    $this->assertSame($seriesRecurrenceBefore, $currentSeries->get('recurrence')->first()?->getValue());

    $ruleStorage->resetCache([$ruleId]);
    $currentRule = $ruleStorage->load($ruleId);
    $this->assertInstanceOf(ResponsibilityRule::class, $currentRule);
    $this->assertCount($ruleCountBefore, $ruleStorage->loadMultiple());
    $this->assertSame($ruleRevisionBefore, (string) $currentRule->getRevisionId());
    $this->assertSame($ruleTargetBefore, (int) $currentRule->get('responsible_person')->target_id);
    $this->assertSame($ruleEffectiveUntilBefore, $currentRule->get('effective_until')->getValue());

    $overrideStorage->resetCache([$overrideId]);
    $currentOverride = $overrideStorage->load($overrideId);
    $this->assertInstanceOf(ResponsibilityOverride::class, $currentOverride);
    $this->assertCount($overrideCountBefore, $overrideStorage->loadMultiple());
    $this->assertSame($overrideRevisionBefore, (string) $currentOverride->getRevisionId());
    $this->assertSame($overrideTargetBefore, (int) $currentOverride->get('responsible_person')->target_id);
    $this->assertSame($overrideStatusBefore, (string) $currentOverride->get('status')->value);

    $preparationStorage->resetCache([$requirementId]);
    $currentRequirement = $preparationStorage->load($requirementId);
    $this->assertNotNull($currentRequirement);
    $this->assertCount($preparationCountBefore, $preparationStorage->loadMultiple());
    $this->assertSame($preparationRevisionBefore, (string) $currentRequirement->getRevisionId());
    $this->assertSame($preparationStateBefore, $currentRequirement->toArray());

    $effectiveAfter = $this->effectiveByOriginalKey(
      $effectiveProjection->project($currentSeries, $nowUtc, $windowEnd),
    );
    $this->assertArrayHasKey($firstBase->originalOccurrenceKey, $effectiveAfter);
    $responsibilityAfter = $effectiveResponsibility->resolve(
      $currentSeries,
      $effectiveAfter[$firstBase->originalOccurrenceKey],
    );
    $this->assertSame(EffectiveResponsibility::SOURCE_RULE, $responsibilityAfter->source);
    $this->assertSame($targetId, $responsibilityAfter->responsiblePersonId);

    $preparationsAfter = $preparationEligibility->derive(
      $currentSeries,
      $effectiveAfter[$firstBase->originalOccurrenceKey],
    );
    $this->assertCount(1, $preparationsAfter);
    $this->assertSame($targetId, $preparationsAfter[0]->responsiblePersonId);
    $this->assertSame($preparationDueBefore, $preparationsAfter[0]->dueAtUtc);

    $this->drupalGet($occurrenceResponsibilityUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Avery Corrected Name');
    $this->assertSession()->pageTextNotContains('Avery Original Name');
    $samePersonChoice = $this->getSession()->getPage()->find(
      'css',
      'input[value="person:' . $targetId . '"]',
    );
    $this->assertNotNull($samePersonChoice);

    $this->drupalGet($recurringResponsibilityUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Avery Corrected Name');
    $this->assertSession()->pageTextNotContains('Avery Original Name');
    $recurringSelect = $this->getSession()->getPage()->findField('responsible_person_id');
    $this->assertNotNull($recurringSelect);
    $samePersonOption = $recurringSelect->find('css', 'option[value="' . $targetId . '"]');
    $this->assertNotNull($samePersonOption);
    $this->assertSame('Avery Corrected Name', trim($samePersonOption->getText()));
  }

  private function assertPersonUnchanged(
    object $storage,
    int $personId,
    string $expectedUuid,
    string $expectedName,
    int $expectedCount,
  ): void {
    $storage->resetCache([$personId]);
    $person = $storage->load($personId);
    $this->assertInstanceOf(Person::class, $person);
    $this->assertSame($personId, (int) $person->id());
    $this->assertSame($expectedUuid, $person->uuid());
    $this->assertSame($expectedName, (string) $person->label());
    $this->assertCount($expectedCount, $storage->loadMultiple());
  }

  /**
   * @return array<int, array<int, array<string, mixed>>>
   */
  private function householdState(object $storage): array {
    $storage->resetCache();
    $state = [];
    foreach ($storage->loadMultiple() as $household) {
      $this->assertInstanceOf(Household::class, $household);
      $state[(int) $household->id()] = $household->get('members')->getValue();
    }
    ksort($state);
    return $state;
  }

  /**
   * @param \Drupal\personal_secretary\Value\EffectiveOccurrence[] $occurrences
   * @return array<string, \Drupal\personal_secretary\Value\EffectiveOccurrence>
   */
  private function effectiveByOriginalKey(array $occurrences): array {
    $result = [];
    foreach ($occurrences as $occurrence) {
      $result[$occurrence->originalOccurrenceKey] = $occurrence;
    }
    return $result;
  }

}
