<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;

/**
 * Proves the personalized My upcoming view from effective responsibility.
 *
 * @group personal_secretary
 */
final class MyUpcomingTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testMyUpcomingUsesCurrentPersonEffectiveResponsibility(): void {
    $myUpcomingUrl = '/personal-secretary/upcoming/mine';

    $this->drupalGet($myUpcomingUrl);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet(Url::fromRoute('personal_secretary.my_upcoming', [], ['query' => ['days' => '30']]));
    $this->assertSession()->statusCodeEquals(403);

    $this->installUserPersonFieldViaEntityApi();

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\ResponsibilityMutationService $responsibilityMutations */
    $responsibilityMutations = $this->container->get('personal_secretary.responsibility_mutation');
    /** @var \Drupal\personal_secretary\Service\PreparationRequirementMutationService $preparationMutations */
    $preparationMutations = $this->container->get('personal_secretary.preparation_requirement_mutation');
    /** @var \Drupal\personal_secretary\Service\EffectiveOccurrenceProjectionService $effectiveProjection */
    $effectiveProjection = $this->container->get('personal_secretary.effective_occurrence_projection');

    $entityTypeManager = $this->container->get('entity_type.manager');
    $userStorage = $entityTypeManager->getStorage('user');

    $sourceTimezone = new DateTimeZone('Europe/Brussels');
    $nowUtc = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone(new DateTimeZone('UTC'));
    $nowLocal = $nowUtc->setTimezone($sourceTimezone);
    $windowEnd = $nowUtc->modify('+7 days');

    $personA = $domain->createPerson('A Current Person');
    $personB = $domain->createPerson('B Other Person');
    $household = $domain->createHousehold(
      'Synthetic personalized household',
      [(int) $personA->id(), (int) $personB->id()],
    );

    $ruleASeries = $this->createSeriesWithRule(
      'Rule A activity',
      (int) $household->id(),
      (int) $personA->id(),
      $nowLocal->modify('+1 day')->setTime(9, 0),
    );
    $preparationMutations->createPreparationRequirement(
      $ruleASeries,
      'Prepare included A kit',
      1800,
      $nowUtc->modify('-1 day'),
    );

    $ruleBSeries = $this->createSeriesWithRule(
      'Rule B activity',
      (int) $household->id(),
      (int) $personB->id(),
      $nowLocal->modify('+2 days')->setTime(10, 0),
    );
    $preparationMutations->createPreparationRequirement(
      $ruleBSeries,
      'Prepare excluded B kit',
      1800,
      $nowUtc->modify('-1 day'),
    );

    $overrideToASeries = $this->createSeriesWithRule(
      'Override to A activity',
      (int) $household->id(),
      (int) $personB->id(),
      $nowLocal->modify('+3 days')->setTime(11, 0),
    );
    $overrideToAOccurrence = $effectiveProjection->project(
      $overrideToASeries,
      $nowUtc,
      $windowEnd,
    )[0];
    $responsibilityMutations->createAssignOverride(
      $overrideToASeries,
      $overrideToAOccurrence,
      (int) $personA->id(),
    );
    $preparationMutations->createPreparationRequirement(
      $overrideToASeries,
      'Prepare included override kit',
      1800,
      $nowUtc->modify('-1 day'),
    );

    $overrideAwaySeries = $this->createSeriesWithRule(
      'Override away from A activity',
      (int) $household->id(),
      (int) $personA->id(),
      $nowLocal->modify('+4 days')->setTime(12, 0),
    );
    $overrideAwayOccurrence = $effectiveProjection->project(
      $overrideAwaySeries,
      $nowUtc,
      $windowEnd,
    )[0];
    $responsibilityMutations->createAssignOverride(
      $overrideAwaySeries,
      $overrideAwayOccurrence,
      (int) $personB->id(),
    );
    $preparationMutations->createPreparationRequirement(
      $overrideAwaySeries,
      'Prepare excluded override-away kit',
      1800,
      $nowUtc->modify('-1 day'),
    );

    $clearSeries = $this->createSeriesWithRule(
      'Clear responsibility activity',
      (int) $household->id(),
      (int) $personA->id(),
      $nowLocal->modify('+5 days')->setTime(13, 0),
    );
    $clearOccurrence = $effectiveProjection->project(
      $clearSeries,
      $nowUtc,
      $windowEnd,
    )[0];
    $responsibilityMutations->createClearOverride($clearSeries, $clearOccurrence);
    $preparationMutations->createPreparationRequirement(
      $clearSeries,
      'Prepare excluded clear kit',
      1800,
      $nowUtc->modify('-1 day'),
    );

    $noneStart = $nowLocal->modify('+6 days')->setTime(14, 0);
    $noneSeries = $domain->createActivitySeries(
      'No responsibility activity',
      (int) $household->id(),
      $noneStart,
      $noneStart->modify('+1 hour'),
      'FREQ=DAILY;COUNT=1',
    );

    // Future synthetic data proves the two bounded horizons and scoped details.
    $laterOwnedSeries = $this->createSeriesWithRule(
      'Later A activity',
      (int) $household->id(),
      (int) $personA->id(),
      $nowLocal->modify('+20 days')->setTime(9, 0),
    );
    $laterOtherSeries = $this->createSeriesWithRule(
      'Later B activity',
      (int) $household->id(),
      (int) $personB->id(),
      $nowLocal->modify('+21 days')->setTime(10, 0),
    );
    $this->createSeriesWithRule(
      'Outside horizon A activity',
      (int) $household->id(),
      (int) $personA->id(),
      $nowLocal->modify('+32 days')->setTime(11, 0),
    );
    $personC = $domain->createPerson('C Other Household Person');
    $otherHousehold = $domain->createHousehold(
      'Other synthetic household',
      [(int) $personC->id()],
    );
    $otherHouseholdSeries = $this->createSeriesWithRule(
      'Later other-household activity',
      (int) $otherHousehold->id(),
      (int) $personC->id(),
      $nowLocal->modify('+20 days')->setTime(14, 0),
    );
    $laterOwnedOccurrence = $effectiveProjection->project(
      $laterOwnedSeries,
      $nowUtc,
      $nowUtc->modify('+30 days'),
    )[0];
    $laterOtherOccurrence = $effectiveProjection->project(
      $laterOtherSeries,
      $nowUtc,
      $nowUtc->modify('+30 days'),
    )[0];
    $otherHouseholdOccurrence = $effectiveProjection->project(
      $otherHouseholdSeries,
      $nowUtc,
      $nowUtc->modify('+30 days'),
    )[0];
    $laterOwnedDetailUrl = $this->detailUrl(
      $laterOwnedSeries,
      $laterOwnedOccurrence->originalOccurrenceKey,
    );

    $ruleAOccurrence = $effectiveProjection->project($ruleASeries, $nowUtc, $windowEnd)[0];
    $ruleBOccurrence = $effectiveProjection->project($ruleBSeries, $nowUtc, $windowEnd)[0];
    $ruleADetailUrl = $this->detailUrl($ruleASeries, $ruleAOccurrence->originalOccurrenceKey);
    $ruleBDetailUrl = $this->detailUrl($ruleBSeries, $ruleBOccurrence->originalOccurrenceKey);
    $overrideToADetailUrl = $this->detailUrl(
      $overrideToASeries,
      $overrideToAOccurrence->originalOccurrenceKey,
    );

    $authorized = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->assertInstanceOf(UserInterface::class, $authorized);
    $authorized->set(
      CurrentPersonResolver::FIELD_NAME,
      ['target_id' => (int) $personA->id()],
    );
    $authorized->save();
    $authorizedId = (int) $authorized->id();

    $this->drupalLogin($authorized);

    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('My upcoming');
    $this->assertSession()->linkByHrefExists($myUpcomingUrl);
    $this->assertSession()->pageTextContains('Rule A activity');
    $this->assertSession()->pageTextContains('Rule B activity');
    $this->assertSession()->pageTextContains('Override to A activity');
    $this->assertSession()->pageTextContains('Override away from A activity');
    $this->assertSession()->pageTextContains('Clear responsibility activity');
    $this->assertSession()->pageTextContains('No responsibility activity');
    $this->assertSession()->pageTextContains('Not assigned');
    $this->assertSession()->pageTextContains('A Current Person');
    $this->assertSession()->pageTextContains('B Other Person');

    $countsBefore = $this->domainCounts();
    $userStorage->resetCache([$authorizedId]);
    $persistedAuthorized = $userStorage->load($authorizedId);
    $this->assertInstanceOf(UserInterface::class, $persistedAuthorized);
    $mappingBefore = (int) $persistedAuthorized
      ->get(CurrentPersonResolver::FIELD_NAME)
      ->target_id;

    $this->drupalGet($myUpcomingUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Rule A activity');
    $this->assertSession()->pageTextContains('Showing My upcoming activities for the next 7 days.');
    $this->assertSession()->elementExists('css', '.ps-upcoming-horizon a[aria-current="page"]');
    $this->assertSession()->linkByHrefExists($myUpcomingUrl . '?days=30');
    $this->assertSession()->pageTextNotContains('Later A activity');
    $this->assertSession()->pageTextNotContains('Later B activity');
    $this->assertSession()->pageTextNotContains('Later other-household activity');
    $this->assertSession()->pageTextNotContains('Outside horizon A activity');
    $this->assertSession()->linkByHrefExists('/personal-secretary/activities/add');
    $this->assertCount(1, $this->getSession()->getPage()->findAll('css', 'a.ps-upcoming-actions__add'));
    $this->assertSession()->elementExists('css', '.ps-upcoming-actions ~ .ps-upcoming-list');
    $this->assertSession()->pageTextContains('Override to A activity');
    $this->assertSession()->pageTextContains('Prepare included A kit');
    $this->assertSession()->pageTextContains('Prepare included override kit');
    $this->assertSession()->linkByHrefExists($ruleADetailUrl);
    $this->assertSession()->linkByHrefExists($overrideToADetailUrl);
    $this->assertSession()->pageTextContains('This occurrence');
    $this->assertSession()->pageTextContains('Activity settings');
    $this->assertSession()->linkExists('Change responsibility');

    $this->assertSession()->pageTextNotContains('Rule B activity');
    $this->assertSession()->pageTextNotContains('Override away from A activity');
    $this->assertSession()->pageTextNotContains('Clear responsibility activity');
    $this->assertSession()->pageTextNotContains('No responsibility activity');
    $this->assertSession()->pageTextNotContains('Prepare excluded B kit');
    $this->assertSession()->pageTextNotContains('Prepare excluded override-away kit');
    $this->assertSession()->pageTextNotContains('Prepare excluded clear kit');
    $this->assertSession()->pageTextNotContains('B Other Person');

    $this->drupalGet(Url::fromRoute('personal_secretary.my_upcoming', [], ['query' => ['days' => '30']]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Showing My upcoming activities for the next 30 days.');
    $this->assertSession()->pageTextContains('Later A activity');
    $this->assertSession()->pageTextNotContains('Later B activity');
    $this->assertSession()->pageTextNotContains('Later other-household activity');
    $this->assertSession()->pageTextNotContains('Outside horizon A activity');
    $this->assertSession()->linkByHrefExists($laterOwnedDetailUrl . '?days=30');
    $this->assertSession()->elementExists('css', '.ps-upcoming-horizon a[aria-current="page"][href$="days=30"]');
    $this->assertSession()->linkByHrefExists($myUpcomingUrl);

    $this->drupalGet(Url::fromUserInput($laterOwnedDetailUrl, ['query' => ['days' => '30']]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Later A activity');
    $this->assertSession()->linkByHrefExists($myUpcomingUrl . '?days=30');

    $this->drupalGet($this->detailUrl($laterOtherSeries, $laterOtherOccurrence->originalOccurrenceKey));
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalGet($this->detailUrl($otherHouseholdSeries, $otherHouseholdOccurrence->originalOccurrenceKey));
    $this->assertSession()->statusCodeEquals(404);

    // Search is a standard accessible GET form over the authorized window.
    $this->drupalGet($myUpcomingUrl);
    $this->assertSession()->elementExists('css', 'form.ps-upcoming-search[method="get"][role="search"]');
    $this->assertSession()->elementExists('css', 'label[for="ps-upcoming-search-q"]');
    $this->assertSession()->elementExists(
      'css',
      'input#ps-upcoming-search-q[type="search"][name="q"][maxlength="100"]',
    );
    $this->assertSession()->pageTextContains('Search for an activity');
    $this->assertSession()->buttonExists('Search activities');
    $this->assertSession()->elementNotExists('css', 'form.ps-upcoming-search input[name="days"]');

    // A real form submission is read-only, case-insensitive and label-only.
    $this->getSession()->getPage()->fillField('q', 'rUlE a');
    $this->getSession()->getPage()->pressButton('Search activities');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Rule A activity');
    $this->assertSession()->pageTextNotContains('Override to A activity');
    $this->assertSession()->pageTextNotContains('Rule B activity');
    $this->assertSession()->pageTextNotContains('Later A activity');
    $this->assertSession()->pageTextNotContains('Later B activity');
    $this->assertSession()->pageTextNotContains('Later other-household activity');
    $this->assertSession()->pageTextNotContains('Outside horizon A activity');

    $filtered7 = Url::fromRoute('personal_secretary.my_upcoming', [], ['query' => ['q' => 'rUlE a']])->toString();
    $filtered30 = Url::fromRoute(
      'personal_secretary.my_upcoming',
      [],
      ['query' => ['days' => '30', 'q' => 'rUlE a']],
    )->toString();
    $ruleASearchDetail7 = $this->detailUrl(
      $ruleASeries,
      $ruleAOccurrence->originalOccurrenceKey,
      ['q' => 'rUlE a'],
    );
    $ruleASearchDetail30 = $this->detailUrl(
      $ruleASeries,
      $ruleAOccurrence->originalOccurrenceKey,
      ['days' => '30', 'q' => 'rUlE a'],
    );
    $this->assertSession()->linkByHrefExists($filtered7);
    $this->assertSession()->linkByHrefExists($filtered30);
    $this->assertSession()->linkByHrefExists($ruleASearchDetail7);
    $this->assertSession()->linkByHrefExists($myUpcomingUrl);
    $this->assertSession()->linkExists('Clear search');

    // Changing horizon and consulting details must retain both parameters.
    $this->getSession()->getPage()->clickLink('30 days');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Rule A activity');
    $this->assertSession()->pageTextNotContains('Later A activity');
    $this->assertSession()->elementExists(
      'css',
      'form.ps-upcoming-search input[type="hidden"][name="days"][value="30"]',
    );
    $this->assertSession()->linkByHrefExists($filtered7);
    $this->assertSession()->linkByHrefExists($ruleASearchDetail30);
    $this->assertSession()->linkByHrefExists($myUpcomingUrl . '?days=30');

    $this->getSession()->getPage()->clickLink('View details');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkByHrefExists($filtered30);
    $this->drupalGet(Url::fromUserInput($ruleBDetailUrl, [
      'query' => ['days' => '30', 'q' => 'rUlE a'],
    ]));
    $this->assertSession()->statusCodeEquals(404);

    // The same query cannot cross the authorized Person or Household scope.
    $later30 = Url::fromRoute('personal_secretary.my_upcoming', [], ['query' => ['days' => '30', 'q' => 'lAtEr']]);
    $this->drupalGet($later30);
    $this->assertSession()->pageTextContains('Later A activity');
    $this->assertSession()->pageTextNotContains('Rule A activity');
    $this->assertSession()->pageTextNotContains('Later B activity');
    $this->assertSession()->pageTextNotContains('Later other-household activity');
    $this->assertSession()->pageTextNotContains('Outside horizon A activity');
    $this->assertSession()->linkByHrefExists($this->detailUrl(
      $laterOwnedSeries,
      $laterOwnedOccurrence->originalOccurrenceKey,
      ['days' => '30', 'q' => 'lAtEr'],
    ));
    $this->assertSession()->linkByHrefExists($myUpcomingUrl . '?days=30');

    $this->drupalGet(Url::fromRoute('personal_secretary.my_upcoming', [], ['query' => ['q' => 'lAtEr']]));
    $this->assertSession()->pageTextContains('No upcoming activities match this search.');
    $this->assertSession()->pageTextNotContains('Later A activity');
    $this->assertSession()->pageTextNotContains('Later B activity');
    $this->assertSession()->pageTextNotContains('Later other-household activity');
    $this->assertSession()->linkByHrefExists($myUpcomingUrl);

    // Filtering must not search preparatory instructions or expose counts.
    $this->drupalGet(Url::fromRoute('personal_secretary.my_upcoming', [], ['query' => ['q' => 'included A kit']]));
    $this->assertSession()->pageTextContains('No upcoming activities match this search.');
    $this->assertSession()->pageTextNotContains('Prepare included A kit');
    $this->assertSession()->pageTextNotContains('Rule A activity');

    // Empty, malformed and too-long queries restore the historical results.
    foreach ([['q' => '   '], ['q' => ['Rule A']], ['q' => str_repeat('x', 101)]] as $malformedSearch) {
      $this->drupalGet(Url::fromRoute('personal_secretary.my_upcoming', [], ['query' => $malformedSearch]));
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains('Rule A activity');
      $this->assertSession()->pageTextContains('Override to A activity');
      $this->assertSession()->pageTextNotContains('Rule B activity');
      $this->assertSession()->linkByHrefExists($myUpcomingUrl);
      $this->assertSession()->linkByHrefExists($myUpcomingUrl . '?days=30');
      $this->assertSession()->linkNotExists('Clear search');
      $this->assertSession()->pageTextNotContains('No upcoming activities match this search.');
    }

    // Trimming is reflected in generated links; clearing retains 30 days.
    $this->drupalGet(Url::fromRoute(
      'personal_secretary.my_upcoming',
      [],
      ['query' => ['days' => '30', 'q' => '  Later  ']],
    ));
    $this->assertSession()->linkByHrefExists(Url::fromRoute(
      'personal_secretary.my_upcoming',
      [],
      ['query' => ['days' => '30', 'q' => 'Later']],
    )->toString());
    $this->assertSession()->linkByHrefExists($myUpcomingUrl . '?days=30');

    foreach (['31', '-1', 'garbage', ['30']] as $malformed) {
      $this->drupalGet(Url::fromRoute('personal_secretary.my_upcoming', [], ['query' => ['days' => $malformed]]));
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains('Showing My upcoming activities for the next 7 days.');
      $this->assertSession()->pageTextNotContains('Later A activity');
    }
    $this->assertSame($countsBefore, $this->domainCounts());

    $userStorage->resetCache([$authorizedId]);
    $persistedAuthorized = $userStorage->load($authorizedId);
    $this->assertInstanceOf(UserInterface::class, $persistedAuthorized);
    $this->assertSame(
      $mappingBefore,
      (int) $persistedAuthorized
        ->get(CurrentPersonResolver::FIELD_NAME)
        ->target_id,
    );

    $this->drupalGet($ruleADetailUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Rule A activity');
    $this->assertSession()->pageTextContains('A Current Person');
    $this->assertSession()->pageTextContains('Prepare included A kit');
    $this->assertSession()->linkExists('Back to My upcoming');
    $this->assertSession()->linkByHrefExists($myUpcomingUrl);
    $this->assertSession()->pageTextContains('This occurrence');
    $this->assertSession()->pageTextContains('Activity settings');
    $this->assertSame($countsBefore, $this->domainCounts());

    $this->drupalGet($overrideToADetailUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Override to A activity');
    $this->assertSession()->pageTextContains('A Current Person');
    $this->assertSession()->pageTextContains('Prepare included override kit');

    $this->drupalGet($ruleBDetailUrl);
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Rule B activity');
    $this->assertSession()->pageTextNotContains('B Other Person');
    $this->assertSame($countsBefore, $this->domainCounts());

    $unlinked = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->assertInstanceOf(UserInterface::class, $unlinked);
    $this->drupalLogout();
    $this->drupalLogin($unlinked);
    $this->drupalGet($myUpcomingUrl);
    $this->assertRemediationOnly();

    $stale = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->assertInstanceOf(UserInterface::class, $stale);
    $stale->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => 999999]);
    $stale->save();
    $this->drupalLogout();
    $this->drupalLogin($stale);
    $this->drupalGet($myUpcomingUrl);
    $this->assertRemediationOnly();

    $this->assertInstanceOf(ActivitySeries::class, $noneSeries);
  }

  /**
   * @param array<string, string> $query
   */
  private function detailUrl(ActivitySeries $series, string $originalOccurrenceKey, array $query = []): string {
    return Url::fromRoute(
      'personal_secretary.occurrence_detail',
      [
        'series' => (int) $series->id(),
        'original_occurrence_key' => $originalOccurrenceKey,
      ],
      $query === [] ? [] : ['query' => $query],
    )->toString();
  }

  private function createSeriesWithRule(
    string $label,
    int $householdId,
    int $responsiblePersonId,
    DateTimeImmutable $start,
  ): ActivitySeries {
    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\ResponsibilityMutationService $responsibilityMutations */
    $responsibilityMutations = $this->container->get('personal_secretary.responsibility_mutation');

    $end = $start->modify('+1 hour');
    $series = $domain->createActivitySeries(
      $label,
      $householdId,
      $start,
      $end,
      'FREQ=DAILY;COUNT=1',
    );
    $responsibilityMutations->createResponsibilityRule(
      $series,
      $responsiblePersonId,
      $start,
      $end,
      'FREQ=DAILY;COUNT=1',
    );

    return $series;
  }

  /**
   * @return array<string, int>
   */
  private function domainCounts(): array {
    $entityTypeManager = $this->container->get('entity_type.manager');

    return [
      'person' => count($entityTypeManager->getStorage('personal_secretary_person')->loadMultiple()),
      'household' => count($entityTypeManager->getStorage('personal_secretary_household')->loadMultiple()),
      'series' => count($entityTypeManager->getStorage('personal_sec_activity_series')->loadMultiple()),
      'rules' => count($entityTypeManager->getStorage('personal_sec_resp_rule')->loadMultiple()),
      'overrides' => count($entityTypeManager->getStorage('personal_sec_resp_override')->loadMultiple()),
      'preparations' => count($entityTypeManager->getStorage('personal_sec_prep_req')->loadMultiple()),
    ];
  }

  private function assertRemediationOnly(): void {
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains(
      'Link your account to a Household member to see My upcoming.',
    );
    $this->assertSession()->linkExists('Link my account to household member');
    $this->assertSession()->linkByHrefExists('/personal-secretary/account/person');
    $this->assertSession()->elementNotExists('css', '.ps-upcoming-actions__add');
    $this->assertSession()->elementNotExists('css', 'form.ps-upcoming-search');

    foreach ([
      'Rule A activity',
      'Rule B activity',
      'Override to A activity',
      'Override away from A activity',
      'Clear responsibility activity',
      'No responsibility activity',
      'A Current Person',
      'B Other Person',
      'Prepare included A kit',
      'Prepare included override kit',
      'Prepare excluded B kit',
    ] as $leakedText) {
      $this->assertSession()->pageTextNotContains($leakedText);
    }
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
