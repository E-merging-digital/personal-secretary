<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\PersonalTask;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;

/**
 * Proves the read-only Today surface for authorized tasks and activities.
 *
 * @group personal_secretary
 */
final class TodayViewTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testTodayComposesCurrentAuthorizedTruthWithoutPreparations(): void {
    $todayUrl = '/personal-secretary/today';
    $this->drupalGet($todayUrl);
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

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\ResponsibilityMutationService $responsibilityMutations */
    $responsibilityMutations = $this->container->get('personal_secretary.responsibility_mutation');
    /** @var \Drupal\personal_secretary\Service\PreparationRequirementMutationService $preparationMutations */
    $preparationMutations = $this->container->get('personal_secretary.preparation_requirement_mutation');
    /** @var \Drupal\personal_secretary\Service\ActivityExceptionService $exceptions */
    $exceptions = $this->container->get('personal_secretary.activity_exception');
    /** @var \Drupal\personal_secretary\Service\RevisionTimelineService $timeline */
    $timeline = $this->container->get('personal_secretary.revision_timeline');
    /** @var \Drupal\personal_secretary\Service\EffectiveOccurrenceProjectionService $effective */
    $effective = $this->container->get('personal_secretary.effective_occurrence_projection');
    /** @var \Drupal\personal_secretary\Service\PersonalTaskMutationService $taskMutations */
    $taskMutations = $this->container->get('personal_secretary.personal_task_mutation');
    /** @var \Drupal\personal_secretary\Service\TodayService $today */
    $today = $this->container->get('personal_secretary.today');

    $person = $domain->createPerson('Synthetic Today current person');
    $otherPerson = $domain->createPerson('Synthetic Today other person');
    $h1 = $domain->createHousehold(
      'Synthetic Today authorized household',
      [(int) $person->id(), (int) $otherPerson->id()],
    );
    $h2 = $domain->createHousehold(
      'Synthetic Today unauthorized household',
      [(int) $person->id(), (int) $otherPerson->id()],
    );

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
      $nowUtc = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
        ->setTimezone(new \DateTimeZone('UTC'));
      $window = $today->windowFor($authorized, $nowUtc);
      $localStart = $window['local_start'];
      $localEnd = $window['local_end'];

      $insideStart = $window['utc_start']->modify('+4 hours');
      $insideSeries = $this->createSeriesWithRule(
        'Today authorized UTC activity',
        (int) $h1->id(),
        (int) $person->id(),
        $insideStart,
        $insideStart->modify('+1 hour'),
      );
      $preparationMutations->createPreparationRequirement(
        $insideSeries,
        'Preparation must stay out of Today',
        1800,
        $window['utc_start']->modify('-1 day'),
      );

      $h2Start = $window['utc_start']->modify('+5 hours');
      $this->createSeriesWithRule(
        'Today unauthorized H2 activity',
        (int) $h2->id(),
        (int) $person->id(),
        $h2Start,
        $h2Start->modify('+1 hour'),
      );

      $crossStart = $localStart->modify('-1 hour');
      $this->createSeriesWithRule(
        'Today cross-midnight activity',
        (int) $h1->id(),
        (int) $person->id(),
        $crossStart,
        $localStart->modify('+1 hour'),
      );

      $cancelStart = $localStart->modify('+8 hours');
      $cancelled = $this->createSeriesWithRule(
        'Today cancelled activity',
        (int) $h1->id(),
        (int) $person->id(),
        $cancelStart,
        $cancelStart->modify('+1 hour'),
      );
      $cancelTarget = $timeline->projectBaseWindow(
        $cancelled,
        $window['utc_start'],
        $window['utc_end'],
      )[0];
      $exceptions->createCancel($cancelled, $cancelTarget);

      $toCurrentStart = $localStart->modify('+10 hours');
      $toCurrent = $this->createSeriesWithRule(
        'Today responsibility to current',
        (int) $h1->id(),
        (int) $otherPerson->id(),
        $toCurrentStart,
        $toCurrentStart->modify('+1 hour'),
      );
      $toCurrentOccurrence = $effective->projectOverlapping(
        $toCurrent,
        $window['utc_start'],
        $window['utc_end'],
      )[0];
      $responsibilityMutations->createAssignOverride(
        $toCurrent,
        $toCurrentOccurrence,
        (int) $person->id(),
      );

      $awayStart = $localStart->modify('+12 hours');
      $away = $this->createSeriesWithRule(
        'Today responsibility away',
        (int) $h1->id(),
        (int) $person->id(),
        $awayStart,
        $awayStart->modify('+1 hour'),
      );
      $awayOccurrence = $effective->projectOverlapping(
        $away,
        $window['utc_start'],
        $window['utc_end'],
      )[0];
      $responsibilityMutations->createAssignOverride(
        $away,
        $awayOccurrence,
        (int) $otherPerson->id(),
      );

      $dueToday = $taskMutations->createTask(
        'Today route task',
        (int) $h1->id(),
        PersonalTask::DUE_DATE,
        $window['local_date'],
      );

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
      $this->assertLessThan($localEnd, $localStart, 'The viewer local Today start is before the next local midnight.');
    }
    finally {
      $accountSwitcher->switchBack();
    }

    $this->drupalLogin($authorized);
    $countsBefore = $this->domainCounts();

    $this->drupalGet($todayUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Tasks');
    $this->assertSession()->pageTextContains('Activities');
    $this->assertSession()->pageTextContains('Today route task');
    $this->assertSession()->pageTextContains('Today authorized UTC activity');
    $this->assertSession()->pageTextContains('Today cross-midnight activity');
    $this->assertSession()->pageTextContains('Today responsibility to current');
    $this->assertSession()->pageTextNotContains('Today unauthorized H2 activity');
    $this->assertSession()->pageTextNotContains('Today cancelled activity');
    $this->assertSession()->pageTextNotContains('Today responsibility away');
    $this->assertSession()->pageTextNotContains('Preparation must stay out of Today');
    $this->assertSession()->pageTextNotContains('To prepare');
    $this->assertSession()->pageTextContains('Europe/Brussels');
    $this->assertSame($countsBefore, $this->domainCounts(), 'GET Today does not mutate domain state.');

    $accountSwitcher->switchTo($authorized);
    try {
      $this->assertTrue($taskMutations->completeTask((int) $dueToday->id()));
    }
    finally {
      $accountSwitcher->switchBack();
    }
    $this->drupalGet($todayUrl);
    $this->assertSession()->pageTextNotContains('Today route task');

    $accountSwitcher->switchTo($authorized);
    try {
      $this->assertTrue($taskMutations->reopenTask((int) $dueToday->id()));
    }
    finally {
      $accountSwitcher->switchBack();
    }
    $this->drupalGet($todayUrl);
    $this->assertSession()->pageTextContains('Today route task');
    $this->drupalLogout();

    $this->drupalLogin($noGrant);
    $this->drupalGet($todayUrl);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogout();

    $this->drupalLogin($unlinked);
    $this->drupalGet($todayUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Link your account to a valid Household member to see Today.');
    foreach ([
      'Today route task',
      'Today authorized UTC activity',
      'Today cross-midnight activity',
      'Today responsibility to current',
      'Preparation must stay out of Today',
    ] as $leakedText) {
      $this->assertSession()->pageTextNotContains($leakedText);
    }
  }

  private function createSeriesWithRule(
    string $label,
    int $householdId,
    int $responsiblePersonId,
    DateTimeImmutable $start,
    DateTimeImmutable $end,
  ): ActivitySeries {
    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\ResponsibilityMutationService $responsibilityMutations */
    $responsibilityMutations = $this->container->get('personal_secretary.responsibility_mutation');

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
   * @param array<int, array<string, mixed>> $activities
   *
   * @return array<string, array<string, mixed>>
   */
  private function activitiesByLabel(array $activities): array {
    $indexed = [];
    foreach ($activities as $activity) {
      $indexed[(string) $activity['activity_label']] = $activity;
    }
    return $indexed;
  }

  /**
   * @return array<string, int>
   */
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
      'settings' => [
        'handler' => 'default:' . $targetType,
        'handler_settings' => [],
      ],
    ])->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

}
