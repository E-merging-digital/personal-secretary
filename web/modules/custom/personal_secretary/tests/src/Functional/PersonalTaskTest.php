<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\personal_secretary\Entity\PersonalTask;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\personal_secretary\Service\PersonalTaskMutationService;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use InvalidArgumentException;

/**
 * Proves the first standalone PersonalTask vertical.
 *
 * @group personal_secretary
 */
final class PersonalTaskTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testEntityDueLifecycleAndTimezone(): void {
    $this->installUserPersonFieldViaEntityApi();
    $this->installHouseholdAuthorizationFieldViaEntityApi();

    $entityType = $this->container->get('entity_type.manager')->getDefinition(PersonalTask::ENTITY_TYPE_ID);
    $this->assertFalse($entityType->isRevisionable());
    $this->assertSame('personal_secretary_task', $entityType->getBaseTable());

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\PersonalTaskMutationService $tasks */
    $tasks = $this->container->get('personal_secretary.personal_task_mutation');

    $person = $domain->createPerson('Synthetic task owner');
    $household = $domain->createHousehold('Synthetic task household', [(int) $person->id()]);
    $user = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $user);
    $user->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $person->id()]);
    $user->set(HouseholdAuthorizationService::FIELD_NAME, [['target_id' => (int) $household->id()]]);
    $user->set('timezone', 'Europe/Brussels');
    $user->save();

    $accountSwitcher = $this->container->get('account_switcher');
    $accountSwitcher->switchTo($user);
    try {
      $undated = $tasks->createTask('  Buy synthetic batteries  ', (int) $household->id(), PersonalTask::DUE_NONE);
      $this->assertSame('Buy synthetic batteries', (string) $undated->label());
      $this->assertTrue($undated->get('due_date')->isEmpty());
      $this->assertTrue($undated->get('due_at')->isEmpty());
      $this->assertSame((int) $person->id(), (int) $undated->get('assigned_person')->target_id);

      $dateOnly = $tasks->createTask(
        'Send synthetic document',
        (int) $household->id(),
        PersonalTask::DUE_DATE,
        '2026-03-29',
      );
      $this->assertSame('2026-03-29', (string) $dateOnly->get('due_date')->value);
      $this->assertTrue($dateOnly->get('due_at')->isEmpty());

      $dateTime = $tasks->createTask(
        'Call synthetic dentist',
        (int) $household->id(),
        PersonalTask::DUE_DATE_TIME,
        NULL,
        '2026-03-29 12:30',
      );
      $this->assertSame('2026-03-29T10:30:00', (string) $dateTime->get('due_at')->value);
      $this->assertTrue($dateTime->get('due_date')->isEmpty());
      $this->assertSame('2026-03-29 12:30', $tasks->dueAtAsLocalInput($dateTime));

      try {
        $tasks->createTask(
          'Synthetic nonexistent DST time',
          (int) $household->id(),
          PersonalTask::DUE_DATE_TIME,
          NULL,
          '2026-03-29 02:30',
        );
        $this->fail('A nonexistent local DST time must fail closed.');
      }
      catch (InvalidArgumentException) {
        // Expected.
      }

      $this->assertTrue($tasks->completeTask((int) $undated->id()));
      $storage = $this->container->get('entity_type.manager')->getStorage(PersonalTask::ENTITY_TYPE_ID);
      $storage->resetCache([(int) $undated->id()]);
      $completed = $storage->load($undated->id());
      $this->assertInstanceOf(PersonalTask::class, $completed);
      $completedAt = (int) $completed->get('completed_at')->value;
      $completedBy = (int) $completed->get('completed_by_user')->target_id;
      $this->assertSame(PersonalTask::STATUS_COMPLETED, (string) $completed->get('status')->value);
      $this->assertSame((int) $user->id(), $completedBy);

      $this->assertFalse($tasks->completeTask((int) $undated->id()), 'Duplicate completion is a deterministic no-op.');
      $storage->resetCache([(int) $undated->id()]);
      $completedAgain = $storage->load($undated->id());
      $this->assertInstanceOf(PersonalTask::class, $completedAgain);
      $this->assertSame($completedAt, (int) $completedAgain->get('completed_at')->value);
      $this->assertSame($completedBy, (int) $completedAgain->get('completed_by_user')->target_id);

      $this->assertTrue($tasks->reopenTask((int) $undated->id()));
      $storage->resetCache([(int) $undated->id()]);
      $reopened = $storage->load($undated->id());
      $this->assertInstanceOf(PersonalTask::class, $reopened);
      $this->assertSame(PersonalTask::STATUS_OPEN, (string) $reopened->get('status')->value);
      $this->assertTrue($reopened->get('completed_at')->isEmpty());
      $this->assertTrue($reopened->get('completed_by_user')->isEmpty());
      $this->assertFalse($tasks->reopenTask((int) $undated->id()));
    }
    finally {
      $accountSwitcher->switchBack();
    }

    $invalid = $this->container->get('entity_type.manager')->getStorage(PersonalTask::ENTITY_TYPE_ID)->create([
      'title' => 'Synthetic invalid due state',
      'household' => ['target_id' => (int) $household->id()],
      'assigned_person' => ['target_id' => (int) $person->id()],
      'due_mode' => PersonalTask::DUE_NONE,
      'due_date' => '2026-04-01',
      'status' => PersonalTask::STATUS_OPEN,
    ]);
    try {
      $invalid->save();
      $this->fail('Inconsistent due storage must be rejected by the entity invariant.');
    }
    catch (EntityStorageException) {
      // Expected.
    }
  }

  public function testAuthorizedScopeFirstAndMultipleUsers(): void {
    $this->installUserPersonFieldViaEntityApi();
    $this->installHouseholdAuthorizationFieldViaEntityApi();

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\PersonalTaskMutationService $tasks */
    $tasks = $this->container->get('personal_secretary.personal_task_mutation');

    $person = $domain->createPerson('Synthetic shared task person');
    $otherPerson = $domain->createPerson('Synthetic other task person');
    $h1 = $domain->createHousehold('Synthetic task H1', [(int) $person->id(), (int) $otherPerson->id()]);
    $h2 = $domain->createHousehold('Synthetic task H2', [(int) $person->id(), (int) $otherPerson->id()]);
    $h3 = $domain->createHousehold('Synthetic task H3 other member only', [(int) $otherPerson->id()]);

    $userA = $this->taskUser($person->id(), [$h1->id()]);
    $userB = $this->taskUser($person->id(), []);
    $userC = $this->taskUser($person->id(), [$h1->id()]);
    $userNoMembership = $this->taskUser($person->id(), [$h3->id()]);
    $admin = $this->drupalCreateUser([HouseholdAuthorizationService::ADMIN_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $admin);
    $admin->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $person->id()]);
    $admin->save();

    $h1Task = $this->asUser($userA, fn (): PersonalTask => $tasks->createTask(
      'H1 synthetic task',
      (int) $h1->id(),
      PersonalTask::DUE_NONE,
    ));
    $this->asUser($admin, fn (): PersonalTask => $tasks->createTask(
      'H2 confidential synthetic task',
      (int) $h2->id(),
      PersonalTask::DUE_NONE,
    ));

    $this->drupalLogin($userA);
    $this->drupalGet('/personal-secretary/tasks/mine');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('H1 synthetic task');
    $this->assertSession()->pageTextNotContains('H2 confidential synthetic task');

    $this->drupalLogout();
    $this->drupalLogin($userB);
    $this->drupalGet('/personal-secretary/tasks/mine');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogout();
    $this->drupalLogin($userC);
    $this->drupalGet('/personal-secretary/tasks/mine');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('H1 synthetic task');

    $this->drupalLogout();
    $this->drupalLogin($userNoMembership);
    $this->drupalGet('/personal-secretary/tasks/add');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('You need an authorized Household containing your linked Household member');
    $this->assertSession()->buttonNotExists('Add task');
    try {
      $this->asUser($userNoMembership, fn (): PersonalTask => $tasks->createTask(
        'Must not be created',
        (int) $h3->id(),
        PersonalTask::DUE_NONE,
      ));
      $this->fail('Authorized Household without CurrentPerson membership must fail creation.');
    }
    catch (InvalidArgumentException) {
      // Expected.
    }

    try {
      $this->asUser($userA, fn (): PersonalTask => $tasks->createTask(
        'Forged H2 task',
        (int) $h2->id(),
        PersonalTask::DUE_NONE,
      ));
      $this->fail('Forged unauthorized Household must fail creation.');
    }
    catch (InvalidArgumentException) {
      // Expected.
    }

    $grantBeforeRelink = $this->grantIds($userC);
    $userC->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $otherPerson->id()]);
    $userC->save();
    $this->assertSame($grantBeforeRelink, $this->grantIds($userC), 'User -> Person relink must not mutate grants.');
    $this->drupalLogout();
    $this->drupalLogin($userC);
    $this->drupalGet('/personal-secretary/tasks/mine');
    $this->assertSession()->pageTextNotContains('H1 synthetic task');

    $userA->set(HouseholdAuthorizationService::FIELD_NAME, []);
    $userA->save();
    $this->drupalLogout();
    $this->drupalLogin($userA);
    $this->drupalGet('/personal-secretary/tasks/mine');
    $this->assertSession()->statusCodeEquals(403);

    $this->assertInstanceOf(PersonalTask::class, $h1Task);
  }

  public function testOpenEditCompleteReopenAndDeleteRoutes(): void {
    $this->installUserPersonFieldViaEntityApi();
    $this->installHouseholdAuthorizationFieldViaEntityApi();

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\PersonalTaskMutationService $tasks */
    $tasks = $this->container->get('personal_secretary.personal_task_mutation');

    $person = $domain->createPerson('Synthetic lifecycle person');
    $household = $domain->createHousehold('Synthetic lifecycle Household', [(int) $person->id()]);
    $user = $this->taskUser($person->id(), [$household->id()]);
    $task = $this->asUser($user, fn (): PersonalTask => $tasks->createTask(
      'Synthetic task before edit',
      (int) $household->id(),
      PersonalTask::DUE_NONE,
    ));

    $taskId = (int) $task->id();
    $storage = $this->container->get('entity_type.manager')->getStorage(PersonalTask::ENTITY_TYPE_ID);

    $this->drupalLogin($user);
    $this->drupalGet('/personal-secretary/tasks/' . $taskId . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([
      'title' => 'Synthetic task after edit',
      'due_mode' => PersonalTask::DUE_NONE,
    ], 'Save task');
    $this->assertSession()->pageTextContains('Synthetic task after edit');

    $this->drupalGet('/personal-secretary/tasks/' . $taskId . '/complete');
    $this->assertSession()->statusCodeEquals(200);
    $storage->resetCache([$taskId]);
    $beforeSubmit = $storage->load($taskId);
    $this->assertInstanceOf(PersonalTask::class, $beforeSubmit);
    $this->assertSame(PersonalTask::STATUS_OPEN, (string) $beforeSubmit->get('status')->value, 'GET must not complete a task.');

    $this->submitForm([], 'Mark complete');
    $this->assertSession()->pageTextContains('Completed');
    $this->assertSession()->linkExists('Reopen task');

    $this->drupalGet('/personal-secretary/tasks/mine');
    $this->assertSession()->pageTextNotContains('Synthetic task after edit');

    $this->drupalGet('/personal-secretary/tasks/' . $taskId . '/edit');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalGet('/personal-secretary/tasks/' . $taskId . '/reopen');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([], 'Reopen task');
    $this->assertSession()->pageTextContains('Synthetic task after edit');

    $this->drupalGet('/personal-secretary/tasks/' . $taskId . '/delete');
    $this->assertSession()->statusCodeEquals(200);
    $storage->resetCache([$taskId]);
    $this->assertInstanceOf(PersonalTask::class, $storage->load($taskId), 'GET must not delete a task.');
    $this->submitForm([], 'Delete task');
    $storage->resetCache([$taskId]);
    $this->assertNull($storage->load($taskId));
  }

  public function testExistingInstallUpdatePreservesDomainAndGrants(): void {
    $this->installUserPersonFieldViaEntityApi();
    $this->installHouseholdAuthorizationFieldViaEntityApi();

    $entityTypeManager = $this->container->get('entity_type.manager');
    $taskDefinition = $entityTypeManager->getDefinition(PersonalTask::ENTITY_TYPE_ID);
    $this->container->get('entity.definition_update_manager')->uninstallEntityType($taskDefinition);

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $person = $domain->createPerson('Existing synthetic task-update person');
    $household = $domain->createHousehold('Existing synthetic task-update Household', [(int) $person->id()]);
    $start = new DateTimeImmutable('2026-09-08 09:00:00', new DateTimeZone('Europe/Brussels'));
    $series = $domain->createActivitySeries(
      'Existing synthetic activity',
      (int) $household->id(),
      $start,
      $start->modify('+1 hour'),
      'FREQ=DAILY;COUNT=1',
    );
    $user = $this->taskUser($person->id(), [$household->id()]);

    $snapshot = [
      'person_uuid' => $person->uuid(),
      'household_uuid' => $household->uuid(),
      'series_uuid' => $series->uuid(),
      'grants' => $this->grantIds($user),
    ];

    $updateManager = $this->container->get('entity.definition_update_manager');
    $this->assertNull($updateManager->getEntityType(PersonalTask::ENTITY_TYPE_ID));
    $this->container->get('module_handler')->loadInclude('personal_secretary', 'install');
    $this->assertSame(
      'Installed the PersonalTask fieldable entity type with zero task backfill.',
      personal_secretary_update_11002(),
    );

    $entityTypeManager->clearCachedDefinitions();
    $taskStorage = $entityTypeManager->getStorage(PersonalTask::ENTITY_TYPE_ID);
    $this->assertSame(0, (int) $taskStorage->getQuery()->accessCheck(FALSE)->count()->execute());

    $persistedPerson = $entityTypeManager->getStorage('personal_secretary_person')->load($person->id());
    $persistedHousehold = $entityTypeManager->getStorage('personal_secretary_household')->load($household->id());
    $persistedSeries = $entityTypeManager->getStorage('personal_sec_activity_series')->load($series->id());
    $userStorage = $entityTypeManager->getStorage('user');
    $userStorage->resetCache([(int) $user->id()]);
    $persistedUser = $userStorage->load($user->id());

    $this->assertSame($snapshot['person_uuid'], $persistedPerson?->uuid());
    $this->assertSame($snapshot['household_uuid'], $persistedHousehold?->uuid());
    $this->assertSame($snapshot['series_uuid'], $persistedSeries?->uuid());
    $this->assertInstanceOf(UserInterface::class, $persistedUser);
    $this->assertSame($snapshot['grants'], $this->grantIds($persistedUser));
  }

  private function taskUser(int|string|null $personId, array $householdIds): UserInterface {
    $user = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $user);
    $user->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $personId]);
    $user->set(HouseholdAuthorizationService::FIELD_NAME, array_map(
      static fn ($id): array => ['target_id' => (int) $id],
      $householdIds,
    ));
    $user->save();
    return $user;
  }

  private function asUser(UserInterface $user, callable $callback): mixed {
    $accountSwitcher = $this->container->get('account_switcher');
    $accountSwitcher->switchTo($user);
    try {
      return $callback();
    }
    finally {
      $accountSwitcher->switchBack();
    }
  }

  private function installUserPersonFieldViaEntityApi(): void {
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
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  private function installHouseholdAuthorizationFieldViaEntityApi(): void {
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

  /** @return int[] */
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
