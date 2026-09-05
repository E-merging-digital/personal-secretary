<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\personal_secretary\Entity\ActivitySeries;
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
    $h1 = $domain->createHousehold(
      'Preparation authorized household',
      [(int) $person->id(), (int) $otherPerson->id()],
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
    foreach (['Active overdue preparation', 'Due now preparation', 'Long lead preparation due inside seven days', 'Preparation removed by cancel', 'Preparation moved out by reschedule', 'Preparation disappears after responsibility override', 'Old preparation requirement'] as $expected) {
      $this->assertArrayHasKey($expected, $beforeItems);
    }
    foreach (['Preparation due exactly at window end', 'Preparation due after seven days', 'Preparation for already started activity', 'Unauthorized H2 thirty day lead preparation', 'Other person preparation', 'Preparation appears after responsibility override'] as $excluded) {
      $this->assertArrayNotHasKey($excluded, $beforeItems);
    }
    $this->assertTrue($beforeItems['Active overdue preparation']['overdue']);
    $this->assertFalse($beforeItems['Due now preparation']['overdue']);
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

    $accountSwitcher->switchTo($authorized);
    try {
      $after = $preparations->mine($fixedNow);
    }
    finally {
      $accountSwitcher->switchBack();
    }

    $afterItems = $this->itemsByInstruction($after['items']);
    foreach (['Active overdue preparation', 'Due now preparation', 'Long lead preparation due inside seven days', 'Preparation appears after responsibility override', 'Replacement preparation requirement'] as $expected) {
      $this->assertArrayHasKey($expected, $afterItems);
    }
    foreach (['Preparation removed by cancel', 'Preparation moved out by reschedule', 'Preparation disappears after responsibility override', 'Old preparation requirement', 'Unauthorized H2 thirty day lead preparation', 'Preparation due exactly at window end', 'Preparation due after seven days', 'Preparation for already started activity'] as $excluded) {
      $this->assertArrayNotHasKey($excluded, $afterItems);
    }
    $this->assertSame('Europe/Brussels', $after['timezone']);
    $this->assertDueOrder($after['items']);

    $accountSwitcher->switchTo($fallback);
    try {
      $fallbackModel = $preparations->mine($fixedNow);
    }
    finally {
      $accountSwitcher->switchBack();
    }
    $this->assertSame('Europe/Brussels', $fallbackModel['timezone']);

    $this->drupalLogin($authorized);
    $countsBefore = $this->domainCounts();
    $this->drupalGet($route);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Active overdue preparation');
    $this->assertSession()->pageTextContains('Long lead preparation due inside seven days');
    $this->assertSession()->pageTextContains('Preparation appears after responsibility override');
    $this->assertSession()->pageTextContains('Replacement preparation requirement');
    $this->assertSession()->pageTextContains('Overdue');
    $this->assertSession()->pageTextContains('Europe/Brussels');
    $this->assertSession()->pageTextNotContains('Unauthorized H2 thirty day lead preparation');
    $this->assertSession()->pageTextNotContains('Preparation removed by cancel');
    $this->assertSession()->pageTextNotContains('Preparation moved out by reschedule');
    $this->assertSame($countsBefore, $this->domainCounts());
    $this->drupalLogout();

    $this->drupalLogin($noGrant);
    $this->drupalGet($route);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogout();

    $this->drupalLogin($secondGranted);
    $this->drupalGet($route);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Active overdue preparation');
    $this->assertSession()->pageTextContains('Long lead preparation due inside seven days');
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
