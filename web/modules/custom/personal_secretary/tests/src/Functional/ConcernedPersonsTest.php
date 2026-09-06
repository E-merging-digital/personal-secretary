<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\ResponsibilityRule;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;

/**
 * Proves concerned Persons creation, presentation and filter independence.
 *
 * @group personal_secretary
 */
final class ConcernedPersonsTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testConcernedPersonsCreationPresentationAndIndependence(): void {
    $this->installUserPersonFieldViaEntityApi();

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\AddActivityService $addActivity */
    $addActivity = $this->container->get('personal_secretary.add_activity');
    $manager = $this->container->get('entity_type.manager');

    $jonathan = $domain->createPerson('Synthetic Jonathan');
    $eva = $domain->createPerson('Synthetic Eva');
    $barbara = $domain->createPerson('Synthetic Barbara');
    $foreign = $domain->createPerson('Synthetic Foreign Person');
    $household = $domain->createHousehold('Synthetic concerned household', [
      (int) $jonathan->id(),
      (int) $eva->id(),
      (int) $barbara->id(),
    ]);
    $domain->createHousehold('Synthetic foreign household', [(int) $foreign->id()]);

    $authorized = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->assertInstanceOf(UserInterface::class, $authorized);
    $authorized->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $jonathan->id()]);
    $authorized->set('timezone', 'Europe/Brussels');
    $authorized->save();
    $this->drupalLogin($authorized);

    $sourceTimezone = new DateTimeZone('Europe/Brussels');
    $nowUtc = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone(new DateTimeZone('UTC'));
    $nowLocal = $nowUtc->setTimezone($sourceTimezone);

    $this->drupalGet('/personal-secretary/activities/add');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Personnes concernées');
    $this->assertSession()->fieldExists('concerned_person_ids[' . $eva->id() . ']');
    $this->assertSession()->fieldExists('concerned_person_ids[' . $barbara->id() . ']');

    $weeklyStart = $nowLocal->modify('+1 day')->setTime(9, 0);
    $this->submitForm([
      'household_id' => (string) $household->id(),
      'activity_type' => 'weekly',
      'responsible_person_id' => (string) $jonathan->id(),
      'concerned_person_ids[' . $eva->id() . ']' => (string) $eva->id(),
      'concerned_person_ids[' . $barbara->id() . ']' => (string) $barbara->id(),
      'activity_label' => 'Synthetic concerned weekly',
      'location' => '',
      'first_occurrence_date' => $weeklyStart->format('Y-m-d'),
      'start_local_time' => '09:00',
      'end_local_time' => '10:00',
      'source_timezone' => 'Europe/Brussels',
      'preparation_instruction' => '',
      'preparation_lead_minutes' => '0',
    ], 'Add activity');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertUpcomingArticleContains(
      'Synthetic concerned weekly',
      ['Pour', 'Synthetic Eva', 'Synthetic Barbara'],
    );

    $weekly = $this->seriesByLabel('Synthetic concerned weekly');
    $expectedMultiple = [(int) $eva->id(), (int) $barbara->id()];
    sort($expectedMultiple, SORT_NUMERIC);
    $this->assertSame($expectedMultiple, $this->concernedIds($weekly));
    $weeklyRuleIds = $this->idsForSeries('personal_sec_resp_rule', (int) $weekly->id());
    $this->assertCount(1, $weeklyRuleIds);
    $weeklyRule = $manager->getStorage('personal_sec_resp_rule')->load(reset($weeklyRuleIds));
    $this->assertInstanceOf(ResponsibilityRule::class, $weeklyRule);
    $this->assertSame((int) $jonathan->id(), (int) $weeklyRule->get('responsible_person')->target_id);
    $this->assertCount(0, $this->idsForSeries('personal_sec_resp_override', (int) $weekly->id()));

    $oneOffStart = $nowLocal->modify('+2 days')->setTime(11, 0);
    $this->drupalGet('/personal-secretary/activities/add');
    $this->submitForm([
      'household_id' => (string) $household->id(),
      'activity_type' => 'one_off',
      'responsible_person_id' => '',
      'concerned_person_ids[' . $eva->id() . ']' => (string) $eva->id(),
      'activity_label' => 'Synthetic concerned one-off unassigned',
      'location' => '',
      'first_occurrence_date' => $oneOffStart->format('Y-m-d'),
      'start_local_time' => '11:00',
      'end_local_time' => '12:00',
      'source_timezone' => 'Europe/Brussels',
      'preparation_instruction' => '',
      'preparation_lead_minutes' => '0',
    ], 'Add activity');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertUpcomingArticleContains(
      'Synthetic concerned one-off unassigned',
      ['Pour', 'Synthetic Eva'],
    );

    $oneOff = $this->seriesByLabel('Synthetic concerned one-off unassigned');
    $this->assertSame([(int) $eva->id()], $this->concernedIds($oneOff));
    $this->assertCount(0, $this->idsForSeries('personal_sec_resp_rule', (int) $oneOff->id()));
    $this->assertCount(0, $this->idsForSeries('personal_sec_resp_override', (int) $oneOff->id()));
    $this->assertSame('FREQ=DAILY;COUNT=1', (string) ($oneOff->get('recurrence')->first()?->getValue()['rrule'] ?? ''));

    $emptyStart = $nowLocal->modify('+3 days')->setTime(13, 0);
    $this->drupalGet('/personal-secretary/activities/add');
    $this->submitForm([
      'household_id' => (string) $household->id(),
      'activity_type' => 'one_off',
      'responsible_person_id' => '',
      'activity_label' => 'Synthetic empty concerned one-off',
      'location' => '',
      'first_occurrence_date' => $emptyStart->format('Y-m-d'),
      'start_local_time' => '13:00',
      'end_local_time' => '14:00',
      'source_timezone' => 'Europe/Brussels',
      'preparation_instruction' => '',
      'preparation_lead_minutes' => '0',
    ], 'Add activity');
    $empty = $this->seriesByLabel('Synthetic empty concerned one-off');
    $this->assertTrue($empty->get('concerned_persons')->isEmpty());
    $this->assertUpcomingArticleExcludes('Synthetic empty concerned one-off', 'Pour');

    $beforeForeignAttempt = count($manager->getStorage('personal_sec_activity_series')->loadMultiple());
    $foreignStart = $nowLocal->modify('+4 days')->setTime(15, 0);
    $this->drupalGet('/personal-secretary/activities/add');
    $this->submitForm([
      'household_id' => (string) $household->id(),
      'activity_type' => 'one_off',
      'responsible_person_id' => '',
      'concerned_person_ids[' . $foreign->id() . ']' => (string) $foreign->id(),
      'activity_label' => 'Synthetic rejected foreign concerned',
      'location' => '',
      'first_occurrence_date' => $foreignStart->format('Y-m-d'),
      'start_local_time' => '15:00',
      'end_local_time' => '16:00',
      'source_timezone' => 'Europe/Brussels',
      'preparation_instruction' => '',
      'preparation_lead_minutes' => '0',
    ], 'Add activity');
    $this->assertSession()->addressEquals('/personal-secretary/activities/add');
    $this->assertSession()->pageTextContains('The activity could not be created for the selected household and options.');
    $this->assertSame($beforeForeignAttempt, count($manager->getStorage('personal_sec_activity_series')->loadMultiple()));

    $duplicateStart = $nowLocal->modify('+5 days')->setTime(16, 0);
    $normalized = $domain->createActivitySeries(
      'Synthetic normalized concerned IDs',
      (int) $household->id(),
      $duplicateStart,
      $duplicateStart->modify('+1 hour'),
      'FREQ=DAILY;COUNT=1',
      '',
      [(int) $barbara->id(), (int) $eva->id(), (int) $eva->id()],
    );
    $this->assertSame($expectedMultiple, $this->concernedIds($normalized));

    $this->drupalGet('/personal-secretary/upcoming/mine');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Synthetic concerned weekly');
    $this->assertSession()->pageTextContains('Synthetic Eva');
    $this->assertSession()->pageTextContains('Synthetic Barbara');
    $this->assertSession()->pageTextNotContains('Synthetic concerned one-off unassigned');

    $todayAssignedStart = $nowLocal->setTime(12, 0);
    $todayAssigned = $addActivity->addOneOffActivity(
      (int) $household->id(),
      (int) $jonathan->id(),
      'Synthetic Today assigned concerned',
      $todayAssignedStart,
      $todayAssignedStart->modify('+1 hour'),
      '',
      0,
      '',
      [(int) $eva->id()],
    );
    $this->assertSame([(int) $eva->id()], $this->concernedIds($todayAssigned));

    $todayUnassignedStart = $nowLocal->setTime(14, 0);
    $todayUnassigned = $addActivity->addOneOffActivity(
      (int) $household->id(),
      NULL,
      'Synthetic Today concerned-only current Person',
      $todayUnassignedStart,
      $todayUnassignedStart->modify('+1 hour'),
      '',
      0,
      '',
      [(int) $jonathan->id()],
    );
    $this->assertCount(0, $this->idsForSeries('personal_sec_resp_rule', (int) $todayUnassigned->id()));
    $this->assertCount(0, $this->idsForSeries('personal_sec_resp_override', (int) $todayUnassigned->id()));

    $this->drupalGet('/personal-secretary/today');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertTodayArticleContains(
      'Synthetic Today assigned concerned',
      ['Pour', 'Synthetic Eva'],
    );
    $this->assertSession()->pageTextNotContains('Synthetic Today concerned-only current Person');

    $originalRevisionId = (int) $weekly->getRevisionId();
    $originalConcerned = $this->concernedIds($weekly);
    $revisionStart = $weeklyStart->modify('+8 days')->setTime(9, 30);
    $revised = $domain->updateActivitySeriesRecurrence(
      $weekly,
      $revisionStart,
      $revisionStart->modify('+1 hour'),
      'FREQ=WEEKLY;INTERVAL=1',
      $revisionStart,
    );
    $this->assertNotSame($originalRevisionId, (int) $revised->getRevisionId());
    $this->assertSame($originalConcerned, $this->concernedIds($revised));
    $historical = $manager->getStorage('personal_sec_activity_series')->loadRevision($originalRevisionId);
    $this->assertInstanceOf(ActivitySeries::class, $historical);
    $this->assertSame($originalConcerned, $this->concernedIds($historical));
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
    return array_map(
      static fn(array $item): int => (int) ($item['target_id'] ?? 0),
      $series->get('concerned_persons')->getValue(),
    );
  }

  /**
   * @return int[]
   */
  private function idsForSeries(string $entityTypeId, int $seriesId): array {
    return array_values(array_map(
      'intval',
      $this->container->get('entity_type.manager')
        ->getStorage($entityTypeId)
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('series', $seriesId)
        ->execute(),
    ));
  }

  /**
   * @param string[] $needles
   */
  private function assertUpcomingArticleContains(string $activityLabel, array $needles): void {
    $this->assertArticleContains('article.personal-secretary-upcoming-activity', 'h2', $activityLabel, $needles);
  }

  private function assertUpcomingArticleExcludes(string $activityLabel, string $needle): void {
    foreach ($this->getSession()->getPage()->findAll('css', 'article.personal-secretary-upcoming-activity') as $article) {
      $heading = $article->find('css', 'h2');
      if ($heading !== NULL && trim($heading->getText()) === $activityLabel) {
        $this->assertStringNotContainsString($needle, $article->getText());
        return;
      }
    }
    $this->fail(sprintf('Upcoming article %s was not found.', $activityLabel));
  }

  /**
   * @param string[] $needles
   */
  private function assertTodayArticleContains(string $activityLabel, array $needles): void {
    $this->assertArticleContains('article.personal-secretary-today-activity', 'h3', $activityLabel, $needles);
  }

  /**
   * @param string[] $needles
   */
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
