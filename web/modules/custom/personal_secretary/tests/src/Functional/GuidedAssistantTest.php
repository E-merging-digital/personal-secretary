<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\personal_secretary\Entity\PersonalTask;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Exercises read-only previews and explicit governed task confirmation.
 *
 * @group personal_secretary
 */
#[RunTestsInSeparateProcesses]
final class GuidedAssistantTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];
  protected $defaultTheme = 'olivero';

  protected function setUp(): void {
    parent::setUp();
    $this->installReference(CurrentPersonResolver::FIELD_NAME, 'personal_secretary_person', 1);
    $this->installReference(HouseholdAuthorizationService::FIELD_NAME, 'personal_secretary_household', FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
  }

  public function testProtectedTodayGuidanceThreeIntentsAndNoWrite(): void {
    $route = '/personal-secretary/assistant';
    $this->drupalGet($route);
    $this->assertSession()->statusCodeEquals(403);

    $person = $this->container->get('personal_secretary.domain_mutation')->createPerson('Synthetic Guided Reader');
    $h1 = $this->container->get('personal_secretary.domain_mutation')->createHousehold('Synthetic allowed', [(int) $person->id()]);
    $h2 = $this->container->get('personal_secretary.domain_mutation')->createHousehold('Synthetic disallowed', [(int) $person->id()]);
    $authorized = $this->productUser($person, [$h1]);
    $unlinked = $this->productUser(NULL, [$h1]);
    $withoutGrant = $this->productUser($person, []);
    $ordinary = $this->drupalCreateUser([]);

    $now = $this->container->get('datetime.time')->getCurrentTime();
    $localDate = (new \DateTimeImmutable('@' . $now))
      ->setTimezone(new \DateTimeZone('Europe/Brussels'))->format('Y-m-d');
    $storage = $this->container->get('entity_type.manager')->getStorage(PersonalTask::ENTITY_TYPE_ID);
    // Test fixtures are persisted in the isolated test database only.
    $yesterday = (new \DateTimeImmutable($localDate))->modify('-1 day')->format('Y-m-d');
    foreach ([
      ['Synthetic visible Today task', (int) $h1->id(), $localDate],
      ['Synthetic overdue Today task', (int) $h1->id(), $yesterday],
      ['Synthetic forbidden Today task', (int) $h2->id(), $localDate],
    ] as [$title, $householdId, $dueDate]) {
      $storage->create([
        'title' => $title,
        'household' => $householdId,
        'assigned_person' => (int) $person->id(),
        'due_mode' => PersonalTask::DUE_DATE,
        'due_date' => $dueDate,
        'status' => PersonalTask::STATUS_OPEN,
      ])->save();
    }

    $this->drupalLogin($ordinary);
    $this->drupalGet($route);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogout();
    $this->drupalLogin($unlinked);
    $this->drupalGet('/personal-secretary/today');
    $this->assertSession()->linkNotExists('Talk to the assistant');
    $this->drupalGet($route);
    $this->assertSession()->pageTextContains('Link your account to an authorized Household member');
    $this->assertSession()->pageTextNotContains('Synthetic visible Today task');
    $this->assertSession()->elementNotExists('css', 'input[name="op"]');

    $this->drupalLogout();
    $this->drupalLogin($withoutGrant);
    $this->drupalGet($route);
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->pageTextNotContains('Synthetic forbidden Today task');

    $this->drupalLogout();
    $this->drupalLogin($authorized);
    $this->drupalGet('/personal-secretary/today');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkByHrefExists($route);
    $this->assertCount(1, $this->getSession()->getPage()->findAll('css', '.ps-today-assistant__link'));
    $this->assertSession()->elementExists('css', '.ps-today-assistant ~ .ps-today-section--tasks');

    $this->drupalGet($route, ['query' => [
      'user_id' => $ordinary->id(),
      'household_id' => $h2->id(),
    ]]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkByHrefExists('/personal-secretary/today');
    $this->assertSession()->pageTextContains('Choose an action');
    $this->assertSession()->pageTextContains('View my day');
    $this->assertSession()->pageTextContains('Prepare a task');
    $this->assertSession()->pageTextContains('Prepare an activity');
    $this->assertSession()->pageTextNotContains('Synthetic forbidden Today task');
    $this->assertSession()->elementNotExists('css', 'textarea');
    $this->assertSession()->elementNotExists('css', '[name="user_id"]');
    $this->assertSession()->elementNotExists('css', '[name="confirm"]');

    $before = $this->domainCounts();
    $this->submitForm(['intent' => 'GET_TODAY'], 'Show preview');
    $this->assertSession()->pageTextContains('READ_SUCCESS');
    $this->assertSession()->pageTextContains('Synthetic visible Today task');
    $this->assertSession()->pageTextContains('Synthetic overdue Today task');
    $this->assertSession()->pageTextNotContains('Synthetic forbidden Today task');
    $this->assertSession()->pageTextContains('Your day at a glance');
    $this->assertSession()->pageTextContains('Tasks due or overdue: 2');
    $this->assertSession()->pageTextContains('Overdue tasks: 1');
    $this->assertSession()->pageTextContains('Preparations due or overdue: 0');
    $this->assertSession()->pageTextContains('Overdue preparations: 0');
    $this->assertSession()->pageTextContains('Planning items: 0');
    $this->assertSession()->pageTextContains('Some items are overdue.');
    $this->assertSession()->pageTextContains('Overdue:');
    $this->assertSession()->linkByHrefExists('/personal-secretary/tasks/mine');
    $this->assertSession()->linkByHrefExists('/personal-secretary/preparations/mine');
    $this->assertSession()->linkByHrefExists('/personal-secretary/upcoming/mine');
    $this->assertSession()->pageTextContains('Preview not saved');
    $this->assertSame($before, $this->domainCounts());

    $task = ['intent' => 'PROPOSE_TASK', 'task_title' => 'Synthetic guided task', 'task_due_mode' => PersonalTask::DUE_NONE];
    $this->submitForm($task, 'Show preview');
    $this->assertSession()->pageTextContains('PROPOSAL_READY');
    $this->assertSession()->pageTextContains('Synthetic guided task');
    $this->assertSession()->pageTextContains('Preview not saved');
    $this->assertSession()->pageTextContains('Create this task will save one real local task.');
    $this->assertSame($before, $this->domainCounts(), 'Preview must never create a task.');
    $this->submitForm(['task_title' => 'Synthetic changed without review'], 'Create this task');
    $this->assertSession()->pageTextContains('Review a new preview');
    $this->assertSession()->elementNotExists('css', 'input[name="confirm_create_task"]');
    $this->assertSame($before, $this->domainCounts(), 'Changed fields must not create the reviewed task.');

    $this->submitForm($task, 'Show preview');
    $this->assertSession()->pageTextContains('PROPOSAL_READY');
    $this->assertSession()->elementExists('css', 'input[name="confirm_create_task"]');
    $this->submitForm($task, 'Show preview');
    $this->assertSession()->pageTextContains('PROPOSAL_READY');

    $this->submitForm(['intent' => 'PROPOSE_TASK', 'task_title' => '', 'task_due_mode' => PersonalTask::DUE_DATE], 'Show preview');
    $this->assertSession()->pageTextContains('NEEDS_CLARIFICATION');
    $this->assertSession()->pageTextContains('Enter a valid title.');
    $this->assertSession()->elementNotExists('css', 'input[name="confirm_create_task"]');

    $activity = [
      'intent' => 'PROPOSE_ACTIVITY',
      'activity_label' => 'Synthetic guided walk',
      'activity_date' => '2026-10-22',
      'activity_mode' => 'timed',
      'activity_start' => '09:00',
      'activity_end' => '10:00',
      'activity_responsible_self' => 1,
    ];
    $this->submitForm($activity, 'Show preview');
    $this->assertSession()->pageTextContains('PROPOSAL_READY');
    $this->assertSession()->pageTextContains('Synthetic guided walk');
    $this->assertSession()->pageTextContains('Preview not saved');
    $this->assertSession()->elementExists('css', 'input[name="confirm_create_activity"]');
    $this->assertSession()->elementNotExists('css', 'input[name="confirm_create_task"]');
    $this->submitForm($activity, 'Show preview');
    $this->assertSession()->pageTextContains('PROPOSAL_READY');

    $activity['activity_end'] = '';
    $this->submitForm($activity, 'Show preview');
    $this->assertSession()->pageTextContains('NEEDS_CLARIFICATION');
    $this->assertSession()->pageTextContains('Enter an end time after the start.');
    $this->assertSession()->elementNotExists('css', 'input[name="confirm_create_activity"]');

    $activity['activity_mode'] = 'all_day';
    $this->submitForm($activity, 'Show preview');
    $this->assertSession()->pageTextContains('PROPOSAL_READY');
    $this->assertSame($before, $this->domainCounts(), 'Preview and repeated submit must not persist entities.');

    $this->drupalGet($route);
    $this->assertSession()->pageTextNotContains('Synthetic guided walk');
    $this->assertSession()->pageTextNotContains('PROPOSAL_READY');
    $this->assertSession()->linkByHrefExists('/personal-secretary/today');

    // Existing language negotiation is reused, not a new translation catalog.
    if (ConfigurableLanguage::load('fr') === NULL) {
      ConfigurableLanguage::createFromLangcode('fr')->save();
    }
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

    $this->drupalGet('/fr/personal-secretary/assistant');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Choisir une action');
    $this->assertSession()->pageTextContains('Préparer une tâche');
    $this->assertSession()->linkByHrefExists('/fr/personal-secretary/today');
    $this->submitForm(['intent' => 'GET_TODAY'], 'Afficher l’aperçu');
    $this->assertSession()->pageTextContains('Votre journée en bref');
    $this->assertSession()->pageTextContains('Tâches dues ou en retard: 2');
    $this->assertSession()->pageTextContains('Tâches en retard: 1');
    $this->assertSession()->pageTextContains('Certains éléments sont en retard.');
    $this->assertSession()->linkByHrefExists('/fr/personal-secretary/tasks/mine');
    $this->assertSession()->linkByHrefExists('/fr/personal-secretary/preparations/mine');
    $this->assertSession()->linkByHrefExists('/fr/personal-secretary/upcoming/mine');
    $this->assertSession()->pageTextNotContains('Synthetic forbidden Today task');
    $this->assertSame($before, $this->domainCounts());
    $this->submitForm($task, 'Afficher l’aperçu');
    $this->assertSession()->pageTextContains('Aperçu non enregistré');
    $this->assertSession()->pageTextContains('Créer cette tâche');
    $this->submitForm($activity, 'Afficher l’aperçu');
    $this->assertSession()->pageTextContains('Créer cette activité');
    $this->assertSame($before, $this->domainCounts());
    $this->drupalGet('/en/personal-secretary/assistant');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Choose an action');
    $this->assertSession()->elementNotExists('css', 'input[name="confirm_create_task"]');
    $this->submitForm($task, 'Show preview');
    $this->assertSession()->pageTextContains('PROPOSAL_READY');
    $this->assertSame($before, $this->domainCounts());
    $this->submitForm([], 'Create this task');
    $this->assertSession()->addressEquals('/en/personal-secretary/tasks/mine');
    $this->assertSession()->pageTextContains('Task created.');
    $after = $this->domainCounts();
    $this->assertSame($before['task'] + 1, $after['task']);
    $storage->resetCache();
    $created = array_values(array_filter($storage->loadMultiple(),
      static fn(PersonalTask $item): bool => (string) $item->get('title')->value === 'Synthetic guided task'));
    $this->assertCount(1, $created);
    $this->assertSame((int) $h1->id(), (int) $created[0]->get('household')->target_id);
    $this->assertSame((int) $person->id(), (int) $created[0]->get('assigned_person')->target_id);
    $this->getSession()->reload();
    $this->assertSame($after, $this->domainCounts(), 'PRG refresh must not resubmit task creation.');
  }

  public function testOnlyExplicitOneOffConfirmationCreatesActivityAndRejectsTampering(): void {
    $person = $this->container->get('personal_secretary.domain_mutation')->createPerson('Synthetic Activity Creator');
    $household = $this->container->get('personal_secretary.domain_mutation')->createHousehold('Synthetic Activity Household', [(int) $person->id()]);
    $user = $this->productUser($person, [$household]);
    $this->drupalLogin($user);
    $route = '/personal-secretary/assistant';
    $this->drupalGet($route);
    $before = $this->domainCounts();

    $timed = [
      'intent' => 'PROPOSE_ACTIVITY',
      'activity_label' => 'Synthetic confirmed timed walk',
      'activity_date' => '2026-11-03',
      'activity_mode' => 'timed',
      'activity_start' => '09:15',
      'activity_end' => '10:45',
      'activity_responsible_self' => 1,
    ];
    $this->submitForm($timed, 'Show preview');
    $this->assertSession()->pageTextContains('PROPOSAL_READY');
    $this->assertSession()->pageTextContains('Synthetic Activity Household');
    $this->assertSession()->pageTextContains('Europe/Brussels');
    $this->assertSession()->elementExists('css', 'input[name="confirm_create_activity"]');
    $this->assertSame($before, $this->domainCounts(), 'A complete preview is read-only.');

    $this->submitForm(['activity_end' => '11:45'], 'Create this activity');
    $this->assertSession()->pageTextContains('Review a new preview');
    $this->assertSession()->elementNotExists('css', 'input[name="confirm_create_activity"]');
    $this->assertSame($before, $this->domainCounts(), 'Tampered review must not write.');

    $this->submitForm($timed, 'Show preview');
    $this->submitForm([], 'Create this activity');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming/mine');
    $this->assertSession()->pageTextContains('Activity created.');
    $afterTimed = $this->domainCounts();
    $this->assertSame($before['series'] + 1, $afterTimed['series']);
    $seriesStorage = $this->container->get('entity_type.manager')->getStorage('personal_sec_activity_series');
    $seriesStorage->resetCache();
    $created = array_values(array_filter($seriesStorage->loadMultiple(),
      static fn(ActivitySeries $item): bool => (string) $item->get('name')->value === 'Synthetic confirmed timed walk'));
    $this->assertCount(1, $created);
    $this->assertSame('timed', (string) $created[0]->get('time_mode')->value);
    $this->assertSame((int) $household->id(), (int) $created[0]->get('household')->target_id);
    $this->getSession()->reload();
    $this->assertSame($afterTimed, $this->domainCounts(), 'Reload must not create a duplicate.');

    $this->drupalGet($route);
    $allDay = [
      'intent' => 'PROPOSE_ACTIVITY',
      'activity_label' => 'Synthetic confirmed all-day event',
      'activity_date' => '2026-11-04',
      'activity_mode' => 'all_day',
      'activity_responsible_self' => 1,
    ];
    $this->submitForm($allDay, 'Show preview');
    $this->assertSession()->elementExists('css', 'input[name="confirm_create_activity"]');
    $this->assertSame($afterTimed, $this->domainCounts());
    $this->submitForm([], 'Create this activity');
    $this->assertSession()->pageTextContains('Activity created.');
    $afterAllDay = $this->domainCounts();
    $this->assertSame($afterTimed['series'] + 1, $afterAllDay['series']);
    $seriesStorage->resetCache();
    $created = array_values(array_filter($seriesStorage->loadMultiple(),
      static fn(ActivitySeries $item): bool => (string) $item->get('name')->value === 'Synthetic confirmed all-day event'));
    $this->assertCount(1, $created);
    $this->assertSame('all_day', (string) $created[0]->get('time_mode')->value);
    $this->getSession()->reload();
    $this->assertSame($afterAllDay, $this->domainCounts());

    // Ready proposals with no explicit self responsibility remain preview-only.
    $this->drupalGet($route);
    $noResponsibility = $timed;
    $noResponsibility['activity_responsible_self'] = 0;
    $this->submitForm($noResponsibility, 'Show preview');
    $this->assertSession()->elementNotExists('css', 'input[name="confirm_create_activity"]');
    $this->assertSame($afterAllDay, $this->domainCounts());
    $this->submitForm(['intent' => 'GET_TODAY'], 'Show preview');
    $this->assertSession()->pageTextContains('READ_SUCCESS');
    $this->assertSession()->elementNotExists('css', 'input[name="confirm_create_activity"]');
    $this->assertSame($afterAllDay, $this->domainCounts());
  }

  public function testAmbiguousHouseholdAndRevokedContextDoNotCreateActivity(): void {
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $person = $domain->createPerson('Synthetic Activity Context');
    $otherPerson = $domain->createPerson('Synthetic Alternative Context');
    $h1 = $domain->createHousehold('Synthetic activity house one', [(int) $person->id(), (int) $otherPerson->id()]);
    $h2 = $domain->createHousehold('Synthetic activity house two', [(int) $person->id()]);
    $user = $this->productUser($person, [$h1, $h2]);
    $this->drupalLogin($user);
    $route = '/personal-secretary/assistant';
    $this->drupalGet($route);
    $before = $this->domainCounts();
    $activity = [
      'intent' => 'PROPOSE_ACTIVITY',
      'activity_label' => 'Synthetic ambiguous activity',
      'activity_date' => '2026-11-05',
      'activity_mode' => 'all_day',
      'activity_responsible_self' => 1,
    ];
    $this->submitForm($activity, 'Show preview');
    $this->assertSession()->pageTextContains('NEEDS_CLARIFICATION');
    $this->assertSession()->elementNotExists('css', 'input[name="confirm_create_activity"]');
    $this->assertSame($before, $this->domainCounts());

    // With a single eligible household, a genuine review is possible.
    $user->set(HouseholdAuthorizationService::FIELD_NAME, [['target_id' => (int) $h1->id()]]);
    $user->save();
    $this->drupalGet($route);
    $this->submitForm($activity, 'Show preview');
    $this->assertSession()->elementExists('css', 'input[name="confirm_create_activity"]');
    $this->assertSame($before, $this->domainCounts());

    // Change the persisted CurrentPerson between preview and confirmation.
    $user->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $otherPerson->id()]);
    $user->save();
    $this->submitForm([], 'Create this activity');
    $this->assertSession()->elementNotExists('css', 'input[name="confirm_create_activity"]');
    $this->assertSame($before, $this->domainCounts());
  }

  public function testWeeklyStructuredConfirmationAndGuardedNoWrite(): void {
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $person = $domain->createPerson('Synthetic Weekly Creator');
    $household = $domain->createHousehold('Synthetic Weekly Home', [(int) $person->id()]);
    $user = $this->productUser($person, [$household]);
    $this->drupalLogin($user);
    $route = '/personal-secretary/assistant';
    $this->drupalGet($route);
    $before = $this->domainCounts();
    $weekly = [
      'intent' => 'PROPOSE_ACTIVITY',
      'activity_label' => 'Synthetic Wednesday Tennis',
      'activity_recurrence' => 'weekly',
      'activity_weekday' => 'WEDNESDAY',
      'activity_date' => '2026-11-04',
      'activity_mode' => 'timed',
      'activity_start' => '16:00',
      'activity_end' => '17:00',
      'activity_responsible_self' => 1,
    ];
    $this->submitForm($weekly, 'Show preview');
    $this->assertSession()->pageTextContains('PROPOSAL_READY');
    $this->assertSession()->pageTextContains('Every week');
    $this->assertSession()->pageTextContains('Wednesday');
    $this->assertSession()->pageTextContains('Synthetic Weekly Home');
    $this->assertSession()->pageTextContains('Europe/Brussels');
    $this->assertSession()->pageTextContains('Preview not saved');
    $this->assertSession()->elementExists('css', 'input[name="confirm_create_weekly_activity"]');
    $this->assertSession()->elementNotExists('css', 'input[name="confirm_create_activity"]');
    $this->assertSame($before, $this->domainCounts());

    $this->submitForm(['activity_start' => '15:00'], 'Create this weekly activity');
    $this->assertSession()->pageTextContains('Review a new preview');
    $this->assertSession()->elementNotExists('css', 'input[name="confirm_create_weekly_activity"]');
    $this->assertSame($before, $this->domainCounts());

    $wrongDay = $weekly;
    $wrongDay['activity_weekday'] = 'TUESDAY';
    $this->submitForm($wrongDay, 'Show preview');
    $this->assertSession()->pageTextContains('first date must match');
    $this->assertSession()->elementNotExists('css', 'input[name="confirm_create_weekly_activity"]');
    $this->assertSame($before, $this->domainCounts());

    $missing = $weekly;
    $missing['activity_responsible_self'] = 0;
    $this->submitForm($missing, 'Show preview');
    $this->assertSession()->elementNotExists('css', 'input[name="confirm_create_weekly_activity"]');
    $this->assertSame($before, $this->domainCounts());

    $invalidTime = $weekly;
    $invalidTime['activity_end'] = '15:00';
    $this->submitForm($invalidTime, 'Show preview');
    $this->assertSession()->pageTextContains('NEEDS_CLARIFICATION');
    $this->assertSession()->elementNotExists('css', 'input[name="confirm_create_weekly_activity"]');

    $this->submitForm($weekly, 'Show preview');
    $this->assertSession()->elementExists('css', 'input[name="confirm_create_weekly_activity"]');
    $this->assertSame($before, $this->domainCounts());
    $this->submitForm([], 'Create this weekly activity');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming/mine');
    $this->assertSession()->pageTextContains('Weekly activity created.');
    $after = $this->domainCounts();
    $this->assertSame($before['series'] + 1, $after['series']);
    $manager = $this->container->get('entity_type.manager');
    $seriesStorage = $manager->getStorage('personal_sec_activity_series');
    $seriesStorage->resetCache();
    $series = array_values(array_filter($seriesStorage->loadMultiple(),
      static fn(ActivitySeries $a): bool => (string) $a->get('name')->value === 'Synthetic Wednesday Tennis'));
    $this->assertCount(1, $series);
    $this->assertSame('timed', (string) $series[0]->get('time_mode')->value);
    $this->assertSame((int) $household->id(), (int) $series[0]->get('household')->target_id);
    $this->assertSame('FREQ=WEEKLY;INTERVAL=1', (string) $series[0]->get('recurrence')->first()->rrule);
    $ruleStorage = $manager->getStorage('personal_sec_resp_rule');
    $rules = array_values(array_filter($ruleStorage->loadMultiple(),
      static fn($rule): bool => (int) $rule->get('series')->target_id === (int) $series[0]->id()));
    $this->assertCount(1, $rules);
    $this->assertSame((int) $person->id(), (int) $rules[0]->get('responsible_person')->target_id);
    $this->getSession()->reload();
    $this->assertSame($after, $this->domainCounts(), 'PRG reload must not create a duplicate weekly series.');

    $this->drupalGet($route);
    $allDay = [
      'intent' => 'PROPOSE_ACTIVITY',
      'activity_label' => 'Synthetic Weekly All Day',
      'activity_recurrence' => 'weekly',
      'activity_weekday' => 'MONDAY',
      'activity_date' => '2026-11-09',
      'activity_mode' => 'all_day',
      'activity_responsible_self' => 1,
    ];
    $this->submitForm($allDay, 'Show preview');
    $this->assertSession()->elementExists('css', 'input[name="confirm_create_weekly_activity"]');
    $this->assertSame($after, $this->domainCounts());
    $this->submitForm([], 'Create this weekly activity');
    $this->assertSession()->pageTextContains('Weekly activity created.');
    $seriesStorage->resetCache();
    $allSeries = array_values(array_filter($seriesStorage->loadMultiple(),
      static fn(ActivitySeries $a): bool => (string) $a->get('name')->value === 'Synthetic Weekly All Day'));
    $this->assertCount(1, $allSeries);
    $this->assertSame('all_day', (string) $allSeries[0]->get('time_mode')->value);
    $this->assertSame($after['series'] + 1, $this->domainCounts()['series']);
  }

  public function testWeeklyDstRejectionAndFrenchPreview(): void {
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $person = $domain->createPerson('Synthetic Weekly DST');
    $household = $domain->createHousehold('Synthetic Weekly DST Household', [(int) $person->id()]);
    $this->drupalLogin($this->productUser($person, [$household]));
    $this->drupalGet('/personal-secretary/assistant');
    $before = $this->domainCounts();
    $activity = [
      'intent' => 'PROPOSE_ACTIVITY',
      'activity_label' => 'Synthetic Sunday Session',
      'activity_recurrence' => 'weekly',
      'activity_weekday' => 'SUNDAY',
      'activity_date' => '2026-03-29',
      'activity_mode' => 'timed',
      'activity_start' => '02:15',
      'activity_end' => '03:15',
      'activity_responsible_self' => 1,
    ];
    $this->submitForm($activity, 'Show preview');
    $this->assertSession()->pageTextContains('clock change');
    $this->assertSession()->elementNotExists('css', 'input[name="confirm_create_weekly_activity"]');
    $this->assertSame($before, $this->domainCounts());

    $activity['activity_date'] = '2026-10-25';
    $activity['activity_start'] = '02:15';
    $activity['activity_end'] = '03:15';
    $this->submitForm($activity, 'Show preview');
    $this->assertSession()->pageTextContains('clock change');
    $this->assertSession()->elementNotExists('css', 'input[name="confirm_create_weekly_activity"]');
    $this->assertSame($before, $this->domainCounts());

    if (ConfigurableLanguage::load('fr') === NULL) {
      ConfigurableLanguage::createFromLangcode('fr')->save();
    }
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
    $this->drupalGet('/fr/personal-secretary/assistant');
    $activity['activity_date'] = '2026-11-01';
    $activity['activity_start'] = '16:00';
    $activity['activity_end'] = '17:00';
    $this->submitForm($activity, 'Afficher l’aperçu');
    $this->assertSession()->pageTextContains('Chaque semaine');
    $this->assertSession()->pageTextContains('Dimanche');
    $this->assertSession()->pageTextContains('Créer cette activité hebdomadaire');
    $this->assertSame($before, $this->domainCounts());
  }

  public function testHouseholdAuthorizationDriftRejectsTaskConfirmation(): void {
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $person = $domain->createPerson('Synthetic Drift Person');
    $allowed = $domain->createHousehold('Synthetic Drift Eligible', [(int) $person->id()]);
    $other = $domain->createHousehold('Synthetic Drift Other', [(int) $person->id()]);
    $user = $this->productUser($person, [$allowed, $other]);
    $this->drupalLogin($user);
    $this->drupalGet('/personal-secretary/assistant');
    $before = $this->domainCounts();
    $this->submitForm([
      'intent' => 'PROPOSE_TASK',
      'task_title' => 'Synthetic drifted task',
      'task_household' => (string) $allowed->id(),
      'task_due_mode' => PersonalTask::DUE_DATE,
      'task_due_date' => '2026-11-01',
    ], 'Show preview');
    $this->assertSession()->pageTextContains('PROPOSAL_READY');
    $this->assertSame($before, $this->domainCounts());
    // Revoke precisely the reviewed Household before the final POST.
    $user->set(HouseholdAuthorizationService::FIELD_NAME, [
      ['target_id' => (int) $other->id()],
    ]);
    $user->save();
    $this->submitForm([], 'Create this task');
    $this->assertSame($before, $this->domainCounts());
    $this->assertSession()->elementNotExists('css', 'input[name="confirm_create_task"]');
  }

  /**
   * @param object[] $households
   */
  private function productUser(?object $person, array $households): UserInterface {
    $user = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    self::assertInstanceOf(UserInterface::class, $user);
    if ($person !== NULL) {
      $user->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $person->id()]);
    }
    if ($households !== []) {
      $user->set(HouseholdAuthorizationService::FIELD_NAME, array_map(
        static fn(object $h): array => ['target_id' => (int) $h->id()], $households,
      ));
    }
    $user->set('timezone', 'Europe/Brussels');
    $user->save();
    return $user;
  }

  /**
   * @return array<string, int>
   */
  private function domainCounts(): array {
    $manager = $this->container->get('entity_type.manager');
    $counts = [];
    foreach ([
      'task' => 'personal_sec_task',
      'series' => 'personal_sec_activity_series',
      'preparations' => 'personal_sec_prep_req',
      'persons' => 'personal_secretary_person',
      'households' => 'personal_secretary_household',
    ] as $key => $entityType) {
      $counts[$key] = count($manager->getStorage($entityType)->loadMultiple());
    }
    return $counts;
  }

  private function installReference(string $name, string $targetType, int $cardinality): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'user',
      'type' => 'entity_reference',
      'settings' => ['target_type' => $targetType],
      'cardinality' => $cardinality,
      'translatable' => FALSE,
    ])->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => $name,
      'required' => FALSE,
      'translatable' => FALSE,
      'settings' => ['handler' => 'default:' . $targetType, 'handler_settings' => []],
    ])->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

}
