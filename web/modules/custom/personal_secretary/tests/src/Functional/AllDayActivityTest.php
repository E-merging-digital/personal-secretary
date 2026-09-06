<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\personal_secretary\Entity\ActivityException;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\TimeCommitmentRule;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Value\EffectiveResponsibility;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use InvalidArgumentException;

/**
 * Proves DST-safe all-day creation, projection, presentation and guards.
 *
 * @group personal_secretary
 */
final class AllDayActivityTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testAllDayCreationPresentationAndTimedRegression(): void {
    $this->installUserPersonFieldViaEntityApi();

    $domain = $this->container->get('personal_secretary.domain_mutation');
    $baseProjection = $this->container->get('personal_secretary.occurrence_projection');
    $effectiveProjection = $this->container->get('personal_secretary.effective_occurrence_projection');
    $effectiveResponsibility = $this->container->get('personal_secretary.effective_responsibility');
    $preparationEligibility = $this->container->get('personal_secretary.preparation_eligibility');
    $rescheduleOccurrence = $this->container->get('personal_secretary.reschedule_occurrence');

    $utc = new DateTimeZone('UTC');
    $brussels = new DateTimeZone('Europe/Brussels');
    $nowUtc = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))->setTimezone($utc);
    $nowLocal = $nowUtc->setTimezone($brussels);

    $currentPerson = $domain->createPerson('Synthetic current person');
    $concernedPerson = $domain->createPerson('Synthetic concerned child');
    $household = $domain->createHousehold('Synthetic all-day household', [
      (int) $currentPerson->id(),
      (int) $concernedPerson->id(),
    ]);

    $authorized = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->assertInstanceOf(UserInterface::class, $authorized);
    $authorized->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $currentPerson->id()]);
    $authorized->set('timezone', 'Europe/Brussels');
    $authorized->save();
    $this->drupalLogin($authorized);

    $this->drupalGet('/personal-secretary/activities/add');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Horaire');
    $this->assertSession()->pageTextContains('Toute la journée');
    $this->assertSession()->fieldValueEquals('time_mode', ActivitySeries::TIME_MODE_TIMED);
    $this->assertSession()->fieldExists('all_day_end_date');

    $timedStart = $nowLocal->modify('+1 day')->setTime(9, 15);
    $this->submitForm([
      'household_id' => (string) $household->id(),
      'activity_type' => 'one_off',
      'time_mode' => ActivitySeries::TIME_MODE_TIMED,
      'responsible_person_id' => (string) $currentPerson->id(),
      'activity_label' => 'Synthetic timed regression',
      'location' => '',
      'first_occurrence_date' => $timedStart->format('Y-m-d'),
      'start_local_time' => '09:15',
      'end_local_time' => '10:45',
      'source_timezone' => 'Europe/Brussels',
      'preparation_instruction' => '',
      'preparation_lead_minutes' => '0',
    ], 'Add activity');
    $timed = $this->seriesByLabel('Synthetic timed regression');
    $this->assertSame(ActivitySeries::TIME_MODE_TIMED, $timed->timeMode());
    $timedOccurrence = $baseProjection->project(
      $timed,
      $timedStart->setTimezone($utc)->modify('-1 minute'),
      $timedStart->modify('+2 hours')->setTimezone($utc),
      2,
    )[0];
    $this->assertSame('09:15', (new DateTimeImmutable($timedOccurrence->sourceLocalStart))->format('H:i'));
    $this->assertSame('10:45', (new DateTimeImmutable($timedOccurrence->sourceLocalEnd))->format('H:i'));
    $timedRescheduleUrl = Url::fromRoute('personal_secretary.reschedule_occurrence', [
      'series' => (int) $timed->id(),
      'original_occurrence_key' => $timedOccurrence->originalOccurrenceKey,
    ])->toString();
    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertUpcomingArticleContains('Synthetic timed regression', ['09:15', '10:45']);
    $this->assertUpcomingArticleExcludes('Synthetic timed regression', 'Toute la journée');
    $this->assertSession()->linkByHrefExists($timedRescheduleUrl);
    $this->drupalGet($timedRescheduleUrl);
    $this->assertSession()->statusCodeEquals(200);

    $oneDayDate = $nowLocal->modify('+2 days')->format('Y-m-d');
    $this->drupalGet('/personal-secretary/activities/add');
    $this->submitForm([
      'household_id' => (string) $household->id(),
      'activity_type' => 'one_off',
      'time_mode' => ActivitySeries::TIME_MODE_ALL_DAY,
      'responsible_person_id' => (string) $currentPerson->id(),
      'concerned_person_ids[' . $concernedPerson->id() . ']' => (string) $concernedPerson->id(),
      'activity_label' => 'Synthetic all-day one-off',
      'location' => 'Synthetic library',
      'first_occurrence_date' => $oneDayDate,
      'all_day_end_date' => '',
      'source_timezone' => 'Europe/Brussels',
      'preparation_instruction' => 'Synthetic prepare bag',
      'preparation_lead_minutes' => '120',
    ], 'Add activity');
    $oneDay = $this->seriesByLabel('Synthetic all-day one-off');
    $this->assertSame(ActivitySeries::TIME_MODE_ALL_DAY, $oneDay->timeMode());
    $this->assertSame('FREQ=DAILY;COUNT=1', $this->rrule($oneDay));
    $this->assertSame('Synthetic library', (string) $oneDay->get('location')->value);
    $this->assertSame([(int) $concernedPerson->id()], $this->concernedIds($oneDay));

    $oneDayStart = new DateTimeImmutable($oneDayDate . ' 00:00:00', $brussels);
    $oneDayEnd = $oneDayStart->modify('+1 day');
    $oneDayBase = $baseProjection->project(
      $oneDay,
      $oneDayStart->setTimezone($utc)->modify('-1 second'),
      $oneDayEnd->setTimezone($utc)->modify('+1 second'),
      2,
    );
    $this->assertCount(1, $oneDayBase);
    $this->assertSame($oneDayStart->format(DateTimeInterface::ATOM), $oneDayBase[0]->sourceLocalStart);
    $this->assertSame($oneDayEnd->format(DateTimeInterface::ATOM), $oneDayBase[0]->sourceLocalEnd);
    $this->assertSame(
      $oneDayStart->setTimezone($utc)->format('Y-m-d\\TH:i:s\\Z'),
      $oneDayBase[0]->originalOccurrenceKey,
    );

    $oneDayEffective = $effectiveProjection->project(
      $oneDay,
      $oneDayStart->setTimezone($utc)->modify('-1 second'),
      $oneDayEnd->setTimezone($utc)->modify('+1 second'),
      2,
    );
    $this->assertCount(1, $oneDayEffective);
    $responsibility = $effectiveResponsibility->resolve($oneDay, $oneDayEffective[0]);
    $this->assertSame(EffectiveResponsibility::STATE_ASSIGNED, $responsibility->state);
    $this->assertSame((int) $currentPerson->id(), $responsibility->responsiblePersonId);
    $preparations = $preparationEligibility->derive($oneDay, $oneDayEffective[0]);
    $this->assertCount(1, $preparations);
    $this->assertSame(
      $oneDayStart->modify('-2 hours')->setTimezone($utc)->format(DateTimeInterface::ATOM),
      $preparations[0]->dueAtUtc,
    );

    $rescheduleUrl = Url::fromRoute('personal_secretary.reschedule_occurrence', [
      'series' => (int) $oneDay->id(),
      'original_occurrence_key' => $oneDayBase[0]->originalOccurrenceKey,
    ])->toString();
    $cancelUrl = Url::fromRoute('personal_secretary.cancel_occurrence', [
      'series' => (int) $oneDay->id(),
      'original_occurrence_key' => $oneDayBase[0]->originalOccurrenceKey,
    ])->toString();
    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertUpcomingArticleContains(
      'Synthetic all-day one-off',
      ['Toute la journée', $oneDayDate, 'Synthetic library', 'Synthetic concerned child', 'Synthetic prepare bag'],
    );
    $this->assertUpcomingArticleExcludes('Synthetic all-day one-off', '00:00');
    $this->assertSession()->linkByHrefNotExists($rescheduleUrl);
    $this->assertSession()->linkByHrefExists($cancelUrl);
    $this->drupalGet($rescheduleUrl);
    $this->assertSession()->statusCodeEquals(404);
    try {
      $rescheduleOccurrence->resolve((int) $oneDay->id(), $oneDayBase[0]->originalOccurrenceKey);
      $this->fail('ALL_DAY reschedule domain resolution must fail closed.');
    }
    catch (InvalidArgumentException) {
      $this->addToAssertionCount(1);
    }
  }

  public function testAllDayDstRecurrenceCompositionAndFilters(): void {
    $this->installUserPersonFieldViaEntityApi();

    $domain = $this->container->get('personal_secretary.domain_mutation');
    $addActivity = $this->container->get('personal_secretary.add_activity');
    $baseProjection = $this->container->get('personal_secretary.occurrence_projection');
    $effectiveProjection = $this->container->get('personal_secretary.effective_occurrence_projection');
    $timeCommitmentMutation = $this->container->get('personal_secretary.time_commitment_mutation');
    $calendarEligibility = $this->container->get('personal_secretary.calendar_eligibility');
    $cancelOccurrence = $this->container->get('personal_secretary.cancel_occurrence');
    $scheduleEditor = $this->container->get('personal_secretary.edit_recurring_schedule');

    $utc = new DateTimeZone('UTC');
    $brussels = new DateTimeZone('Europe/Brussels');
    $nowUtc = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))->setTimezone($utc);
    $nowLocal = $nowUtc->setTimezone($brussels);

    $currentPerson = $domain->createPerson('Synthetic DST current person');
    $concernedPerson = $domain->createPerson('Synthetic DST concerned person');
    $household = $domain->createHousehold('Synthetic DST household', [
      (int) $currentPerson->id(),
      (int) $concernedPerson->id(),
    ]);

    $authorized = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->assertInstanceOf(UserInterface::class, $authorized);
    $authorized->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $currentPerson->id()]);
    $authorized->set('timezone', 'Europe/Brussels');
    $authorized->save();
    $this->drupalLogin($authorized);

    $springStart = new DateTimeImmutable('2026-03-29 00:00:00', $brussels);
    $spring = $domain->createActivitySeries(
      'Synthetic spring DST all-day', (int) $household->id(), $springStart, $springStart->modify('+1 day'),
      'FREQ=DAILY;COUNT=1', '', [], ActivitySeries::TIME_MODE_ALL_DAY,
    );
    $springOccurrence = $baseProjection->project(
      $spring,
      $springStart->setTimezone($utc)->modify('-1 second'),
      $springStart->modify('+2 days')->setTimezone($utc),
      2,
    )[0];
    $this->assertSame(23 * 3600, $this->elapsedSeconds($springOccurrence->utcStart, $springOccurrence->utcEnd));
    $this->assertSame('2026-03-29 00:00', (new DateTimeImmutable($springOccurrence->sourceLocalStart))->format('Y-m-d H:i'));
    $this->assertSame('2026-03-30 00:00', (new DateTimeImmutable($springOccurrence->sourceLocalEnd))->format('Y-m-d H:i'));

    $autumnStart = new DateTimeImmutable('2026-10-25 00:00:00', $brussels);
    $autumn = $domain->createActivitySeries(
      'Synthetic autumn DST all-day', (int) $household->id(), $autumnStart, $autumnStart->modify('+1 day'),
      'FREQ=DAILY;COUNT=1', '', [], ActivitySeries::TIME_MODE_ALL_DAY,
    );
    $autumnOccurrence = $baseProjection->project(
      $autumn,
      $autumnStart->setTimezone($utc)->modify('-1 second'),
      $autumnStart->modify('+2 days')->setTimezone($utc),
      2,
    )[0];
    $this->assertSame(25 * 3600, $this->elapsedSeconds($autumnOccurrence->utcStart, $autumnOccurrence->utcEnd));

    $weeklyStart = new DateTimeImmutable('2026-03-15 00:00:00', $brussels);
    $weekly = $domain->createActivitySeries(
      'Synthetic weekly DST all-day', (int) $household->id(), $weeklyStart, $weeklyStart->modify('+1 day'),
      'FREQ=WEEKLY;INTERVAL=1', '', [], ActivitySeries::TIME_MODE_ALL_DAY,
    );
    $weeklyOccurrences = $baseProjection->project(
      $weekly,
      $weeklyStart->setTimezone($utc)->modify('-1 second'),
      (new DateTimeImmutable('2026-04-06 00:00:00', $brussels))->setTimezone($utc),
    );
    $this->assertSame(
      ['2026-03-15 00:00', '2026-03-22 00:00', '2026-03-29 00:00', '2026-04-05 00:00'],
      array_map(static fn($occurrence): string => (new DateTimeImmutable($occurrence->sourceLocalStart))->format('Y-m-d H:i'), $weeklyOccurrences),
    );
    foreach ($weeklyOccurrences as $occurrence) {
      $localStart = new DateTimeImmutable($occurrence->sourceLocalStart);
      $localEnd = new DateTimeImmutable($occurrence->sourceLocalEnd);
      $this->assertSame('00:00', $localStart->format('H:i'));
      $this->assertSame('00:00', $localEnd->format('H:i'));
      $this->assertSame($localStart->modify('+1 day')->format('Y-m-d'), $localEnd->format('Y-m-d'));
      $this->assertSame($localStart->setTimezone($utc)->format('Y-m-d\\TH:i:s\\Z'), $occurrence->originalOccurrenceKey);
    }

    $multiStart = new DateTimeImmutable('2026-03-28 00:00:00', $brussels);
    $multi = $domain->createActivitySeries(
      'Synthetic multi-day DST all-day', (int) $household->id(), $multiStart,
      new DateTimeImmutable('2026-03-31 00:00:00', $brussels),
      'FREQ=DAILY;COUNT=1', '', [], ActivitySeries::TIME_MODE_ALL_DAY,
    );
    $multiOccurrence = $baseProjection->project(
      $multi,
      $multiStart->setTimezone($utc)->modify('-1 second'),
      (new DateTimeImmutable('2026-04-01 00:00:00', $brussels))->setTimezone($utc),
      2,
    )[0];
    $this->assertSame(3, $multi->allDayCivilDaySpan());
    $this->assertSame('2026-03-28 00:00', (new DateTimeImmutable($multiOccurrence->sourceLocalStart))->format('Y-m-d H:i'));
    $this->assertSame('2026-03-31 00:00', (new DateTimeImmutable($multiOccurrence->sourceLocalEnd))->format('Y-m-d H:i'));
    $this->assertSame(71 * 3600, $this->elapsedSeconds($multiOccurrence->utcStart, $multiOccurrence->utcEnd));

    $futureMultiStart = $nowLocal->modify('+3 days')->setTime(0, 0);
    $futureMultiEnd = $futureMultiStart->modify('+3 days');
    $futureMulti = $addActivity->addOneOffActivity(
      (int) $household->id(), (int) $currentPerson->id(), 'Synthetic future multi-day all-day',
      $futureMultiStart, $futureMultiEnd, '', 0, 'Synthetic park',
      [(int) $concernedPerson->id()], ActivitySeries::TIME_MODE_ALL_DAY,
    );
    $this->assertSame('FREQ=DAILY;COUNT=1', $this->rrule($futureMulti));
    $this->assertSame(3, $futureMulti->allDayCivilDaySpan());

    $futureWeeklyStart = $nowLocal->modify('+1 day')->setTime(0, 0);
    $futureWeekly = $addActivity->addWeeklyActivity(
      (int) $household->id(), (int) $currentPerson->id(), 'Synthetic future weekly all-day',
      $futureWeeklyStart, $futureWeeklyStart->modify('+1 day'), '', 0, '', [], ActivitySeries::TIME_MODE_ALL_DAY,
    );
    $this->assertSame('FREQ=WEEKLY;INTERVAL=1', $this->rrule($futureWeekly));
    $scheduleUrl = Url::fromRoute('personal_secretary.edit_recurring_schedule', [
      'series' => (int) $futureWeekly->id(),
    ])->toString();
    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertUpcomingArticleContains('Synthetic future weekly all-day', ['Toute la journée']);
    $this->assertSession()->linkByHrefNotExists($scheduleUrl);

    $concernedOnlyStart = $nowLocal->modify('+2 days')->setTime(0, 0);
    $addActivity->addOneOffActivity(
      (int) $household->id(), NULL, 'Synthetic concerned-only all-day',
      $concernedOnlyStart, $concernedOnlyStart->modify('+1 day'), '', 0, '',
      [(int) $currentPerson->id()], ActivitySeries::TIME_MODE_ALL_DAY,
    );
    $this->drupalGet('/personal-secretary/upcoming/mine');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Synthetic future weekly all-day');
    $this->assertSession()->pageTextContains('Toute la journée');
    $this->assertSession()->pageTextNotContains('Synthetic concerned-only all-day');

    $todayStart = $nowLocal->setTime(0, 0);
    $todayAssigned = $addActivity->addOneOffActivity(
      (int) $household->id(), (int) $currentPerson->id(), 'Synthetic Today all-day overlap',
      $todayStart->modify('-1 day'), $todayStart->modify('+2 days'), '', 0,
      'Synthetic Today location', [(int) $concernedPerson->id()], ActivitySeries::TIME_MODE_ALL_DAY,
    );
    $addActivity->addOneOffActivity(
      (int) $household->id(), NULL, 'Synthetic Today concerned-only all-day',
      $todayStart, $todayStart->modify('+1 day'), '', 0, '', [(int) $currentPerson->id()],
      ActivitySeries::TIME_MODE_ALL_DAY,
    );
    $this->drupalGet('/personal-secretary/today');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertTodayArticleContains(
      'Synthetic Today all-day overlap',
      ['Toute la journée', 'Synthetic Today location', 'Synthetic DST concerned person'],
    );
    $this->assertTodayArticleExcludes('Synthetic Today all-day overlap', '00:00');
    $this->assertSession()->pageTextNotContains('Synthetic Today concerned-only all-day');

    $futureOccurrence = $effectiveProjection->project(
      $futureMulti,
      $futureMultiStart->setTimezone($utc)->modify('-1 second'),
      $futureMultiEnd->setTimezone($utc)->modify('+1 second'),
      2,
    )[0];
    $commitment = $timeCommitmentMutation->createFullOccurrenceCommitment(
      $futureMulti,
      $futureMultiStart->modify('-1 hour')->setTimezone($utc),
    );
    $this->assertSame(TimeCommitmentRule::MODE_FULL_OCCURRENCE, (string) $commitment->get('mode')->value);
    $candidate = $calendarEligibility->evaluate($futureMulti, $futureOccurrence, $currentPerson);
    $this->assertNotNull($candidate);
    $this->assertSame($futureOccurrence->effectiveUtcStart, $candidate->effectiveUtcStart);
    $this->assertSame($futureOccurrence->effectiveUtcEnd, $candidate->effectiveUtcEnd);
    $this->assertSame($futureOccurrence->originalOccurrenceKey, $candidate->originalOccurrenceKey);

    $cancelStart = $nowLocal->modify('+5 days')->setTime(0, 0);
    $cancelSeries = $domain->createActivitySeries(
      'Synthetic cancel all-day', (int) $household->id(), $cancelStart, $cancelStart->modify('+1 day'),
      'FREQ=DAILY;COUNT=1', '', [], ActivitySeries::TIME_MODE_ALL_DAY,
    );
    $cancelBase = $baseProjection->project(
      $cancelSeries,
      $cancelStart->setTimezone($utc)->modify('-1 second'),
      $cancelStart->modify('+2 days')->setTimezone($utc),
      2,
    )[0];
    $exception = $cancelOccurrence->cancel((int) $cancelSeries->id(), $cancelBase->originalOccurrenceKey);
    $this->assertSame(ActivityException::ACTION_CANCEL, (string) $exception->get('action')->value);
    $this->assertSame($cancelBase->originalOccurrenceKey, (string) $exception->get('original_occurrence_key')->value);
    $this->assertCount(0, $effectiveProjection->project(
      $cancelSeries,
      $cancelStart->setTimezone($utc)->modify('-1 second'),
      $cancelStart->modify('+2 days')->setTimezone($utc),
    ));

    try {
      $scheduleEditor->context((int) $futureWeekly->id());
      $this->fail('ALL_DAY recurring schedule context must be rejected.');
    }
    catch (InvalidArgumentException) {
      $this->addToAssertionCount(1);
    }
    $this->drupalGet($scheduleUrl);
    $this->assertSession()->statusCodeEquals(404);

    try {
      $domain->createActivitySeries(
        'Synthetic invalid mode', (int) $household->id(), $futureWeeklyStart,
        $futureWeeklyStart->modify('+1 day'), 'FREQ=DAILY;COUNT=1', '', [], 'floating',
      );
      $this->fail('Unsupported ActivitySeries time mode must be rejected.');
    }
    catch (InvalidArgumentException) {
      $this->addToAssertionCount(1);
    }

    $this->assertNotSame('', $todayAssigned->uuid());
  }

  private function seriesByLabel(string $label): ActivitySeries {
    foreach ($this->container->get('entity_type.manager')->getStorage('personal_sec_activity_series')->loadMultiple() as $series) {
      if ($series instanceof ActivitySeries && $series->label() === $label) {
        return $series;
      }
    }
    throw new \RuntimeException(sprintf('ActivitySeries %s was not found.', $label));
  }

  private function rrule(ActivitySeries $series): string {
    return (string) ($series->get('recurrence')->first()?->getValue()['rrule'] ?? '');
  }

  private function concernedIds(ActivitySeries $series): array {
    return array_map(
      static fn(array $item): int => (int) ($item['target_id'] ?? 0),
      $series->get('concerned_persons')->getValue(),
    );
  }

  private function elapsedSeconds(string $start, string $end): int {
    return (new DateTimeImmutable($end))->getTimestamp() - (new DateTimeImmutable($start))->getTimestamp();
  }

  private function assertUpcomingArticleContains(string $activityLabel, array $needles): void {
    $this->assertArticleContains('article.personal-secretary-upcoming-activity', 'h2', $activityLabel, $needles);
  }

  private function assertUpcomingArticleExcludes(string $activityLabel, string $needle): void {
    $this->assertArticleExcludes('article.personal-secretary-upcoming-activity', 'h2', $activityLabel, $needle);
  }

  private function assertTodayArticleContains(string $activityLabel, array $needles): void {
    $this->assertArticleContains('article.personal-secretary-today-activity', 'h3', $activityLabel, $needles);
  }

  private function assertTodayArticleExcludes(string $activityLabel, string $needle): void {
    $this->assertArticleExcludes('article.personal-secretary-today-activity', 'h3', $activityLabel, $needle);
  }

  private function assertArticleContains(string $selector, string $headingSelector, string $activityLabel, array $needles): void {
    foreach ($this->getSession()->getPage()->findAll('css', $selector) as $article) {
      $heading = $article->find('css', $headingSelector);
      if ($heading !== NULL && trim($heading->getText()) === $activityLabel) {
        foreach ($needles as $needle) {
          $this->assertStringContainsString($needle, $article->getText());
        }
        return;
      }
    }
    $this->fail(sprintf('Activity article %s was not found.', $activityLabel));
  }

  private function assertArticleExcludes(string $selector, string $headingSelector, string $activityLabel, string $needle): void {
    foreach ($this->getSession()->getPage()->findAll('css', $selector) as $article) {
      $heading = $article->find('css', $headingSelector);
      if ($heading !== NULL && trim($heading->getText()) === $activityLabel) {
        $this->assertStringNotContainsString($needle, $article->getText());
        return;
      }
    }
    $this->fail(sprintf('Activity article %s was not found.', $activityLabel));
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

}
