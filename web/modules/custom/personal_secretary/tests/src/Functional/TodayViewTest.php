<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use Drupal\Core\Url;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\CalendarAccountConnection;
use Drupal\personal_secretary\Entity\ExternalEventShadow;
use Drupal\personal_secretary\Entity\PersonalTask;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;

/**
 * Proves the read-only Today composition for authorized current-user truth.
 *
 * @group personal_secretary
 */
final class TodayViewTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testTodayComposesTasksPreparationsAndActivities(): void {
    $todayUrl = '/personal-secretary/today';
    $this->drupalGet($todayUrl);
    $this->assertSession()->statusCodeEquals(403);

    $this->installUserReferenceField(CurrentPersonResolver::FIELD_NAME, 'personal_secretary_person', 1, 'Personal Secretary person');
    $this->installUserReferenceField(HouseholdAuthorizationService::FIELD_NAME, 'personal_secretary_household', FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED, 'Personal Secretary households');

    $domain = $this->container->get('personal_secretary.domain_mutation');
    $responsibilityMutations = $this->container->get('personal_secretary.responsibility_mutation');
    $preparationMutations = $this->container->get('personal_secretary.preparation_requirement_mutation');
    $exceptions = $this->container->get('personal_secretary.activity_exception');
    $timeline = $this->container->get('personal_secretary.revision_timeline');
    $effective = $this->container->get('personal_secretary.effective_occurrence_projection');
    $taskMutations = $this->container->get('personal_secretary.personal_task_mutation');
    $today = $this->container->get('personal_secretary.today');

    $person = $domain->createPerson('Synthetic Today current person');
    $otherPerson = $domain->createPerson('Synthetic Today other person');
    $h1 = $domain->createHousehold('Synthetic Today authorized household', [(int) $person->id(), (int) $otherPerson->id()]);
    $h2 = $domain->createHousehold('Synthetic Today unauthorized household', [(int) $person->id(), (int) $otherPerson->id()]);

    $authorized = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $authorized);
    $authorized->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $person->id()]);
    $authorized->set(HouseholdAuthorizationService::FIELD_NAME, [['target_id' => (int) $h1->id()]]);
    $authorized->set('timezone', 'Europe/Brussels');
    $authorized->save();

    $noGrant = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $noGrant);
    $noGrant->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $person->id()]);
    $noGrant->save();

    $unlinked = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $unlinked);
    $unlinked->set(HouseholdAuthorizationService::FIELD_NAME, [['target_id' => (int) $h1->id()]]);
    $unlinked->save();

    $accountSwitcher = $this->container->get('account_switcher');
    $accountSwitcher->switchTo($authorized);
    try {
      $nowUtc = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))->setTimezone(new \DateTimeZone('UTC'));
      $window = $today->windowFor($authorized, $nowUtc);
      $localStart = $window['local_start'];
      $localEnd = $window['local_end'];

      $insideStart = $window['utc_start']->modify('+4 hours');
      $authorizedActivity = $this->createSeriesWithRule('Today authorized UTC activity', (int) $h1->id(), (int) $person->id(), $insideStart, $insideStart->modify('+1 hour'));
      $authorizedOccurrence = $effective->projectOverlapping($authorizedActivity, $window['utc_start'], $window['utc_end'])[0];

      $h2Start = $window['utc_start']->modify('+5 hours');
      $this->createSeriesWithRule('Today unauthorized H2 activity', (int) $h2->id(), (int) $person->id(), $h2Start, $h2Start->modify('+1 hour'));

      $crossStart = $localStart->modify('-1 hour');
      $crossActivity = $this->createSeriesWithRule('Today cross-midnight activity', (int) $h1->id(), (int) $person->id(), $crossStart, $localStart->modify('+1 hour'));
      $crossOccurrence = $effective->projectOverlapping($crossActivity, $window['utc_start'], $window['utc_end'])[0];

      $cancelStart = $localStart->modify('+8 hours');
      $cancelled = $this->createSeriesWithRule('Today cancelled activity', (int) $h1->id(), (int) $person->id(), $cancelStart, $cancelStart->modify('+1 hour'));
      $cancelTarget = $timeline->projectBaseWindow($cancelled, $window['utc_start'], $window['utc_end'])[0];
      $exceptions->createCancel($cancelled, $cancelTarget);

      $toCurrentStart = $localStart->modify('+10 hours');
      $toCurrent = $this->createSeriesWithRule('Today responsibility to current', (int) $h1->id(), (int) $otherPerson->id(), $toCurrentStart, $toCurrentStart->modify('+1 hour'));
      $toCurrentOccurrence = $effective->projectOverlapping($toCurrent, $window['utc_start'], $window['utc_end'])[0];
      $responsibilityMutations->createAssignOverride($toCurrent, $toCurrentOccurrence, (int) $person->id());

      $awayStart = $localStart->modify('+12 hours');
      $away = $this->createSeriesWithRule('Today responsibility away', (int) $h1->id(), (int) $person->id(), $awayStart, $awayStart->modify('+1 hour'));
      $awayOccurrence = $effective->projectOverlapping($away, $window['utc_start'], $window['utc_end'])[0];
      $responsibilityMutations->createAssignOverride($away, $awayOccurrence, (int) $otherPerson->id());

      $effectiveFrom = $window['utc_start']->modify('-10 days');
      $dueTodayAt = $window['utc_start'];
      $dueTodayActivityStart = $window['utc_end']->modify('+6 hours');
      $dueTodaySeries = $this->createSeriesWithRule('Today preparation related activity', (int) $h1->id(), (int) $person->id(), $dueTodayActivityStart, $dueTodayActivityStart->modify('+1 hour'));
      $preparationMutations->createPreparationRequirement($dueTodaySeries, 'Today due preparation', $dueTodayActivityStart->getTimestamp() - $dueTodayAt->getTimestamp(), $effectiveFrom);

      $overdueAt = $window['utc_start']->modify('-1 hour');
      $overdueActivityStart = $window['utc_end']->modify('+1 day');
      $overdueSeries = $this->createSeriesWithRule('Today overdue preparation related activity', (int) $h1->id(), (int) $person->id(), $overdueActivityStart, $overdueActivityStart->modify('+1 hour'));
      $preparationMutations->createPreparationRequirement($overdueSeries, 'Today active overdue preparation', $overdueActivityStart->getTimestamp() - $overdueAt->getTimestamp(), $effectiveFrom);

      // A still-upcoming deadline in the same local day exercises mixed urgency.
      $laterDueAt = $window['utc_end']->modify('-1 minute');
      $laterActivityStart = $window['utc_end']->modify('+8 hours');
      $laterSeries = $this->createSeriesWithRule('Today later preparation related activity', (int) $h1->id(), (int) $person->id(), $laterActivityStart, $laterActivityStart->modify('+1 hour'));
      $preparationMutations->createPreparationRequirement($laterSeries, 'Today later preparation', $laterActivityStart->getTimestamp() - $laterDueAt->getTimestamp(), $effectiveFrom);

      $futureDueAt = $window['utc_end']->modify('+1 hour');
      $futureActivityStart = $window['utc_end']->modify('+6 hours');
      $futureSeries = $this->createSeriesWithRule('Today future preparation related activity', (int) $h1->id(), (int) $person->id(), $futureActivityStart, $futureActivityStart->modify('+1 hour'));
      $preparationMutations->createPreparationRequirement($futureSeries, 'Today future preparation excluded', $futureActivityStart->getTimestamp() - $futureDueAt->getTimestamp(), $effectiveFrom);

      $startedActivityStart = $nowUtc->modify('-2 hours');
      $startedSeries = $this->createSeriesWithRule('Today already started preparation activity', (int) $h1->id(), (int) $person->id(), $startedActivityStart, $startedActivityStart->modify('+1 hour'));
      $preparationMutations->createPreparationRequirement($startedSeries, 'Today started preparation excluded', 3600, $effectiveFrom);

      $h2PrepActivityStart = $window['utc_end']->modify('+4 hours');
      $h2PrepSeries = $this->createSeriesWithRule('Today unauthorized H2 preparation activity', (int) $h2->id(), (int) $person->id(), $h2PrepActivityStart, $h2PrepActivityStart->modify('+1 hour'));
      $preparationMutations->createPreparationRequirement($h2PrepSeries, 'Today unauthorized H2 preparation', $h2PrepActivityStart->getTimestamp() - $dueTodayAt->getTimestamp(), $effectiveFrom);

      $dueToday = $taskMutations->createTask('Today route task', (int) $h1->id(), PersonalTask::DUE_DATE, $window['local_date']);

      $connection = CalendarAccountConnection::create([
        'owner_user' => (int) $authorized->id(),
        'provider_key' => CalendarAccountConnection::PROVIDER_GOOGLE,
        'provider_subject_id' => 'today-external-google-subject',
        'scopes' => array_map(
          static fn(string $scope): array => ['value' => $scope],
          CalendarAccountConnection::writeScopes(),
        ),
        'status' => CalendarAccountConnection::STATUS_CONNECTED,
        'connected_at' => $nowUtc->getTimestamp(),
      ]);
      $connection->save();

      $externalStorage = $this->container->get('entity_type.manager')
        ->getStorage(ExternalEventShadow::ENTITY_TYPE_ID);
      $externalStorage->create([
        'owner_user' => (int) $authorized->id(),
        'account_connection' => (int) $connection->id(),
        'provider' => 'google',
        'calendar_id' => 'primary',
        'provider_event_id' => 'today-external-timed',
        'etag' => '"today-timed-etag"',
        'provider_status' => 'confirmed',
        'title' => 'External Today appointment',
        'time_mode' => ExternalEventShadow::TIME_MODE_TIMED,
        'source_timezone' => 'Europe/Brussels',
        'location' => 'External room',
        'transparency' => 'opaque',
        'timed_start' => $window['utc_start']->modify('+6 hours')->format('Y-m-d\TH:i:s'),
        'timed_end' => $window['utc_start']->modify('+7 hours')->format('Y-m-d\TH:i:s'),
        'provider_updated' => $nowUtc->format('Y-m-d\TH:i:s'),
        'active' => TRUE,
        'last_seen_at' => $nowUtc->getTimestamp(),
      ])->save();
      $externalStorage->create([
        'owner_user' => (int) $authorized->id(),
        'account_connection' => (int) $connection->id(),
        'provider' => 'google',
        'calendar_id' => 'primary',
        'provider_event_id' => 'today-external-all-day',
        'etag' => '"today-all-day-etag"',
        'provider_status' => 'confirmed',
        'title' => 'External Today all day',
        'time_mode' => ExternalEventShadow::TIME_MODE_ALL_DAY,
        'source_timezone' => '',
        'location' => '',
        'transparency' => 'transparent',
        'all_day_start' => $window['local_date'],
        'all_day_end' => $window['local_end']->format('Y-m-d'),
        'provider_updated' => $nowUtc->format('Y-m-d\TH:i:s'),
        'active' => TRUE,
        'last_seen_at' => $nowUtc->getTimestamp(),
      ])->save();

      $model = $today->today();
      $activities = $this->activitiesByLabel($model['activities']);
      $this->assertArrayHasKey('Today authorized UTC activity', $activities);
      $this->assertSame('UTC', $activities['Today authorized UTC activity']['source_timezone']);
      $this->assertSame('Europe/Brussels', $activities['Today authorized UTC activity']['display_timezone']);
      $this->assertArrayHasKey('Today cross-midnight activity', $activities);
      $this->assertArrayHasKey('Today responsibility to current', $activities);
      $this->assertArrayNotHasKey('Today responsibility away', $activities);
      $this->assertArrayNotHasKey('Today cancelled activity', $activities);
      $this->assertArrayNotHasKey('Today unauthorized H2 activity', $activities);

      $preparationItems = $this->preparationsByInstruction($model['preparations']);
      $this->assertArrayHasKey('Today due preparation', $preparationItems);
      $this->assertArrayHasKey('Today active overdue preparation', $preparationItems);
      $this->assertArrayHasKey('Today later preparation', $preparationItems);
      $this->assertArrayNotHasKey('Today future preparation excluded', $preparationItems);
      $this->assertArrayNotHasKey('Today started preparation excluded', $preparationItems);
      $this->assertArrayNotHasKey('Today unauthorized H2 preparation', $preparationItems);
      $this->assertSame($dueTodayAt < $nowUtc, $preparationItems['Today due preparation']['overdue']);
      $this->assertTrue($preparationItems['Today active overdue preparation']['overdue']);
      $this->assertSame($laterDueAt < $nowUtc, $preparationItems['Today later preparation']['overdue']);
      $this->assertSame('Europe/Brussels', $preparationItems['Today due preparation']['display_timezone']);

      $taskTitles = array_map(static fn(array $task): string => (string) $task['title'], $model['tasks']);
      $this->assertNotContains('Today due preparation', $taskTitles);
      $this->assertLessThan($localEnd, $localStart);
    }
    finally {
      $accountSwitcher->switchBack();
    }

    $this->drupalLogin($authorized);
    $countsBefore = $this->domainCounts();
    $this->drupalGet($todayUrl);
    $this->assertSession()->statusCodeEquals(200);
    $localNoon = new DateTimeImmutable(
      $model['local_date'] . ' 12:00:00',
      new \DateTimeZone($model['timezone']),
    );
    $displayDate = $this->container->get('date.formatter')->format(
      $localNoon->getTimestamp(), 'custom', 'l j F Y', $model['timezone'],
      $this->container->get('language_manager')->getCurrentLanguage()->getId(),
    );
    $dateContext = $this->assertSession()->elementExists('css', '.ps-today__date-context');
    $this->assertStringContainsString($displayDate, $dateContext->getText());
    $this->assertStringContainsString($model['timezone'], $dateContext->getText());
    $this->assertStringNotContainsString($model['local_date'], $dateContext->getText());
    $this->assertSession()->pageTextContains('Tasks');
    $this->assertSession()->pageTextContains('Preparations');
    $this->assertSession()->pageTextContains('Planning');
    $this->assertSession()->linkExists('My preparations');
    $this->assertSession()->linkByHrefExists('/personal-secretary/preparations/mine');
    $this->assertSession()->pageTextContains('Today route task');
    $this->assertSession()->linkByHrefExists('/personal-secretary/tasks/add');
    // A task opened from Today returns to Today after a read-only detail view.
    $taskDetailArgs = ['task' => (int) $dueToday->id()];
    $taskDetailUrl = Url::fromRoute('personal_secretary.task_status', $taskDetailArgs, ['query' => ['from' => 'today']]);
    $taskEditUrl = Url::fromRoute('personal_secretary.edit_task', $taskDetailArgs, ['query' => ['from' => 'today']]);
    $this->assertSession()->linkByHrefExists($taskDetailUrl->toString());
    // The task title itself is the direct, accessible link to its detail.
    $this->assertSession()->elementExists('css', '.ps-today-section--tasks .ps-task-card__title');
    $this->assertSession()->linkExists('Today route task');
    $this->assertSession()->linkNotExists('Task details');
    $this->clickLink('Today route task');
    $this->assertSession()->addressEquals($taskDetailUrl->toString());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Today route task');
    $this->assertSession()->linkExists('Back to Today');
    $this->assertSession()->linkByHrefExists($taskEditUrl->toString());
    $this->clickLink('Back to Today');
    $this->assertSession()->addressEquals($todayUrl);
    // Both Cancel and Save keep the user on Today, without changing access.
    $this->assertSession()->linkByHrefExists($taskEditUrl->toString());
    $this->drupalGet($taskEditUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->clickLink('Cancel');
    $this->assertSession()->addressEquals($todayUrl);
    $editTaskStorage = $this->container->get('entity_type.manager')->getStorage(PersonalTask::ENTITY_TYPE_ID);
    $editTaskStorage->resetCache([(int) $dueToday->id()]);
    $this->assertSame('Today route task', (string) $editTaskStorage->load($dueToday->id())->get('title')->value);
    $this->drupalGet(Url::fromRoute('personal_secretary.edit_task', $taskDetailArgs, ['query' => ['from' => ['today']]]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkByHrefExists('/personal-secretary/tasks/mine');
    $this->drupalGet($taskEditUrl);
    $this->submitForm([
      'title' => 'Today route task updated',
      'due_mode' => PersonalTask::DUE_DATE,
      'due_date' => $window['local_date'],
    ], 'Save task');
    $this->assertSession()->addressEquals($todayUrl);
    $this->assertSession()->pageTextContains('Today route task updated');
    $editTaskStorage->resetCache([(int) $dueToday->id()]);
    $this->assertSame('Today route task updated', (string) $editTaskStorage->load($dueToday->id())->get('title')->value);
    // An array-valued origin cannot change navigation or grant task access.
    $this->drupalGet(Url::fromRoute('personal_secretary.task_status', $taskDetailArgs, ['query' => ['from' => ['today']]]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Back to My tasks');
    $this->assertSession()->linkByHrefExists('/personal-secretary/tasks/mine');
    $this->drupalGet($todayUrl);
    $this->assertSession()->pageTextContains('Today due preparation');
    $this->assertSession()->pageTextContains('Today active overdue preparation');
    $this->assertSession()->pageTextContains('Today later preparation');
    $this->assertCount(0, $this->getSession()->getPage()->findAll('css', '.ps-today-section--preparations a[href="/personal-secretary/activities/add"]'));
    $preparationGroups = $this->getSession()->getPage()->findAll(
      'css',
      '.ps-today-section--preparations .ps-task-group',
    );
    // At the last minute of a civil day, even the final deadline is already
    // overdue; the single-group presentation remains correct.
    if ($laterDueAt >= $nowUtc) {
      $this->assertCount(2, $preparationGroups);
      $this->assertSame('Overdue preparations', trim(($preparationGroups[0]->find('css', 'h3')?->getText() ?? '')));
      $this->assertSame('Due today', trim(($preparationGroups[1]->find('css', 'h3')?->getText() ?? '')));
      $this->assertStringContainsString('Today active overdue preparation', $preparationGroups[0]->getText());
      $this->assertStringContainsString('Today due preparation', $preparationGroups[0]->getText());
      $this->assertStringNotContainsString('Today later preparation', $preparationGroups[0]->getText());
      $this->assertStringContainsString('Today later preparation', $preparationGroups[1]->getText());
      $this->assertStringNotContainsString('Today active overdue preparation', $preparationGroups[1]->getText());
    }
    else {
      $this->assertCount(0, $preparationGroups);
    }
    // A preparation can open its associated future activity and return
    // here, even when that activity is not part of today's planning.
    $preparationDetailUrl = Url::fromRoute('personal_secretary.occurrence_detail', [
      'series' => (int) $preparationItems['Today due preparation']['_completion_series_id'],
      'original_occurrence_key' => (string) $preparationItems['Today due preparation']['_completion_original_occurrence_key'],
    ], ['query' => ['from' => 'today']]);
    $this->assertSession()->linkByHrefExists($preparationDetailUrl->toString());
    $countsBeforePreparationDetail = $this->domainCounts();
    $this->drupalGet($preparationDetailUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Today preparation related activity');
    $this->assertSession()->linkExists('Back to Today');
    $this->clickLink('Back to Today');
    $this->assertSession()->addressEquals($todayUrl);
    $this->assertSame($countsBeforePreparationDetail, $this->domainCounts());

    $this->assertSession()->pageTextContains('Today authorized UTC activity');
    // A Today card reaches the exact authorized occurrence and returns here.
    $detailRoute = 'personal_secretary.occurrence_detail';
    $detailArgs = [
      'series' => (int) $authorizedActivity->id(),
      'original_occurrence_key' => $authorizedOccurrence->originalOccurrenceKey,
    ];
    $detailUrl = Url::fromRoute($detailRoute, $detailArgs, ['query' => ['from' => 'today']]);
    $this->assertSession()->linkByHrefExists($detailUrl->toString());
    $this->drupalGet($detailUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Today authorized UTC activity');
    $this->assertSession()->linkExists('Back to Today');
    $this->clickLink('Back to Today');
    $this->assertSession()->addressEquals($todayUrl);
    $crossDetailUrl = Url::fromRoute($detailRoute, [
      'series' => (int) $crossActivity->id(),
      'original_occurrence_key' => $crossOccurrence->originalOccurrenceKey,
    ], ['query' => ['from' => 'today']]);
    $this->assertSession()->linkByHrefExists($crossDetailUrl->toString());
    $this->drupalGet($crossDetailUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Today cross-midnight activity');
    $this->clickLink('Back to Today');
    $this->assertSession()->addressEquals($todayUrl);
    // A forged array origin must keep the ordinary My upcoming return.
    $this->drupalGet(Url::fromRoute($detailRoute, $detailArgs, ['query' => ['from' => ['today']]]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Back to My upcoming');
    $this->drupalGet($todayUrl);
    $this->assertSession()->pageTextContains('Today cross-midnight activity');
    $this->assertSession()->pageTextContains('Today responsibility to current');
    $this->assertSession()->pageTextNotContains('Today unauthorized H2 activity');
    $this->assertSession()->pageTextNotContains('Today cancelled activity');
    $this->assertSession()->pageTextNotContains('Today responsibility away');
    $this->assertSession()->pageTextNotContains('Today future preparation excluded');
    $this->assertSession()->pageTextNotContains('Today started preparation excluded');
    $this->assertSession()->pageTextNotContains('Today unauthorized H2 preparation');
    $this->assertSession()->pageTextContains('Europe/Brussels');
    $this->assertSession()->pageTextContains('External Today appointment');
    $this->assertSession()->pageTextContains('External Today all day');
    $this->assertSession()->pageTextContains('Google Calendar');
    $this->assertSession()->pageTextContains('Read-only external event');
    $externalCards = $this->getSession()->getPage()->findAll(
      'css',
      '.personal-secretary-external-event',
    );
    $this->assertCount(2, $externalCards);
    foreach ($externalCards as $externalCard) {
      $this->assertSame([], $externalCard->findAll('css', 'a, button, input[type="submit"]'));
    }

    $headings = array_map(static fn($heading): string => trim($heading->getText()), $this->getSession()->getPage()->findAll('css', 'h2'));
    $headings = array_values(array_filter($headings, static fn(string $heading): bool => in_array($heading, ['Tasks', 'Preparations', 'Planning'], TRUE)));
    $this->assertSame(['Tasks', 'Preparations', 'Planning'], $headings);
    $this->assertSame($countsBefore, $this->domainCounts());

    // Creating an activity is a direct Today action; Cancel never writes.
    $todayAddActivityUrl = Url::fromRoute('personal_secretary.add_activity', [], ['query' => ['from' => 'today']]);
    $this->assertSession()->linkByHrefExists($todayAddActivityUrl->toString());
    $this->clickLink('Add activity');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->buttonExists('Add activity');
    $this->assertSession()->linkExists('Cancel');
    $this->clickLink('Cancel');
    $this->assertSession()->addressEquals($todayUrl);
    $this->assertSame($countsBefore, $this->domainCounts());

    // The Add task shortcut keeps the Today origin through Cancel and POST.
    $todayAddTaskUrl = Url::fromRoute('personal_secretary.add_task', [], ['query' => ['from' => 'today']]);
    $this->assertSession()->linkByHrefExists($todayAddTaskUrl->toString());
    $this->drupalGet($todayAddTaskUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->clickLink('Cancel');
    $this->assertSession()->addressEquals($todayUrl);
    $this->assertSame($countsBefore, $this->domainCounts());
    $this->drupalGet($todayAddTaskUrl);
    $this->submitForm([
      'title' => 'Synthetic Today added task',
      'due_mode' => PersonalTask::DUE_NONE,
    ], 'Add task');
    $this->assertSession()->addressEquals($todayUrl);
    $this->assertSame($countsBefore['task'] + 1, $this->domainCounts()['task']);
    $this->assertSession()->pageTextNotContains('Synthetic Today added task');
    $this->drupalGet('/personal-secretary/tasks/mine');
    $this->assertSession()->pageTextContains('Synthetic Today added task');
    // A forged array origin cannot redirect a task creation flow to Today.
    $this->drupalGet(Url::fromRoute('personal_secretary.add_task', [], ['query' => ['from' => ['today']]]));
    $this->clickLink('Cancel');
    $this->assertSession()->addressEquals('/personal-secretary/tasks/mine');
    $this->drupalGet($todayUrl);

    // A saved activity originating from Today returns there; direct creation
    // retains its existing My upcoming destination.
    $seriesBeforeAdd = $this->domainCounts()['series'];
    $this->drupalGet($todayAddActivityUrl);
    $this->submitForm([
      'activity_type' => 'one_off',
      'time_mode' => ActivitySeries::TIME_MODE_TIMED,
      'activity_label' => 'Synthetic Today added activity',
      'first_occurrence_date' => $localStart->modify('+3 days')->format('Y-m-d'),
      'start_local_time' => '14:00',
      'end_local_time' => '15:00',
      'source_timezone' => 'Europe/Brussels',
    ], 'Add activity');
    $this->assertSession()->addressEquals($todayUrl);
    $this->assertSession()->pageTextContains('Activity "Synthetic Today added activity" added.');
    $this->assertSame($seriesBeforeAdd + 1, $this->domainCounts()['series']);
    $this->drupalGet('/personal-secretary/upcoming/mine');
    $this->assertSession()->pageTextContains('Synthetic Today added activity');

    $this->drupalGet(Url::fromRoute('personal_secretary.add_activity', [], ['query' => ['from' => ['today']]]));
    $this->submitForm([
      'activity_type' => 'one_off',
      'time_mode' => ActivitySeries::TIME_MODE_TIMED,
      'activity_label' => 'Synthetic ordinary added activity',
      'first_occurrence_date' => $localStart->modify('+4 days')->format('Y-m-d'),
      'start_local_time' => '14:00',
      'end_local_time' => '15:00',
      'source_timezone' => 'Europe/Brussels',
    ], 'Add activity');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming/mine');
    $this->assertSame($seriesBeforeAdd + 2, $this->domainCounts()['series']);
    $this->drupalGet($todayUrl);

    $taskStorage = $this->container->get('entity_type.manager')->getStorage(PersonalTask::ENTITY_TYPE_ID);
    $taskId = (int) $dueToday->id();
    // Visiting the action route is read-only; only the Form API POST mutates.
    $this->drupalGet('/personal-secretary/today/tasks/' . $taskId . '/complete');
    $this->assertSession()->statusCodeEquals(200);
    $taskStorage->resetCache([$taskId]);
    $this->assertSame(PersonalTask::STATUS_OPEN, (string) $taskStorage->load($taskId)->get('status')->value);

    $this->drupalGet($todayUrl);
    $this->assertSession()->buttonExists('Mark complete');
    $this->submitForm([], 'Mark complete');
    $this->assertSession()->addressEquals('/personal-secretary/today?reopen_task=' . $taskId);
    $this->assertSession()->pageTextNotContains('Today route task');
    $this->assertSession()->pageTextContains('Today due preparation');
    $this->assertSession()->buttonExists('Reopen task');
    $taskStorage->resetCache([$taskId]);
    $this->assertSame(PersonalTask::STATUS_COMPLETED, (string) $taskStorage->load($taskId)->get('status')->value);

    $this->submitForm([], 'Reopen task');
    $this->assertSession()->addressEquals($todayUrl);
    $this->assertSession()->pageTextContains('Today route task');
    $taskStorage->resetCache([$taskId]);
    $this->assertSame(PersonalTask::STATUS_OPEN, (string) $taskStorage->load($taskId)->get('status')->value);

    // Planning keeps ISO values only in semantic datetime attributes while
    // presenting timed and all-day cards in the visitor's interface language.
    if (ConfigurableLanguage::load('fr') === NULL) {
      ConfigurableLanguage::createFromLangcode('fr')->save();
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
        'language-url' => 0, 'language-user' => 1, 'language-selected' => 2,
      ])
      ->save();
    $this->container->get('language_manager')->reset();
    $formatter = $this->container->get('date.formatter');
    foreach (['fr', 'en'] as $langcode) {
      $this->drupalGet('/' . $langcode . '/personal-secretary/today');
      $planning = $this->assertSession()->elementExists('css', '.ps-today-section--activities');
      $this->assertDoesNotMatchRegularExpression('/\\b\\d{4}-\\d{2}-\\d{2}(?: \\d{2}:\\d{2})?\\b/', $planning->getText());

      $allDay = $this->assertSession()->elementExists('css', '.personal-secretary-external-event time[datetime="' . $window['local_date'] . '"]');
      $this->assertSame($window['local_date'], $allDay->getAttribute('datetime'));
      $this->assertSame(
        $formatter->format($localNoon->getTimestamp(), 'custom', 'j M Y', 'Europe/Brussels', $langcode),
        trim($allDay->getText()),
      );

      $nativeCard = $this->getSession()->getPage()->find(
        'xpath',
        '//article[contains(@class, "personal-secretary-today-activity")][h3[normalize-space(.)="Today authorized UTC activity"]]',
      );
      $this->assertNotNull($nativeCard);
      $nativeStart = $nativeCard->find('css', 'time');
      $this->assertNotNull($nativeStart);
      $this->assertSame($activities['Today authorized UTC activity']['effective_start_iso'], $nativeStart->getAttribute('datetime'));
      $this->assertSame(
        $formatter->format($insideStart->getTimestamp(), 'custom', 'j M Y, H:i', 'Europe/Brussels', $langcode),
        trim($nativeStart->getText()),
      );

      $externalTimed = $planning->findAll('css', '.personal-secretary-external-event time[datetime*="T"]');
      $this->assertCount(2, $externalTimed);
      $this->assertSame(
        $formatter->format($window['utc_start']->modify('+6 hours')->getTimestamp(), 'custom', 'j M Y, H:i', 'Europe/Brussels', $langcode),
        trim($externalTimed[0]->getText()),
      );
      $this->assertMatchesRegularExpression('/^\\d{4}-\\d{2}-\\d{2}T/', (string) $externalTimed[0]->getAttribute('datetime'));
    }
    $this->config('system.site')->set('default_langcode', 'en')->save();
    $this->container->get('language_manager')->reset();
    $this->drupalLogout();

    $this->drupalLogin($noGrant);
    $this->drupalGet($todayUrl);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/personal-secretary/today/tasks/' . $taskId . '/complete');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogout();

    $this->drupalLogin($unlinked);
    $this->drupalGet($todayUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Link your account to a valid Household member to see Today.');
    $this->drupalGet('/personal-secretary/today/tasks/' . $taskId . '/reopen');
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalGet($todayUrl . '?reopen_task=' . $taskId);
    $this->assertSession()->buttonNotExists('Reopen task');
    foreach (['Today route task', 'Today authorized UTC activity', 'Today cross-midnight activity', 'Today responsibility to current', 'Today due preparation', 'Today active overdue preparation', 'Today later preparation'] as $leakedText) {
      $this->assertSession()->pageTextNotContains($leakedText);
    }
  }

  public function testTodayTasksSeparateUrgencyOnlyWhenBothGroupsExist(): void {
    $this->installUserReferenceField(CurrentPersonResolver::FIELD_NAME, 'personal_secretary_person', 1, 'Personal Secretary person');
    $this->installUserReferenceField(HouseholdAuthorizationService::FIELD_NAME, 'personal_secretary_household', FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED, 'Personal Secretary households');

    $domain = $this->container->get('personal_secretary.domain_mutation');
    $mutations = $this->container->get('personal_secretary.personal_task_mutation');
    $person = $domain->createPerson('Synthetic Today urgency person');
    $otherPerson = $domain->createPerson('Synthetic Today private urgency person');
    $household = $domain->createHousehold('Synthetic Today urgency household', [(int) $person->id()]);
    $otherHousehold = $domain->createHousehold('Synthetic Today private urgency household', [(int) $otherPerson->id()]);
    $user = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $user);
    $user->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $person->id()]);
    $user->set(HouseholdAuthorizationService::FIELD_NAME, [['target_id' => (int) $household->id()]]);
    $user->set('timezone', 'Europe/Brussels');
    $user->save();
    $foreignUser = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $foreignUser);
    $foreignUser->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $otherPerson->id()]);
    $foreignUser->set(HouseholdAuthorizationService::FIELD_NAME, [['target_id' => (int) $otherHousehold->id()]]);
    $foreignUser->save();

    $this->drupalLogin($user);
    $url = '/personal-secretary/today';
    $this->drupalGet($url);
    $this->assertSession()->pageTextContains('No tasks are due or overdue today.');
    $this->assertSession()->pageTextContains('Preparations are added through activities. You can include a preparation when adding an activity.');
    $this->assertCount(1, $this->getSession()->getPage()->findAll('css', '.ps-today-section--preparations a[href="/personal-secretary/activities/add?from=today"]'));
    $this->clickLink('Add an activity to prepare');
    $this->assertSession()->addressEquals('/personal-secretary/activities/add?from=today');
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet($url);
    $this->assertSame([], $this->getSession()->getPage()->findAll('css', '.ps-task-group__heading'));

    $nowUtc = new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime());
    $window = $this->container->get('personal_secretary.today')->windowFor($user, $nowUtc);
    $olderDate = $window['local_start']->modify('-2 days')->format('Y-m-d');
    $overdueDate = $window['local_start']->modify('-1 day')->format('Y-m-d');
    $tomorrowDate = $window['local_start']->modify('+1 day')->format('Y-m-d');
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($user);
    try {
      $oldest = $mutations->createTask('Synthetic Today oldest overdue', (int) $household->id(), PersonalTask::DUE_DATE, $olderDate);
      $recent = $mutations->createTask('Synthetic Today recent overdue', (int) $household->id(), PersonalTask::DUE_DATE, $overdueDate);
      $mutations->createTask('Synthetic Today future excluded', (int) $household->id(), PersonalTask::DUE_DATE, $tomorrowDate);
    }
    finally {
      $switcher->switchBack();
    }
    $switcher->switchTo($foreignUser);
    try {
      $mutations->createTask('Synthetic Today private excluded', (int) $otherHousehold->id(), PersonalTask::DUE_DATE, $overdueDate);
    }
    finally {
      $switcher->switchBack();
    }

    // A single non-empty group keeps the original flat rendering.
    $this->drupalGet($url);
    $this->assertSame([], $this->getSession()->getPage()->findAll('css', '.ps-task-group__heading'));
    $this->assertCount(2, $this->getSession()->getPage()->findAll('css', '.ps-task-card'));

    $switcher->switchTo($user);
    try {
      $firstToday = $mutations->createTask('Synthetic Today first due', (int) $household->id(), PersonalTask::DUE_DATE, $window['local_date']);
      $secondToday = $mutations->createTask('Synthetic Today second due', (int) $household->id(), PersonalTask::DUE_DATE, $window['local_date']);
    }
    finally {
      $switcher->switchBack();
    }
    $this->drupalGet($url);
    $groups = $this->getSession()->getPage()->findAll('css', '.ps-task-groups > .ps-task-group');
    $this->assertCount(2, $groups);
    $this->assertSame('Overdue tasks', trim($groups[0]->find('css', 'h3.ps-task-group__heading')->getText()));
    $this->assertSame('Due today', trim($groups[1]->find('css', 'h3.ps-task-group__heading')->getText()));
    $this->assertSame(['Synthetic Today oldest overdue', 'Synthetic Today recent overdue'], array_map(
      static fn ($node): string => trim($node->getText()), $groups[0]->findAll('css', '.ps-task-card strong'),
    ));
    $this->assertSame(['Synthetic Today first due', 'Synthetic Today second due'], array_map(
      static fn ($node): string => trim($node->getText()), $groups[1]->findAll('css', '.ps-task-card strong'),
    ));
    $this->assertSession()->pageTextNotContains('Synthetic Today private excluded');
    $this->assertSession()->pageTextNotContains('Synthetic Today future excluded');
    $this->assertSession()->linkByHrefExists('/personal-secretary/tasks/' . $oldest->id());
    $this->assertSession()->linkByHrefExists('/personal-secretary/tasks/' . $firstToday->id());
    $this->assertSession()->linkByHrefExists('/personal-secretary/tasks/add');

    $this->submitForm([], 'Mark complete');
    $this->assertSession()->buttonExists('Reopen task');
    $this->assertSession()->pageTextNotContains('Synthetic Today oldest overdue');
    $taskStorage = $this->container->get('entity_type.manager')->getStorage(PersonalTask::ENTITY_TYPE_ID);
    $taskStorage->resetCache([(int) $oldest->id()]);
    $this->assertSame(PersonalTask::STATUS_COMPLETED, (string) $taskStorage->load($oldest->id())->get('status')->value);
    $this->submitForm([], 'Reopen task');
    $this->assertSession()->pageTextContains('Synthetic Today oldest overdue');

    if (ConfigurableLanguage::load('fr') === NULL) {
      ConfigurableLanguage::createFromLangcode('fr')->save();
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
        'language-url' => 0, 'language-user' => 1, 'language-selected' => 2,
      ])
      ->save();
    $this->container->get('language_manager')->reset();
    $this->drupalGet('/fr/personal-secretary/today');
    $this->assertSession()->pageTextContains('Tâches en retard');
    $this->assertSession()->pageTextContains('À faire aujourd’hui');
    $this->drupalGet('/en/personal-secretary/today');
    $this->assertSession()->pageTextContains('Overdue tasks');
    $this->assertSession()->pageTextContains('Due today');

    $switcher->switchTo($user);
    try {
      $this->assertTrue($mutations->completeTask((int) $oldest->id()));
      $this->assertTrue($mutations->completeTask((int) $recent->id()));
    }
    finally {
      $switcher->switchBack();
    }
    $this->drupalGet('/en/personal-secretary/today');
    $this->assertSame([], $this->getSession()->getPage()->findAll('css', '.ps-task-group__heading'));
    $this->assertCount(2, $this->getSession()->getPage()->findAll('css', '.ps-task-card'));
    $switcher->switchTo($user);
    try {
      $this->assertTrue($mutations->completeTask((int) $firstToday->id()));
      $this->assertTrue($mutations->completeTask((int) $secondToday->id()));
    }
    finally {
      $switcher->switchBack();
    }
    $this->drupalGet('/en/personal-secretary/today');
    $this->assertSession()->pageTextContains('No tasks are due or overdue today.');
    $this->assertSame([], $this->getSession()->getPage()->findAll('css', '.ps-task-group__heading'));
  }

  private function createSeriesWithRule(string $label, int $householdId, int $responsiblePersonId, DateTimeImmutable $start, DateTimeImmutable $end): ActivitySeries {
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $responsibilityMutations = $this->container->get('personal_secretary.responsibility_mutation');
    $series = $domain->createActivitySeries($label, $householdId, $start, $end, 'FREQ=DAILY;COUNT=1');
    $responsibilityMutations->createResponsibilityRule($series, $responsiblePersonId, $start, $end, 'FREQ=DAILY;COUNT=1');
    return $series;
  }

  private function activitiesByLabel(array $activities): array {
    $indexed = [];
    foreach ($activities as $activity) {
      $indexed[(string) $activity['activity_label']] = $activity;
    }
    return $indexed;
  }

  private function preparationsByInstruction(array $preparations): array {
    $indexed = [];
    foreach ($preparations as $preparation) {
      $indexed[(string) $preparation['instruction']] = $preparation;
    }
    return $indexed;
  }

  private function domainCounts(): array {
    $manager = $this->container->get('entity_type.manager');
    return [
      'task' => count($manager->getStorage(PersonalTask::ENTITY_TYPE_ID)->loadMultiple()),
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
