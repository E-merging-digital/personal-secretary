<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\personal_secretary\Entity\Household;
use InvalidArgumentException;

/**
 * Proves one new Person can be added to one selected Household.
 *
 * @group personal_secretary
 */
final class AddHouseholdMemberTest extends BrowserTestBase {

  protected static $modules = ['block', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testAddHouseholdMemberUnlocksOccurrenceResponsibilityChoice(): void {
    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\AddHouseholdMemberService $addMember */
    $addMember = $this->container->get('personal_secretary.add_household_member');
    /** @var \Drupal\personal_secretary\Service\ResponsibilityMutationService $responsibilityMutations */
    $responsibilityMutations = $this->container->get('personal_secretary.responsibility_mutation');
    /** @var \Drupal\personal_secretary\Service\OccurrenceProjectionService $baseProjection */
    $baseProjection = $this->container->get('personal_secretary.occurrence_projection');
    $entityTypeManager = $this->container->get('entity_type.manager');

    $utc = new DateTimeZone('UTC');
    $sourceTimezone = new DateTimeZone('Europe/Brussels');
    $nowUtc = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone($utc);
    $firstStartLocal = $nowUtc
      ->setTimezone($sourceTimezone)
      ->modify('+2 days')
      ->setTime(10, 0);
    $firstEndLocal = $firstStartLocal->modify('+1 hour');

    $existingPerson = $domain->createPerson('Avery Existing');
    $otherHouseholdPerson = $domain->createPerson('Casey Other Household');
    $selectedHousehold = $domain->createHousehold(
      'Synthetic selected household',
      [(int) $existingPerson->id()],
    );
    $otherHousehold = $domain->createHousehold(
      'Synthetic other household',
      [(int) $otherHouseholdPerson->id()],
    );
    $series = $domain->createActivitySeries(
      'Synthetic member integration activity',
      (int) $selectedHousehold->id(),
      $firstStartLocal,
      $firstEndLocal,
      'FREQ=WEEKLY;INTERVAL=1',
    );
    $rule = $responsibilityMutations->createResponsibilityRule(
      $series,
      (int) $existingPerson->id(),
      $firstStartLocal,
      $firstEndLocal,
      'FREQ=WEEKLY;INTERVAL=1',
    );

    $occurrences = $baseProjection->project(
      $series,
      $nowUtc,
      $nowUtc->modify('+7 days'),
    );
    $this->assertCount(1, $occurrences);
    $target = $occurrences[0];

    $addMemberUrl = Url::fromRoute('personal_secretary.add_household_member')->toString();
    $responsibilityUrl = Url::fromRoute(
      'personal_secretary.responsibility_occurrence',
      [
        'series' => (int) $series->id(),
        'original_occurrence_key' => $target->originalOccurrenceKey,
      ],
    )->toString();

    $this->drupalGet($addMemberUrl);
    $this->assertSession()->statusCodeEquals(403);

    $authorized = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->drupalLogin($authorized);

    $personStorage = $entityTypeManager->getStorage('personal_secretary_person');
    $householdStorage = $entityTypeManager->getStorage('personal_secretary_household');
    $seriesStorage = $entityTypeManager->getStorage('personal_sec_activity_series');
    $ruleStorage = $entityTypeManager->getStorage('personal_sec_resp_rule');
    $overrideStorage = $entityTypeManager->getStorage('personal_sec_resp_override');
    $preparationStorage = $entityTypeManager->getStorage('personal_sec_prep_req');

    $personCountBefore = count($personStorage->loadMultiple());
    $householdCountBefore = count($householdStorage->loadMultiple());
    $seriesCountBefore = count($seriesStorage->loadMultiple());
    $ruleCountBefore = count($ruleStorage->loadMultiple());
    $overrideCountBefore = count($overrideStorage->loadMultiple());
    $preparationCountBefore = count($preparationStorage->loadMultiple());
    $seriesRevisionBefore = (string) $series->getRevisionId();
    $ruleRevisionBefore = (string) $rule->getRevisionId();
    $selectedMemberIdsBefore = $this->memberIds($selectedHousehold);
    $otherMemberIdsBefore = $this->memberIds($otherHousehold);

    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Add household member');
    $this->assertSession()->linkByHrefExists($addMemberUrl);

    $this->drupalGet($addMemberUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Synthetic selected household');
    $this->assertSession()->pageTextContains('Synthetic other household');
    $this->assertSession()->fieldExists('household_id');
    $personNameField = $this->assertSession()->fieldExists('person_name');
    $this->assertSame('255', $personNameField->getAttribute('maxlength'));
    $this->assertTrue($personNameField->hasAttribute('required'));
    $this->assertSession()->buttonExists('Add household member');

    $this->assertCount($personCountBefore, $personStorage->loadMultiple());
    $this->assertCount($householdCountBefore, $householdStorage->loadMultiple());
    $this->assertSame(
      $selectedMemberIdsBefore,
      $this->reloadedMemberIds($householdStorage, (int) $selectedHousehold->id()),
    );

    try {
      $addMember->addNewMember(999999, 'Forged Household Person');
      $this->fail('A forged Household identity must fail closed before Person creation.');
    }
    catch (InvalidArgumentException) {
      // Expected: the target Household is resolved before the first write.
    }
    $this->assertCount($personCountBefore, $personStorage->loadMultiple());
    $this->assertSame(
      $selectedMemberIdsBefore,
      $this->reloadedMemberIds($householdStorage, (int) $selectedHousehold->id()),
    );

    $this->drupalGet($addMemberUrl);
    $this->submitForm([
      'household_id' => (string) $selectedHousehold->id(),
      'person_name' => '',
    ], 'Add household member');
    $this->assertCount($personCountBefore, $personStorage->loadMultiple());
    $this->assertSame(
      $selectedMemberIdsBefore,
      $this->reloadedMemberIds($householdStorage, (int) $selectedHousehold->id()),
    );

    $this->drupalGet($addMemberUrl);
    $this->submitForm([
      'household_id' => (string) $selectedHousehold->id(),
      'person_name' => 'Blair New Member',
    ], 'Add household member');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);

    $people = array_values($personStorage->loadByProperties(['name' => 'Blair New Member']));
    $this->assertCount(1, $people);
    $newPerson = $people[0];
    $this->assertNotNull($newPerson->id());

    $this->assertCount($personCountBefore + 1, $personStorage->loadMultiple());
    $this->assertCount($householdCountBefore, $householdStorage->loadMultiple());

    $selectedMemberIdsAfter = $this->reloadedMemberIds(
      $householdStorage,
      (int) $selectedHousehold->id(),
    );
    $this->assertCount(count($selectedMemberIdsBefore) + 1, $selectedMemberIdsAfter);
    $this->assertContains((int) $existingPerson->id(), $selectedMemberIdsAfter);
    $this->assertContains((int) $newPerson->id(), $selectedMemberIdsAfter);
    $this->assertSame(
      $otherMemberIdsBefore,
      $this->reloadedMemberIds($householdStorage, (int) $otherHousehold->id()),
    );

    $this->assertCount($seriesCountBefore, $seriesStorage->loadMultiple());
    $this->assertCount($ruleCountBefore, $ruleStorage->loadMultiple());
    $this->assertCount($overrideCountBefore, $overrideStorage->loadMultiple());
    $this->assertCount($preparationCountBefore, $preparationStorage->loadMultiple());

    $seriesStorage->resetCache([(int) $series->id()]);
    $currentSeries = $seriesStorage->load($series->id());
    $this->assertNotNull($currentSeries);
    $this->assertSame($seriesRevisionBefore, (string) $currentSeries->getRevisionId());

    $ruleStorage->resetCache([(int) $rule->id()]);
    $currentRule = $ruleStorage->load($rule->id());
    $this->assertNotNull($currentRule);
    $this->assertSame($ruleRevisionBefore, (string) $currentRule->getRevisionId());
    $this->assertSame((int) $existingPerson->id(), (int) $currentRule->get('responsible_person')->target_id);

    $this->drupalGet($responsibilityUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Avery Existing');
    $this->assertSession()->pageTextContains('Blair New Member');
    $this->assertSession()->pageTextNotContains('Casey Other Household');
    $this->assertSession()->buttonExists('Save responsibility');
    $this->assertCount($overrideCountBefore, $overrideStorage->loadMultiple());
  }

  /**
   * @return int[]
   */
  private function memberIds(Household $household): array {
    $ids = array_map(
      static fn(array $item): int => (int) ($item['target_id'] ?? 0),
      $household->get('members')->getValue(),
    );
    sort($ids);
    return $ids;
  }

  /**
   * @return int[]
   */
  private function reloadedMemberIds(object $storage, int $householdId): array {
    $storage->resetCache([$householdId]);
    $household = $storage->load($householdId);
    $this->assertInstanceOf(Household::class, $household);
    return $this->memberIds($household);
  }

}
