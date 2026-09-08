<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\Household;
use Drupal\personal_secretary\Entity\Person;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\CurrentUserActivityCreationService;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use InvalidArgumentException;

/**
 * Proves bounded normal-user Add activity authority and scope isolation.
 *
 * @group personal_secretary
 */
final class AuthorizedActivityCreationTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testRouteAccessScopeFirstAndAdminIsolation(): void {
    $this->drupalGet('/personal-secretary/activities/add');
    $this->assertSession()->statusCodeEquals(403);

    $this->installUserScopeFields();

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $current = $domain->createPerson('Authorized Current Person');
    $h1Member = $domain->createPerson('Authorized H1 Member');
    $h2Secret = $domain->createPerson('Unauthorized H2 Person');
    $h1 = $domain->createHousehold(
      'Authorized Household H1',
      [(int) $current->id(), (int) $h1Member->id()],
    );
    $h2 = $domain->createHousehold(
      'Unauthorized Household H2',
      [(int) $current->id(), (int) $h2Secret->id()],
    );
    $memberlessForCurrent = $domain->createHousehold(
      'Granted but not member Household',
      [(int) $h1Member->id()],
    );

    $withoutPermission = $this->createScopedUser($current, [(int) $h1->id()], FALSE);
    $this->drupalLogin($withoutPermission);
    $this->drupalGet('/personal-secretary/activities/add');
    $this->assertSession()->statusCodeEquals(403);

    $withoutGrant = $this->createScopedUser($current, [], TRUE);
    $this->drupalLogout();
    $this->drupalLogin($withoutGrant);
    $this->drupalGet('/personal-secretary/activities/add');
    $this->assertSession()->statusCodeEquals(403);

    $authorized = $this->createScopedUser($current, [(int) $h1->id()], TRUE);
    $this->drupalLogout();
    $this->drupalLogin($authorized);
    $this->drupalGet('/personal-secretary/activities/add');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Authorized Household H1');
    $this->assertSession()->pageTextNotContains('Unauthorized Household H2');
    $this->assertSession()->pageTextContains('Authorized H1 Member');
    $this->assertSession()->pageTextNotContains('Unauthorized H2 Person');
    $this->assertSession()->elementExists(
      'css',
      'select[name="household_id"] option[value="' . $h1->id() . '"]',
    );
    $this->assertSession()->elementNotExists(
      'css',
      'select[name="household_id"] option[value="' . $h2->id() . '"]',
    );
    $this->assertSession()->elementExists(
      'css',
      'input[name="concerned_person_ids[' . $h1Member->id() . ']"]',
    );
    $this->assertSession()->elementNotExists(
      'css',
      'input[name="concerned_person_ids[' . $h2Secret->id() . ']"]',
    );
    $this->assertSession()->elementNotExists('css', 'select[name="responsible_person_id"]');
    $this->assertSession()->elementExists('css', 'input[type="hidden"][name="responsible_person_id"]');

    foreach ([
      '/personal-secretary/setup',
      '/personal-secretary/households/members/add',
      '/personal-secretary/households/members/rename',
      '/personal-secretary/activities/1/schedule/edit',
      '/personal-secretary/activities/1/responsibility/edit',
      '/personal-secretary/activities/1/time-commitment/edit',
      '/personal-secretary/activities/1/pause',
      '/personal-secretary/activities/1/occurrences/synthetic-key/responsibility',
      '/personal-secretary/activities/1/occurrences/synthetic-key/reschedule',
      '/personal-secretary/activities/1/occurrences/synthetic-key/cancel',
    ] as $adminOnlyPath) {
      $this->drupalGet($adminOnlyPath);
      $this->assertSession()->statusCodeEquals(403);
    }

    $notMember = $this->createScopedUser(
      $current,
      [(int) $memberlessForCurrent->id()],
      TRUE,
    );
    $this->drupalLogout();
    $this->drupalLogin($notMember);
    $this->drupalGet('/personal-secretary/activities/add');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Activity creation requires a current linked Person');
    $this->assertSession()->fieldNotExists('activity_label');
    $this->assertSession()->pageTextNotContains('Granted but not member Household');

    $unlinked = $this->createScopedUser(NULL, [(int) $h1->id()], TRUE);
    $this->drupalLogout();
    $this->drupalLogin($unlinked);
    $this->drupalGet('/personal-secretary/activities/add');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Activity creation requires a current linked Person');
    $this->assertSession()->fieldNotExists('activity_label');
    $this->assertSession()->pageTextNotContains('Authorized Household H1');

    $admin = $this->drupalCreateUser([HouseholdAuthorizationService::ADMIN_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $admin);
    $this->drupalLogout();
    $this->drupalLogin($admin);
    $this->drupalGet('/personal-secretary/activities/add');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', 'select[name="responsible_person_id"]');
  }

  public function testOrdinaryWeeklyAndOneOffCreationReuseExistingSemantics(): void {
    $this->installUserScopeFields();

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $current = $domain->createPerson('Creation Current Person');
    $concerned = $domain->createPerson('Creation Concerned Person');
    $household = $domain->createHousehold(
      'Creation Household',
      [(int) $current->id(), (int) $concerned->id()],
    );

    $user = $this->createScopedUser($current, [(int) $household->id()], TRUE);
    $this->drupalLogin($user);

    $timezone = new DateTimeZone('Europe/Brussels');
    $nowUtc = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone(new DateTimeZone('UTC'));
    $nowLocal = $nowUtc->setTimezone($timezone);

    $weeklyDate = $nowLocal->modify('+2 days')->format('Y-m-d');
    $this->drupalGet('/personal-secretary/activities/add');
    // This hidden value is intentionally forged. It is not mutation authority.
    $hiddenResponsible = $this->getSession()
      ->getPage()
      ->find(
        'css',
        'input[type="hidden"][name="responsible_person_id"]',
      );
    $this->assertNotNull($hiddenResponsible);
    $hiddenResponsible->setValue((string) $concerned->id());

    $this->submitForm([
      'household_id' => (string) $household->id(),
      'activity_type' => 'weekly',
      'time_mode' => ActivitySeries::TIME_MODE_TIMED,
      'concerned_person_ids[' . $concerned->id() . ']' => (string) $concerned->id(),
      'activity_label' => 'Authorized weekly creation',
      'location' => 'Synthetic authorized location',
      'first_occurrence_date' => $weeklyDate,
      'start_local_time' => '09:00',
      'end_local_time' => '10:00',
      'source_timezone' => 'Europe/Brussels',
      'preparation_instruction' => 'Prepare authorized synthetic kit',
      'preparation_lead_minutes' => '45',
    ], 'Add activity');

    $this->assertSession()->addressEquals('/personal-secretary/upcoming/mine');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Authorized weekly creation');
    $this->assertSession()->pageTextContains('Synthetic authorized location');
    $this->assertSession()->pageTextContains('Prepare authorized synthetic kit');

    $weekly = $this->seriesByLabel('Authorized weekly creation');
    $this->assertSame((int) $household->id(), (int) $weekly->get('household')->target_id);
    $this->assertSame('Synthetic authorized location', (string) $weekly->get('location')->value);
    $this->assertSame(ActivitySeries::TIME_MODE_TIMED, $weekly->timeMode());
    $this->assertSame([(int) $concerned->id()], $this->concernedIds($weekly));
    $this->assertSame('FREQ=WEEKLY;INTERVAL=1', $weekly->get('recurrence')->first()?->getValue()['rrule'] ?? NULL);
    $this->assertSeriesResponsibleTo($weekly, (int) $current->id(), $nowUtc);

    $oneOffDate = $nowLocal->modify('+3 days')->format('Y-m-d');
    $this->drupalGet('/personal-secretary/activities/add');
    $hiddenResponsible = $this->getSession()
      ->getPage()
      ->find(
        'css',
        'input[type="hidden"][name="responsible_person_id"]',
      );
    $this->assertNotNull($hiddenResponsible);
    $hiddenResponsible->setValue((string) $concerned->id());

    $this->submitForm([
      'household_id' => (string) $household->id(),
      'activity_type' => 'one_off',
      'time_mode' => ActivitySeries::TIME_MODE_TIMED,
      'activity_label' => 'Authorized one-off creation',
      'first_occurrence_date' => $oneOffDate,
      'start_local_time' => '14:00',
      'end_local_time' => '15:00',
      'source_timezone' => 'Europe/Brussels',
      'preparation_instruction' => '',
      'preparation_lead_minutes' => '0',
    ], 'Add activity');

    $this->assertSession()->addressEquals('/personal-secretary/upcoming/mine');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Authorized weekly creation');
    $this->assertSession()->pageTextContains('Authorized one-off creation');

    $oneOff = $this->seriesByLabel('Authorized one-off creation');
    $this->assertSame([], $this->concernedIds($oneOff));
    $this->assertSame('FREQ=DAILY;COUNT=1', $oneOff->get('recurrence')->first()?->getValue()['rrule'] ?? NULL);
    $this->assertSeriesResponsibleTo($oneOff, (int) $current->id(), $nowUtc);

    $this->assertCount(
      1,
      $this->container->get('entity_type.manager')->getStorage('personal_sec_prep_req')->loadMultiple(),
      'The focused weekly composition reuses the existing optional preparation path without duplicating its matrix.',
    );
  }

  public function testFreshAuthorityRejectsForgeryAndRacesBeforeMutation(): void {
    $this->installUserScopeFields();

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\CurrentUserActivityCreationService $creation */
    $creation = $this->container->get('personal_secretary.current_user_activity_creation');
    $this->assertInstanceOf(CurrentUserActivityCreationService::class, $creation);

    $current = $domain->createPerson('Race Current Person');
    $member = $domain->createPerson('Race Household Member');
    $outsider = $domain->createPerson('Race Outside Person');
    $h1 = $domain->createHousehold(
      'Race Authorized Household',
      [(int) $current->id(), (int) $member->id()],
    );
    $h2 = $domain->createHousehold(
      'Race Unauthorized Household',
      [(int) $outsider->id()],
    );
    $user = $this->createScopedUser($current, [(int) $h1->id()], TRUE);

    $timezone = new DateTimeZone('Europe/Brussels');
    $start = new DateTimeImmutable('2026-09-10 09:00:00', $timezone);
    $end = $start->modify('+1 hour');
    $counts = $this->creationCounts();

    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($user);
    try {
      $scope = $creation->creationScope();
      $renderedPersonId = (int) $scope['current_person']->id();

      $this->assertRejectedWithoutCreation(
        fn() => $creation->addOneOffActivity(
          $renderedPersonId,
          (int) $h2->id(),
          'Forged Household activity',
          $start,
          $end,
        ),
        $counts,
      );

      $this->assertRejectedWithoutCreation(
        fn() => $creation->addOneOffActivity(
          $renderedPersonId,
          (int) $h1->id(),
          'Forged concerned Person activity',
          $start,
          $end,
          '',
          0,
          '',
          [(int) $outsider->id()],
        ),
        $counts,
      );

      // Grant revoked after render.
      $scope = $creation->creationScope();
      $renderedPersonId = (int) $scope['current_person']->id();
      $persistedUser = $this->reloadUser((int) $user->id());
      $persistedUser->set(HouseholdAuthorizationService::FIELD_NAME, []);
      $persistedUser->save();
      $this->assertRejectedWithoutCreation(
        fn() => $creation->addWeeklyActivity(
          $renderedPersonId,
          (int) $h1->id(),
          'Revoked grant activity',
          $start,
          $end,
        ),
        $counts,
      );
      $persistedUser = $this->reloadUser((int) $user->id());
      $persistedUser->set(HouseholdAuthorizationService::FIELD_NAME, [['target_id' => (int) $h1->id()]]);
      $persistedUser->save();

      // CurrentPerson relink after render.
      $scope = $creation->creationScope();
      $renderedPersonId = (int) $scope['current_person']->id();
      $persistedUser = $this->reloadUser((int) $user->id());
      $persistedUser->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $member->id()]);
      $persistedUser->save();
      $this->assertRejectedWithoutCreation(
        fn() => $creation->addWeeklyActivity(
          $renderedPersonId,
          (int) $h1->id(),
          'Relinked CurrentPerson activity',
          $start,
          $end,
        ),
        $counts,
      );
      $persistedUser = $this->reloadUser((int) $user->id());
      $persistedUser->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $current->id()]);
      $persistedUser->save();

      // CurrentPerson removed from Household after render.
      $scope = $creation->creationScope();
      $renderedPersonId = (int) $scope['current_person']->id();
      $persistedH1 = $this->reloadHousehold((int) $h1->id());
      $persistedH1->set('members', [['target_id' => (int) $member->id()]]);
      $persistedH1->save();
      $this->assertRejectedWithoutCreation(
        fn() => $creation->addWeeklyActivity(
          $renderedPersonId,
          (int) $h1->id(),
          'Removed CurrentPerson activity',
          $start,
          $end,
        ),
        $counts,
      );
      $persistedH1 = $this->reloadHousehold((int) $h1->id());
      $persistedH1->set('members', [
        ['target_id' => (int) $current->id()],
        ['target_id' => (int) $member->id()],
      ]);
      $persistedH1->save();

      // Concerned Person removed from Household after render.
      $scope = $creation->creationScope();
      $renderedPersonId = (int) $scope['current_person']->id();
      $persistedH1 = $this->reloadHousehold((int) $h1->id());
      $persistedH1->set('members', [['target_id' => (int) $current->id()]]);
      $persistedH1->save();
      $this->assertRejectedWithoutCreation(
        fn() => $creation->addWeeklyActivity(
          $renderedPersonId,
          (int) $h1->id(),
          'Removed concerned Person activity',
          $start,
          $end,
          '',
          0,
          '',
          [(int) $member->id()],
        ),
        $counts,
      );

      // CurrentPerson unlinked after render.
      $persistedH1 = $this->reloadHousehold((int) $h1->id());
      $persistedH1->set('members', [
        ['target_id' => (int) $current->id()],
        ['target_id' => (int) $member->id()],
      ]);
      $persistedH1->save();
      $scope = $creation->creationScope();
      $renderedPersonId = (int) $scope['current_person']->id();
      $persistedUser = $this->reloadUser((int) $user->id());
      $persistedUser->set(CurrentPersonResolver::FIELD_NAME, []);
      $persistedUser->save();
      $this->assertRejectedWithoutCreation(
        fn() => $creation->addWeeklyActivity(
          $renderedPersonId,
          (int) $h1->id(),
          'Unlinked CurrentPerson activity',
          $start,
          $end,
        ),
        $counts,
      );

      // User blocked after render.
      $persistedUser = $this->reloadUser((int) $user->id());
      $persistedUser->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $current->id()]);
      $persistedUser->activate();
      $persistedUser->save();
      $scope = $creation->creationScope();
      $renderedPersonId = (int) $scope['current_person']->id();
      $persistedUser = $this->reloadUser((int) $user->id());
      $persistedUser->block();
      $persistedUser->save();
      $this->assertRejectedWithoutCreation(
        fn() => $creation->addWeeklyActivity(
          $renderedPersonId,
          (int) $h1->id(),
          'Blocked User activity',
          $start,
          $end,
        ),
        $counts,
      );
    }
    finally {
      $switcher->switchBack();
    }
  }

  private function createScopedUser(?Person $person, array $householdIds, bool $withPermission): UserInterface {
    $permissions = $withPermission ? [HouseholdAuthorizationService::PRODUCT_USE_PERMISSION] : [];
    $user = $this->drupalCreateUser($permissions);
    $this->assertInstanceOf(UserInterface::class, $user);
    if ($person instanceof Person) {
      $user->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $person->id()]);
    }
    $grants = array_map(
      static fn(int $householdId): array => ['target_id' => $householdId],
      $householdIds,
    );
    $user->set(HouseholdAuthorizationService::FIELD_NAME, $grants);
    $user->save();
    return $user;
  }

  private function seriesByLabel(string $label): ActivitySeries {
    foreach ($this->container->get('entity_type.manager')->getStorage('personal_sec_activity_series')->loadMultiple() as $series) {
      if ($series instanceof ActivitySeries && $series->label() === $label) {
        return $series;
      }
    }
    throw new \RuntimeException(sprintf('ActivitySeries %s was not found.', $label));
  }

  /**
   * @return int[]
   */
  private function concernedIds(ActivitySeries $series): array {
    $ids = array_map(
      static fn(array $item): int => (int) ($item['target_id'] ?? 0),
      $series->get('concerned_persons')->getValue(),
    );
    sort($ids, SORT_NUMERIC);
    return $ids;
  }

  private function assertSeriesResponsibleTo(ActivitySeries $series, int $personId, DateTimeImmutable $nowUtc): void {
    $occurrences = $this->container
      ->get('personal_secretary.effective_occurrence_projection')
      ->project($series, $nowUtc, $nowUtc->modify('+7 days'));
    $this->assertNotEmpty($occurrences);
    $responsibility = $this->container
      ->get('personal_secretary.effective_responsibility')
      ->resolve($series, $occurrences[0]);
    $this->assertSame($personId, $responsibility->responsiblePersonId);
  }

  /**
   * @return array<string, int>
   */
  private function creationCounts(): array {
    $manager = $this->container->get('entity_type.manager');
    return [
      'series' => count($manager->getStorage('personal_sec_activity_series')->loadMultiple()),
      'rules' => count($manager->getStorage('personal_sec_resp_rule')->loadMultiple()),
      'overrides' => count($manager->getStorage('personal_sec_resp_override')->loadMultiple()),
      'preparations' => count($manager->getStorage('personal_sec_prep_req')->loadMultiple()),
    ];
  }

  private function assertRejectedWithoutCreation(callable $operation, array $expectedCounts): void {
    try {
      $operation();
      $this->fail('Unauthorized or stale activity creation must fail closed.');
    }
    catch (InvalidArgumentException) {
      // Expected.
    }
    $this->assertSame($expectedCounts, $this->creationCounts());
  }

  private function reloadUser(int $uid): UserInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    $storage->resetCache([$uid]);
    $user = $storage->load($uid);
    $this->assertInstanceOf(UserInterface::class, $user);
    return $user;
  }

  private function reloadHousehold(int $householdId): Household {
    $storage = $this->container->get('entity_type.manager')->getStorage('personal_secretary_household');
    $storage->resetCache([$householdId]);
    $household = $storage->load($householdId);
    $this->assertInstanceOf(Household::class, $household);
    return $household;
  }

  private function installUserScopeFields(): void {
    FieldStorageConfig::create([
      'field_name' => CurrentPersonResolver::FIELD_NAME,
      'entity_type' => 'user',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'personal_secretary_person'],
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

    FieldStorageConfig::create([
      'field_name' => HouseholdAuthorizationService::FIELD_NAME,
      'entity_type' => 'user',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'personal_secretary_household'],
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
      'translatable' => FALSE,
    ])->save();
    FieldConfig::create([
      'field_name' => HouseholdAuthorizationService::FIELD_NAME,
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Personal Secretary Households',
      'required' => FALSE,
      'translatable' => FALSE,
      'settings' => [
        'handler' => 'default:personal_secretary_household',
        'handler_settings' => [],
      ],
    ])->save();

    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

}
