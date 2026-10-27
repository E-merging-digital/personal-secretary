<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\PreparationCompletion;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;

/**
 * Proves the derived authorized current-user preparation read surfaces.
 *
 * @group personal_secretary
 */
final class CurrentUserPreparationTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testMyPreparationsAreDueBoundedAuthorizedAndDerived(): void {
    $route = '/personal-secretary/preparations/mine';
    $this->drupalGet($route);
    $this->assertSession()->statusCodeEquals(403);

    $this->installUserReferenceField(
      CurrentPersonResolver::FIELD_NAME,
      'personal_secretary_person',
      1,
      'Personal Secretary person',
    );
    $this->installUserReferenceField(
      HouseholdAuthorizationService::FIELD_NAME,
      'personal_secretary_household',
      FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
      'Personal Secretary households',
    );

    $this->config('system.date')
      ->set('timezone.default', 'Europe/Brussels')
      ->set('timezone.user.default', UserInterface::TIMEZONE_EMPTY)
      ->save();

    $domain = $this->container->get('personal_secretary.domain_mutation');
    $responsibilityMutations = $this->container->get('personal_secretary.responsibility_mutation');
    $preparationMutations = $this->container->get('personal_secretary.preparation_requirement_mutation');
    $effective = $this->container->get('personal_secretary.effective_occurrence_projection');
    $timeline = $this->container->get('personal_secretary.revision_timeline');
    $exceptions = $this->container->get('personal_secretary.activity_exception');
    $preparations = $this->container->get('personal_secretary.current_user_preparation');

    $utc = new DateTimeZone('UTC');
    $fixedNow = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone($utc);

    $person = $domain->createPerson('Preparation current person');
    $otherPerson = $domain->createPerson('Preparation other person');
    $emptyPerson = $domain->createPerson('Preparation empty person');
    $h1 = $domain->createHousehold(
      'Preparation authorized household',
      [(int) $person->id(), (int) $otherPerson->id(), (int) $emptyPerson->id()],
    );
    $h2 = $domain->createHousehold(
      'Preparation unauthorized household',
      [(int) $person->id(), (int) $otherPerson->id()],
    );

    $authorized = $this->productUser($person->id(), [$h1->id()], 'Europe/Brussels');
    $secondGranted = $this->productUser($person->id(), [$h1->id()], 'Europe/Brussels');
    $noGrant = $this->productUser($person->id(), [], 'Europe/Brussels');
    $unlinked = $this->productUser(NULL, [$h1->id()], 'Europe/Brussels');
    $stale = $this->productUser(999999, [$h1->id()], 'Europe/Brussels');
    $emptyGranted = $this->productUser($emptyPerson->id(), [$h1->id()], 'Europe/Brussels');
    $fallback = $this->productUser($person->id(), [$h1->id()], '');

    $userStorage = $this->container->get('entity_type.manager')->getStorage('user');
    $userStorage->resetCache([(int) $fallback->id()]);
    $persistedFallback = $userStorage->load((int) $fallback->id());
    $this->assertInstanceOf(UserInterface::class, $persistedFallback);
    $this->assertSame('', (string) $persistedFallback->getTimeZone());

    $effectiveFrom = $fixedNow->modify('-30 days');

    $overdueSeries = $this->createSeriesWithRule('Overdue preparation activity', (int) $h1->id(), (int) $person->id(), $fixedNow->modify('+1 day'));
    $preparationMutations->createPreparationRequirement($overdueSeries, 'Active overdue preparation', 2 * 86400, $effectiveFrom);

    $dueNowSeries = $this->createSeriesWithRule('Due now preparation activity', (int) $h1->id(), (int) $person->id(), $fixedNow->modify('+1 day'));
    $preparationMutations->createPreparationRequirement($dueNowSeries, 'Due now preparation', 86400, $effectiveFrom);

    $allDayStart = $fixedNow
      ->setTimezone(new DateTimeZone('Europe/Brussels'))
      ->modify('+2 days')
      ->setTime(0, 0, 0);
    $allDayEnd = $allDayStart->modify('+1 day');
    $allDaySeries = $domain->createActivitySeries(
      'All-day preparation activity',
      (int) $h1->id(),
      $allDayStart,
      $allDayEnd,
      'FREQ=DAILY;COUNT=1',
      '',
      [],
      ActivitySeries::TIME_MODE_ALL_DAY,
    );
    $responsibilityMutations->createResponsibilityRule(
      $allDaySeries,
      (int) $person->id(),
      $allDayStart,
      $allDayEnd,
      'FREQ=DAILY;COUNT=1',
    );
    $preparationMutations->createPreparationRequirement(
      $allDaySeries,
      'All-day preparation',
      86400,
      $effectiveFrom,
    );

    $longLeadSeries = $this->createSeriesWithRule('Long lead activity outside seven days', (int) $h1->id(), (int) $person->id(), $fixedNow->modify('+12 days'));
    $preparationMutations->createPreparationRequirement($longLeadSeries, 'Long lead preparation due inside seven days', 10 * 86400, $effectiveFrom);

    $boundarySeries = $this->createSeriesWithRule('Boundary preparation activity', (int) $h1->id(), (int) $person->id(), $fixedNow->modify('+9 days'));
    $preparationMutations->createPreparationRequirement($boundarySeries, 'Preparation due exactly at window end', 2 * 86400, $effectiveFrom);

    $futureSeries = $this->createSeriesWithRule('Future preparation activity', (int) $h1->id(), (int) $person->id(), $fixedNow->modify('+10 days'));
    $preparationMutations->createPreparationRequirement($futureSeries, 'Preparation due after seven days', 2 * 86400, $effectiveFrom);

    $pastSeries = $this->createSeriesWithRule('Already started activity', (int) $h1->id(), (int) $person->id(), $fixedNow->modify('-2 hours'));
    $preparationMutations->createPreparationRequirement($pastSeries, 'Preparation for already started activity', 86400, $effectiveFrom);

    $h2Series = $this->createSeriesWithRule('Unauthorized H2 preparation activity', (int) $h2->id(), (int) $person->id(), $fixedNow->modify('+20 days'));
    $preparationMutations->createPreparationRequirement($h2Series, 'Unauthorized H2 thirty day lead preparation', 30 * 86400, $effectiveFrom);

    $otherSeries = $this->createSeriesWithRule('Other responsibility preparation activity', (int) $h1->id(), (int) $otherPerson->id(), $fixedNow->modify('+3 days'));
    $preparationMutations->createPreparationRequirement($otherSeries, 'Other person preparation', 2 * 86400, $effectiveFrom);

    $cancelSeries = $this->createSeriesWithRule('Cancelled preparation activity', (int) $h1->id(), (int) $person->id(), $fixedNow->modify('+3 days'));
    $preparationMutations->createPreparationRequirement($cancelSeries, 'Preparation removed by cancel', 2 * 86400, $effectiveFrom);

    $rescheduleSeries = $this->createSeriesWithRule('Rescheduled preparation activity', (int) $h1->id(), (int) $person->id(), $fixedNow->modify('+4 days'));
    $preparationMutations->createPreparationRequirement($rescheduleSeries, 'Preparation moved out by reschedule', 3 * 86400, $effectiveFrom);

    $toCurrentSeries = $this->createSeriesWithRule('Responsibility to current preparation activity', (int) $h1->id(), (int) $otherPerson->id(), $fixedNow->modify('+5 days'));
    $preparationMutations->createPreparationRequirement($toCurrentSeries, 'Preparation appears after responsibility override', 4 * 86400, $effectiveFrom);

    $awaySeries = $this->createSeriesWithRule('Responsibility away preparation activity', (int) $h1->id(), (int) $person->id(), $fixedNow->modify('+5 days'));
    $preparationMutations->createPreparationRequirement($awaySeries, 'Preparation disappears after responsibility override', 4 * 86400, $effectiveFrom);

    $lifecycleSeries = $this->createSeriesWithRule('Requirement lifecycle preparation activity', (int) $h1->id(), (int) $person->id(), $fixedNow->modify('+6 days'));
    $oldRequirement = $preparationMutations->createPreparationRequirement($lifecycleSeries, 'Old preparation requirement', 5 * 86400, $effectiveFrom);

    $accountSwitcher = $this->container->get('account_switcher');
    $accountSwitcher->switchTo($authorized);
    try {
      $before = $preparations->mine($fixedNow);
    }
    finally {
      $accountSwitcher->switchBack();
    }

    $beforeItems = $this->itemsByInstruction($before['items']);
    foreach ([
      'Active overdue preparation',
      'Due now preparation',
      'All-day preparation',
      'Long lead preparation due inside seven days',
      'Preparation removed by cancel',
      'Preparation moved out by reschedule',
      'Preparation disappears after responsibility override',
      'Old preparation requirement',
    ] as $expected) {
      $this->assertArrayHasKey($expected, $beforeItems);
    }
    foreach (['Preparation due exactly at window end', 'Preparation due after seven days', 'Preparation for already started activity', 'Unauthorized H2 thirty day lead preparation', 'Other person preparation', 'Preparation appears after responsibility override'] as $excluded) {
      $this->assertArrayNotHasKey($excluded, $beforeItems);
    }
    $this->assertTrue($beforeItems['Active overdue preparation']['overdue']);
    $this->assertFalse($beforeItems['Due now preparation']['overdue']);

    $dateFormatter = $this->container->get('date.formatter');

    $timedItem = $beforeItems['Due now preparation'];
    $this->assertFalse($timedItem['all_day']);
    $timedStart = new DateTimeImmutable($timedItem['activity_start_iso']);
    $timedDue = new DateTimeImmutable($timedItem['due_time_iso']);
    $this->assertSame(
      $dateFormatter->format($timedStart->getTimestamp(), 'custom', 'j M Y, H:i', 'Europe/Brussels'),
      $timedItem['activity_start'],
    );
    $this->assertSame(
      $dateFormatter->format($timedDue->getTimestamp(), 'custom', 'j M Y, H:i', 'Europe/Brussels'),
      $timedItem['due_time'],
    );

    $allDayItem = $beforeItems['All-day preparation'];
    $this->assertTrue($allDayItem['all_day']);
    $this->assertSame('', $allDayItem['activity_start']);
    $this->assertSame($allDayStart->format('Y-m-d'), $allDayItem['all_day_start_date']);
    $this->assertSame($allDayStart->format('Y-m-d'), $allDayItem['all_day_end_date']);
    $this->assertSame(
      $dateFormatter->format(
        $allDayStart->setTime(12, 0, 0)->getTimestamp(),
        'custom',
        'j M Y',
        'Europe/Brussels',
      ),
      $allDayItem['all_day_start_label'],
    );
    $this->assertSame(10 * 86400, $before['max_lead_time_seconds']);
    $this->assertSame($fixedNow->modify('+17 days')->format(DATE_ATOM), $before['occurrence_projection_end']);
    $this->assertGreaterThan(
      $fixedNow->modify('+7 days')->getTimestamp(),
      (new DateTimeImmutable($beforeItems['Long lead preparation due inside seven days']['activity_start_iso']))->getTimestamp(),
    );

    $cancelTarget = $timeline->projectBaseWindow($cancelSeries, $fixedNow, $fixedNow->modify('+17 days'))[0];
    $exceptions->createCancel($cancelSeries, $cancelTarget);

    $rescheduleTarget = $timeline->projectBaseWindow($rescheduleSeries, $fixedNow, $fixedNow->modify('+17 days'))[0];
    $exceptions->createReschedule(
      $rescheduleSeries,
      $rescheduleTarget,
      $fixedNow->modify('+12 days'),
      $fixedNow->modify('+12 days')->modify('+1 hour'),
      'UTC',
    );

    $toCurrentOccurrence = $effective->project($toCurrentSeries, $fixedNow, $fixedNow->modify('+17 days'))[0];
    $responsibilityMutations->createAssignOverride($toCurrentSeries, $toCurrentOccurrence, (int) $person->id());

    $awayOccurrence = $effective->project($awaySeries, $fixedNow, $fixedNow->modify('+17 days'))[0];
    $responsibilityMutations->createAssignOverride($awaySeries, $awayOccurrence, (int) $otherPerson->id());

    $lifecycleStart = $fixedNow->modify('+6 days');
    $preparationMutations->retirePreparationRequirement($oldRequirement, $lifecycleStart);
    $preparationMutations->createPreparationRequirement($lifecycleSeries, 'Replacement preparation requirement', 4 * 86400, $lifecycleStart);

    // A preparation due on day 20 can belong to an activity beyond day 30.
    $distantActivity = $this->createSeriesWithRule('Activity on day thirty-five', (int) $h1->id(), (int) $person->id(), $fixedNow->modify('+35 days'));
    $preparationMutations->createPreparationRequirement($distantActivity, 'Preparation due on day twenty', 15 * 86400, $effectiveFrom);

    $beyondHorizon = $this->createSeriesWithRule('Activity on day thirty-six', (int) $h1->id(), (int) $person->id(), $fixedNow->modify('+36 days'));
    $preparationMutations->createPreparationRequirement($beyondHorizon, 'Preparation due on day thirty-one', 5 * 86400, $effectiveFrom);

    $accountSwitcher->switchTo($authorized);
    try {
      $after = $preparations->mine($fixedNow);
      $explicitSeven = $preparations->mine($fixedNow, 7);
      $backgroundSeven = $preparations->mineForUser($authorized, $fixedNow);
      $extended = $preparations->mine($fixedNow, 30);
    }
    finally {
      $accountSwitcher->switchBack();
    }

    $afterItems = $this->itemsByInstruction($after['items']);
    foreach ([
      'Active overdue preparation',
      'Due now preparation',
      'All-day preparation',
      'Long lead preparation due inside seven days',
      'Preparation appears after responsibility override',
      'Replacement preparation requirement',
    ] as $expected) {
      $this->assertArrayHasKey($expected, $afterItems);
    }
    foreach (['Preparation removed by cancel', 'Preparation moved out by reschedule', 'Preparation disappears after responsibility override', 'Old preparation requirement', 'Unauthorized H2 thirty day lead preparation', 'Preparation due exactly at window end', 'Preparation due after seven days', 'Preparation for already started activity'] as $excluded) {
      $this->assertArrayNotHasKey($excluded, $afterItems);
    }
    $this->assertSame('Europe/Brussels', $after['timezone']);
    $this->assertDueOrder($after['items']);
    $this->assertSame($after, $explicitSeven, 'The explicit seven-day horizon preserves the default read model.');
    $this->assertSame($after, $backgroundSeven, 'Background reads retain their seven-day default.');
    $this->assertArrayNotHasKey('Preparation due on day twenty', $afterItems);
    $this->assertArrayNotHasKey('Preparation due on day thirty-one', $afterItems);

    $extendedItems = $this->itemsByInstruction($extended['items']);
    $this->assertArrayHasKey('Preparation due on day twenty', $extendedItems);
    $this->assertArrayNotHasKey('Preparation due on day thirty-one', $extendedItems);
    foreach (['Unauthorized H2 thirty day lead preparation', 'Other person preparation', 'Preparation removed by cancel', 'Preparation moved out by reschedule', 'Preparation disappears after responsibility override'] as $excluded) {
      $this->assertArrayNotHasKey($excluded, $extendedItems);
    }
    $this->assertSame($fixedNow->modify('+30 days')->format(DATE_ATOM), $extended['due_window_end']);
    $this->assertSame($fixedNow->modify('+45 days')->format(DATE_ATOM), $extended['occurrence_projection_end']);
    $this->assertTrue($extendedItems['Active overdue preparation']['overdue']);
    $this->assertDueOrder($extended['items']);

    // One synthetic explicit completion proves that both horizon views retain
    // the existing Prepared/To prepare groups without a GET-time mutation.
    $candidate = $afterItems['All-day preparation'];
    $accountSwitcher->switchTo($authorized);
    try {
      $completion = $this->container->get('personal_secretary.preparation_completion');
      $this->assertTrue($completion->markPrepared(
        (int) $candidate['_completion_series_id'],
        (string) $candidate['_completion_original_occurrence_key'],
        (int) $candidate['_completion_requirement_id'],
      ));
      $preparedThirty = $preparations->mine($fixedNow, 30);
    }
    finally {
      $accountSwitcher->switchBack();
    }
    $this->assertTrue($this->itemsByInstruction($preparedThirty['items'])['All-day preparation']['prepared']);

    $accountSwitcher->switchTo($fallback);
    try {
      $fallbackModel = $preparations->mine($fixedNow);
    }
    finally {
      $accountSwitcher->switchBack();
    }
    $this->assertSame('Europe/Brussels', $fallbackModel['timezone']);

    // #198 diagnostic and durable scope proof: a Household member with no
    // effective responsibility must not inherit another Person's preparations.
    $emptyModel = $preparations->mineForUser($emptyGranted, $fixedNow);
    $this->assertSame(
      [],
      $emptyModel['items'],
      'A Household member without effective responsibility must have no preparation items.',
    );

    $this->drupalLogin($authorized);
    $countsBefore = $this->domainCounts();
    $this->drupalGet($route);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Showing active overdue preparations and preparations due in the next 7 days.');
    $this->assertSession()->linkByHrefExists($route . '?days=7');
    $this->assertSession()->linkByHrefExists($route . '?days=30');
    $this->assertSession()->elementExists('xpath', '//a[@aria-current="page"][contains(@href, "days=7")]');
    $this->assertSession()->pageTextNotContains('Preparation due on day twenty');
    $this->assertSession()->pageTextContains('Active overdue preparation');
    $this->assertSession()->pageTextContains('Long lead preparation due inside seven days');
    $this->assertSession()->pageTextContains('Preparation appears after responsibility override');
    $this->assertSession()->pageTextContains('Replacement preparation requirement');
    $this->assertSession()->pageTextContains('All-day preparation');
    $this->assertSession()->pageTextContains('All-day preparation activity');
    $this->assertSession()->pageTextContains('All day');
    $this->assertSession()->pageTextContains($afterItems['Due now preparation']['activity_start']);
    $this->assertSession()->elementNotExists('css', 'a.ps-upcoming-actions__add');

    $allDayRendered = $afterItems['All-day preparation'];
    $allDayArticle = '//article[contains(@class, "personal-secretary-preparation-item")][.//h3[normalize-space()="All-day preparation"]]';
    $this->assertSession()->elementExists(
      'xpath',
      $allDayArticle . '//time[@datetime="' . $allDayRendered['all_day_start_date'] . '"]',
    );
    $this->assertSession()->elementNotExists(
      'xpath',
      $allDayArticle . '//time[@datetime="' . $allDayRendered['activity_start_iso'] . '"]',
    );

    $this->assertSession()->pageTextContains('Overdue');
    $this->assertSession()->pageTextContains('Europe/Brussels');
    $this->assertSession()->pageTextNotContains('Unauthorized H2 thirty day lead preparation');
    $this->assertSession()->pageTextNotContains('Preparation removed by cancel');
    $this->assertSession()->pageTextNotContains('Preparation moved out by reschedule');
    $this->assertSession()->elementExists(
      'xpath',
      '//div[contains(@class, "ps-preparation-row--prepared")][.//h3[normalize-space()="All-day preparation"]]',
    );
    $this->assertSame($countsBefore, $this->domainCounts());

    // The wider GET-only horizon includes day 20 but excludes day 31,
    // canceled/reassigned and unauthorized preparations.
    $this->drupalGet($route, ['query' => ['days' => '30']]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Showing active overdue preparations and preparations due in the next 30 days.');
    $this->assertSession()->elementExists('xpath', '//a[@aria-current="page"][contains(@href, "days=30")]');
    $this->assertSession()->pageTextContains('Preparation due on day twenty');
    $this->assertSession()->pageTextContains('Active overdue preparation');
    $this->assertSession()->pageTextNotContains('Preparation due on day thirty-one');
    $this->assertSession()->pageTextNotContains('Unauthorized H2 thirty day lead preparation');
    $this->assertSession()->pageTextNotContains('Preparation disappears after responsibility override');
    $this->assertSession()->pageTextNotContains('Preparation moved out by reschedule');
    $this->assertSession()->elementExists(
      'xpath',
      '//div[contains(@class, "ps-preparation-row--prepared")][.//h3[normalize-space()="All-day preparation"]]',
    );
    $this->assertSame($countsBefore, $this->domainCounts(), 'Thirty-day GET must not write domain data.');

    // A forged, array-valued or unsupported horizon fails back to seven days.
    foreach ([['days' => '7'], ['days' => 'invalid'], ['days' => '-7'], ['days' => '31'], ['days' => ['30']]] as $query) {
      $this->drupalGet($route, ['query' => $query]);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains('Showing active overdue preparations and preparations due in the next 7 days.');
      $this->assertSession()->pageTextNotContains('Preparation due on day twenty');
      $this->assertSame($countsBefore, $this->domainCounts(), 'Reading a malformed horizon must not mutate domain data.');
    }
    // Search only the authorized due-window projection, using a real GET form.
    $this->drupalGet($route);
    $this->assertSession()->elementExists('css', 'form.ps-preparation-search[method="get"][role="search"]');
    $this->assertSession()->elementExists('css', 'label[for="ps-preparation-search-q"]');
    $this->assertSession()->elementExists(
      'css',
      'input#ps-preparation-search-q[type="search"][name="q"][maxlength="100"]',
    );
    $this->assertSession()->elementExists(
      'css',
      'form.ps-preparation-search input[type="hidden"][name="days"][value="7"]',
    );
    $this->assertSession()->buttonExists('Search preparations');
    $this->getSession()->getPage()->fillField('q', 'dUe nOw');
    $this->getSession()->getPage()->pressButton('Search preparations');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSearchContext($route, 'dUe nOw', 7);
    $this->assertSession()->elementExists('css', 'form.ps-preparation-search input[name="q"][value="dUe nOw"]');
    $this->assertSession()->pageTextContains('Due now preparation');
    $rows = $this->getSession()->getPage()->findAll('css', '#ps-preparation-live .ps-preparation-row h3');
    $visibleInstructions = array_map(static fn($row): string => trim($row->getText()), $rows);
    $this->assertSame(['Due now preparation'], $visibleInstructions, 'Search must filter the displayed preparation rows.');
    $this->assertSession()->linkExists('Clear search');
    $this->assertSession()->linkByHrefExists($route . '?days=7');
    $this->assertSession()->linkByHrefExists(Url::fromRoute(
      'personal_secretary.my_preparations',
      [],
      ['query' => ['days' => 30, 'q' => 'dUe nOw']],
    )->toString());
    $this->assertSame($countsBefore, $this->domainCounts(), 'A GET search must not mutate preparations.');

    $this->getSession()->getPage()->clickLink('30 days');
    $this->assertSearchContext($route, 'dUe nOw', 30);
    $this->assertSession()->pageTextContains('Due now preparation');
    $this->assertSession()->pageTextNotContains('Preparation due on day twenty');
    $this->assertSession()->linkByHrefExists($route . '?days=30');

    // A prepared matching activity remains in the Prepared partition.
    $this->drupalGet($route, ['query' => ['q' => 'aLl-dAy preparation aCtIvItY']]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('All-day preparation');
    $this->assertSession()->pageTextNotContains('Due now preparation');
    $this->assertSession()->elementExists(
      'xpath',
      '//div[contains(@class, "ps-preparation-row--prepared")][.//h3[normalize-space()="All-day preparation"]]',
    );
    $this->assertSession()->pageTextContains('Nothing currently needs preparation.');

    // An item due on day 20 appears only when the requested window is 30.
    $this->drupalGet($route, ['query' => ['days' => '7', 'q' => 'day twenty']]);
    $this->assertSession()->pageTextContains('No preparations match this search.');
    $this->assertSession()->pageTextNotContains('Preparation due on day twenty');
    $this->assertSession()->elementNotExists('css', 'a.ps-upcoming-actions__add');
    $this->getSession()->getPage()->clickLink('30 days');
    $this->assertSearchContext($route, 'day twenty', 30);
    $this->assertSession()->pageTextContains('Preparation due on day twenty');
    $this->assertSession()->pageTextNotContains('Preparation due on day thirty-one');
    $this->assertSession()->pageTextNotContains('Unauthorized H2 thirty day lead preparation');

    // Neither an unauthorized label nor display-only metadata can be searched.
    foreach (['Unauthorized H2', 'Other person', 'Europe/Brussels'] as $excludedSearch) {
      $this->drupalGet($route, ['query' => ['days' => '30', 'q' => $excludedSearch]]);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains('No preparations match this search.');
      $this->assertSession()->pageTextNotContains('Preparations are added through activities.');
      $this->assertSession()->elementNotExists('css', 'a.ps-upcoming-actions__add');
    }

    // Empty, non-string and excessive queries retain the historical list.
    foreach ([['q' => '   '], ['q' => ['Due now']], ['q' => str_repeat('x', 101)]] as $invalidSearch) {
      $this->drupalGet($route, ['query' => $invalidSearch]);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains('Due now preparation');
      $this->assertSession()->pageTextContains('Active overdue preparation');
      $this->assertSession()->linkByHrefExists($route . '?days=7');
      $this->assertSession()->linkByHrefExists($route . '?days=30');
      $this->assertSession()->linkNotExists('Clear search');
      $this->assertSession()->pageTextNotContains('No preparations match this search.');
    }

    // In BrowserTestBase, these protected actions use POST/redirect/GET
    // without JavaScript; both directions must preserve the active search.
    $this->drupalGet($route, ['query' => ['days' => '30', 'q' => 'due now']]);
    $this->assertSession()->elementExists(
      'xpath',
      '//div[contains(@class, "ps-preparation-row--pending")]'
      . '[.//h3[normalize-space()="Due now preparation"]]'
      . '//input[@value="Mark prepared"]',
    )->click();
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSearchContext($route, 'due now', 30);
    $this->assertSession()->pageTextContains('Preparation marked prepared.');
    $this->assertSession()->elementExists(
      'xpath',
      '//div[contains(@class, "ps-preparation-row--prepared")]'
      . '[.//h3[normalize-space()="Due now preparation"]]'
      . '//input[@value="Mark not prepared"]',
    )->click();
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSearchContext($route, 'due now', 30);
    $this->assertSession()->pageTextContains('Preparation marked not prepared.');
    $this->assertSession()->elementExists('css', 'form.ps-preparation-search input[name="q"][value="due now"]');
    $rows = $this->getSession()->getPage()->findAll('css', '#ps-preparation-live .ps-preparation-row h3');
    $visibleInstructions = array_map(static fn($row): string => trim($row->getText()), $rows);
    $this->assertSame(['Due now preparation'], $visibleInstructions, 'Non-JavaScript return must preserve filtered rows.');
    $this->assertSame($countsBefore, $this->domainCounts(), 'A reversible POST must restore completion state.');

    $this->drupalLogout();

    $this->drupalLogin($noGrant);
    $this->drupalGet($route, ['query' => ['days' => '30']]);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogout();

    $this->drupalLogin($secondGranted);
    $this->drupalGet($route, ['query' => ['days' => '30']]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Active overdue preparation');
    $this->assertSession()->pageTextContains('Long lead preparation due inside seven days');
    $this->assertSession()->pageTextContains('Preparation due on day twenty');
    $this->assertSession()->pageTextNotContains('Unauthorized H2 thirty day lead preparation');
    $this->drupalLogout();

    $this->drupalLogin($emptyGranted);
    $this->drupalGet($route);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('No upcoming preparations are assigned to you in the next 7 days.');
    $this->drupalGet($route, ['query' => ['days' => '30']]);
    $this->assertSession()->pageTextContains('No upcoming preparations are assigned to you in the next 30 days.');
    $this->assertSession()->pageTextContains('No preparation shown in this period has been marked prepared.');
    $this->assertSession()->pageTextContains('Preparations are added through activities. You can include a preparation when adding an activity.');
    $this->assertSession()->pageTextContains('Add an activity to prepare');
    $this->assertSession()->linkByHrefExists('/personal-secretary/activities/add');
    $this->assertCount(1, $this->getSession()->getPage()->findAll('css', 'a.ps-upcoming-actions__add'));
    $this->assertSame($countsBefore, $this->domainCounts(), 'Viewing the empty state must not create an activity or preparation.');
    $this->assertSession()->elementNotExists(
      'xpath',
      '//article[contains(concat(" ", normalize-space(@class), " "), " personal-secretary-preparation-item ")][.//h3[normalize-space()="Active overdue preparation"]]',
    );
    $this->drupalLogout();

    $this->drupalLogin($unlinked);
    $this->drupalGet($route);
    $this->assertRemediationOnly();
    $this->drupalLogout();

    $this->drupalLogin($stale);
    $this->drupalGet($route);
    $this->assertRemediationOnly();
    $this->drupalLogout();

    $authorized->set(HouseholdAuthorizationService::FIELD_NAME, []);
    $authorized->save();
    $this->drupalLogin($authorized);
    $this->drupalGet($route);
    $this->assertSession()->statusCodeEquals(403);

    // Reuse Core locale and this module's existing French catalog contract.
    $this->drupalLogout();
    if (\Drupal\language\Entity\ConfigurableLanguage::load('fr') === NULL) {
      \Drupal\language\Entity\ConfigurableLanguage::createFromLangcode('fr')->save();
    }
    require_once DRUPAL_ROOT . '/modules/custom/personal_secretary/personal_secretary.install';
    personal_secretary_import_french_catalog();
    $this->config('system.site')->set('default_langcode', 'fr')->save();
    $this->config('language.negotiation')
      ->set('url.source', 'path_prefix')
      ->set('url.prefixes', ['fr' => 'fr', 'en' => 'en'])
      ->set('url.domains', ['fr' => '', 'en' => ''])
      ->set('selected_langcode', 'site_default')
      ->save();
    $this->config('language.types')
      ->set('negotiation.language_interface.enabled', [
        'language-url' => 0,
        'language-user' => 1,
        'language-selected' => 2,
      ])->save();
    $this->container->get('language_manager')->reset();
    $this->drupalLogin($emptyGranted);
    $this->drupalGet('/fr/personal-secretary/preparations/mine', ['query' => ['days' => '30']]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('30 jours');
    $this->assertSession()->pageTextContains('7 jours');
    $this->assertSession()->pageTextContains('Aucun préparatif à venir ne vous est attribué dans les 30 prochains jours.');
    $this->assertSession()->pageTextContains('Aucun préparatif affiché pour cette période n’a été marqué comme prêt.');
    $this->assertSession()->pageTextContains('Les préparatifs sont associés aux activités.');
    $this->assertSession()->linkExists('Ajouter une activité à préparer');
    $this->assertSession()->linkByHrefExists('/fr/personal-secretary/activities/add');
    $this->assertCount(1, $this->getSession()->getPage()->findAll('css', 'a.ps-upcoming-actions__add'));
    $this->assertSame($countsBefore, $this->domainCounts());

    $this->drupalGet('/fr/personal-secretary/preparations/mine', [
      'query' => ['days' => '30', 'q' => 'introuvable'],
    ]);
    $this->assertSession()->pageTextContains('Rechercher un préparatif');
    $this->assertSession()->pageTextContains('Aucun préparatif ne correspond à cette recherche.');
    $this->assertSession()->linkExists('Effacer la recherche');
    $this->assertSession()->elementNotExists('css', 'a.ps-upcoming-actions__add');
    $this->assertSame($countsBefore, $this->domainCounts());
  }

  /**
   * Asserts the canonical GET query on both search and POST/PRG navigation.
   */
  private function assertSearchContext(string $path, string $search, int $days): void {
    $current = $this->getSession()->getCurrentUrl();
    $this->assertSame($path, parse_url($current, PHP_URL_PATH));
    parse_str((string) parse_url($current, PHP_URL_QUERY), $query);
    $this->assertSame((string) $days, $query['days'] ?? NULL);
    $this->assertSame($search, $query['q'] ?? NULL);
  }

  private function createSeriesWithRule(string $label, int $householdId, int $responsiblePersonId, DateTimeImmutable $start): ActivitySeries {
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $responsibilityMutations = $this->container->get('personal_secretary.responsibility_mutation');
    $end = $start->modify('+1 hour');
    $series = $domain->createActivitySeries($label, $householdId, $start, $end, 'FREQ=DAILY;COUNT=1');
    $responsibilityMutations->createResponsibilityRule($series, $responsiblePersonId, $start, $end, 'FREQ=DAILY;COUNT=1');
    return $series;
  }

  private function productUser(int|string|null $personId, array $householdIds, string $timezone): UserInterface {
    $user = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $user);
    if ($personId !== NULL) {
      $user->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $personId]);
    }
    $user->set(HouseholdAuthorizationService::FIELD_NAME, array_map(static fn($id): array => ['target_id' => (int) $id], $householdIds));
    $user->set('timezone', $timezone);
    $user->save();
    return $user;
  }

  private function itemsByInstruction(array $items): array {
    $indexed = [];
    foreach ($items as $item) {
      $indexed[(string) $item['instruction']] = $item;
    }
    return $indexed;
  }

  private function assertDueOrder(array $items): void {
    $due = array_map(static fn(array $item): string => (string) $item['due_time_iso'], $items);
    $sorted = $due;
    sort($sorted, SORT_STRING);
    $this->assertSame($sorted, $due);
  }

  private function assertRemediationOnly(): void {
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Link your account to a valid Household member to see My preparations.');
    $this->assertSession()->elementNotExists('css', 'a.ps-upcoming-actions__add');
    foreach (['Active overdue preparation', 'Long lead preparation due inside seven days', 'Preparation appears after responsibility override', 'Replacement preparation requirement', 'Unauthorized H2 thirty day lead preparation'] as $leakedText) {
      $this->assertSession()->pageTextNotContains($leakedText);
    }
  }

  private function domainCounts(): array {
    $manager = $this->container->get('entity_type.manager');
    return [
      'person' => count($manager->getStorage('personal_secretary_person')->loadMultiple()),
      'household' => count($manager->getStorage('personal_secretary_household')->loadMultiple()),
      'series' => count($manager->getStorage('personal_sec_activity_series')->loadMultiple()),
      'exceptions' => count($manager->getStorage('personal_sec_activity_exception')->loadMultiple()),
      'rules' => count($manager->getStorage('personal_sec_resp_rule')->loadMultiple()),
      'overrides' => count($manager->getStorage('personal_sec_resp_override')->loadMultiple()),
      'preparations' => count($manager->getStorage('personal_sec_prep_req')->loadMultiple()),
      'completions' => count($manager->getStorage(PreparationCompletion::ENTITY_TYPE_ID)->loadMultiple()),
    ];
  }

  private function installUserReferenceField(string $fieldName, string $targetType, int $cardinality, string $label): void {
    FieldStorageConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'user',
      'type' => 'entity_reference',
      'settings' => ['target_type' => $targetType],
      'cardinality' => $cardinality,
      'translatable' => FALSE,
    ])->save();
    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => $label,
      'required' => FALSE,
      'translatable' => FALSE,
      'settings' => ['handler' => 'default:' . $targetType, 'handler_settings' => []],
    ])->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

}
