<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\personal_secretary\Entity\ActivityException;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\Household;
use Drupal\personal_secretary\Entity\Person;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;

/**
 * Proves bounded ordinary-user self-reschedule authority and fresh submit races.
 *
 * @group personal_secretary
 */
final class AuthorizedOccurrenceRescheduleTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testAuthorizedWeeklyRescheduleAndSubmitRaces(): void {
    $this->installUserScopeFields();

    $domain = $this->container->get('personal_secretary.domain_mutation');
    $responsibility = $this->container->get('personal_secretary.responsibility_mutation');
    $effective = $this->container->get('personal_secretary.effective_occurrence_projection');
    $cancel = $this->container->get('personal_secretary.cancel_occurrence');
    $exceptions = $this->container->get('personal_secretary.activity_exception');
    $entityTypeManager = $this->container->get('entity_type.manager');

    $timezone = new DateTimeZone('Europe/Brussels');
    $utc = new DateTimeZone('UTC');
    $nowUtc = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone($utc);
    $nowLocal = $nowUtc->setTimezone($timezone);

    $current = $domain->createPerson('Reschedule Current Person');
    $other = $domain->createPerson('Reschedule Other Person');
    $outsider = $domain->createPerson('Reschedule Outside Person');
    $h1 = $domain->createHousehold(
      'Reschedule Household H1',
      [(int) $current->id(), (int) $other->id()],
    );
    $h2 = $domain->createHousehold(
      'Secret Reschedule Household H2',
      [(int) $outsider->id()],
    );

    [$weekly, $weeklyTarget] = $this->createSeriesWithResponsibility(
      'Authorized weekly reschedule',
      $h1,
      $current,
      $nowLocal->modify('+1 day')->setTime(9, 0),
      'FREQ=WEEKLY;INTERVAL=1',
    );
    [$secret, $secretTarget] = $this->createSeriesWithResponsibility(
      'Secret H2 reschedule target',
      $h2,
      $outsider,
      $nowLocal->modify('+2 days')->setTime(10, 0),
      'FREQ=DAILY;COUNT=1',
    );
    [$unassigned, $unassignedTarget] = $this->createSeriesWithoutResponsibility(
      'Unassigned reschedule target',
      $h1,
      $nowLocal->modify('+3 days')->setTime(11, 0),
      'FREQ=DAILY;COUNT=1',
    );

    $weeklyUrl = $this->rescheduleUrl($weekly, $weeklyTarget->originalOccurrenceKey);
    $this->drupalGet($weeklyUrl);
    $this->assertSession()->statusCodeEquals(403);

    $withoutPermission = $this->createScopedUser($current, [(int) $h1->id()], FALSE);
    $this->drupalLogin($withoutPermission);
    $this->drupalGet($weeklyUrl);
    $this->assertSession()->statusCodeEquals(403);

    $withoutGrant = $this->createScopedUser($current, [(int) $h2->id()], TRUE);
    $this->drupalLogout();
    $this->drupalLogin($withoutGrant);
    $this->drupalGet($weeklyUrl);
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Authorized weekly reschedule');

    $withoutCurrentPerson = $this->createScopedUser(NULL, [(int) $h1->id()], TRUE);
    $this->drupalLogout();
    $this->drupalLogin($withoutCurrentPerson);
    $this->drupalGet($weeklyUrl);
    $this->assertSession()->statusCodeEquals(404);

    $notMember = $this->createScopedUser($outsider, [(int) $h1->id()], TRUE);
    $this->drupalLogout();
    $this->drupalLogin($notMember);
    $this->drupalGet($weeklyUrl);
    $this->assertSession()->statusCodeEquals(404);

    $authorized = $this->createScopedUser($current, [(int) $h1->id()], TRUE);
    $this->drupalLogout();
    $this->drupalLogin($authorized);

    $secretUrl = $this->rescheduleUrl($secret, $secretTarget->originalOccurrenceKey);
    $this->drupalGet($secretUrl);
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Secret H2 reschedule target');
    $this->assertSession()->pageTextNotContains($secretTarget->sourceLocalStart);

    $this->drupalGet($this->rescheduleUrl($unassigned, $unassignedTarget->originalOccurrenceKey));
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Unassigned reschedule target');

    [$allDay, $allDayTarget] = $this->createAllDaySeriesWithResponsibility(
      'Authorized all-day no self-reschedule',
      $h1,
      $current,
      $nowLocal->modify('+4 days')->setTime(0, 0),
    );
    $allDayUrl = $this->rescheduleUrl($allDay, $allDayTarget->originalOccurrenceKey);

    $this->drupalGet('/personal-secretary/upcoming/mine');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Add activity');
    $this->assertSession()->linkExists('Cancel occurrence');
    $this->assertSession()->linkExists('Reschedule occurrence');
    $this->assertSession()->linkByHrefExists($weeklyUrl);
    $this->assertSession()->linkByHrefNotExists($allDayUrl);
    foreach ([
      'Change recurring schedule',
      'Change recurring responsibility',
      'Change time commitment',
      'Pause recurring activity',
      'Change responsibility',
    ] as $adminOnlyLink) {
      $this->assertSession()->linkNotExists($adminOnlyLink);
    }

    $this->drupalGet($weeklyUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Authorized weekly reschedule');
    $this->assertSession()->pageTextContains('Source timezone');
    $this->assertSession()->pageTextContains('Europe/Brussels');
    $this->drupalGet($allDayUrl);
    $this->assertSession()->statusCodeEquals(404);

    $seriesCount = count($entityTypeManager->getStorage('personal_sec_activity_series')->loadMultiple());
    $oldDisplay = (new DateTimeImmutable($weeklyTarget->sourceLocalStart))->format('Y-m-d H:i');
    $newStart = (new DateTimeImmutable($weeklyTarget->sourceLocalStart))->modify('+30 minutes');
    $newEnd = $newStart->modify('+1 hour');
    $newDisplay = $newStart->format('Y-m-d H:i');

    $this->drupalGet($weeklyUrl);
    $this->submitForm([
      'new_date' => $newStart->format('Y-m-d'),
      'new_local_start_time' => $newStart->format('H:i'),
      'new_local_end_time' => $newEnd->format('H:i'),
    ], 'Reschedule occurrence');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming/mine');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains($oldDisplay);
    $this->assertSession()->pageTextContains($newDisplay);
    $this->assertCount(
      $seriesCount,
      $entityTypeManager->getStorage('personal_sec_activity_series')->loadMultiple(),
    );

    $reschedules = $this->activeReschedules();
    $this->assertCount(1, $reschedules);
    $this->assertSame($weeklyTarget->originalOccurrenceKey, (string) $reschedules[0]->get('original_occurrence_key')->value);
    $this->assertSame('Europe/Brussels', (string) $reschedules[0]->get('source_timezone')->value);
    $this->assertSame(
      $newStart->setTimezone($utc)->format('Y-m-d\\TH:i:s'),
      (string) $reschedules[0]->get('rescheduled_utc_start')->value,
    );
    $this->assertSame(
      $newEnd->setTimezone($utc)->format('Y-m-d\\TH:i:s'),
      (string) $reschedules[0]->get('rescheduled_utc_end')->value,
    );

    $weeklyWindowStart = (new DateTimeImmutable($weeklyTarget->utcStart))->modify('-1 hour');
    $weeklyWindowEnd = $weeklyWindowStart->modify('+15 days');
    $weeklyEffective = $effective->project($weekly, $weeklyWindowStart, $weeklyWindowEnd);
    $weeklyKeys = array_map(
      static fn($occurrence): string => $occurrence->originalOccurrenceKey,
      $weeklyEffective,
    );
    $this->assertContains($weeklyTarget->originalOccurrenceKey, $weeklyKeys);
    $this->assertNotEmpty(
      array_filter(
        $weeklyKeys,
        static fn(string $key): bool => $key !== $weeklyTarget->originalOccurrenceKey,
      ),
      'Rescheduling one Weekly occurrence must preserve a sibling occurrence.',
    );

    [$grantRace, $grantRaceTarget] = $this->createSeriesWithResponsibility(
      'Grant race reschedule target',
      $h1,
      $current,
      $nowLocal->modify('+5 days')->setTime(8, 0),
      'FREQ=DAILY;COUNT=1',
    );
    $grantRaceUrl = $this->rescheduleUrl($grantRace, $grantRaceTarget->originalOccurrenceKey);
    $this->drupalGet($grantRaceUrl);
    $this->assertSession()->statusCodeEquals(200);
    $persistedUser = $this->reloadUser((int) $authorized->id());
    $persistedUser->set(
      HouseholdAuthorizationService::FIELD_NAME,
      [['target_id' => (int) $h2->id()]],
    );
    $persistedUser->save();
    $this->submitReschedule($grantRaceTarget, 30);
    $this->assertSession()->addressEquals('/personal-secretary/upcoming/mine');
    $this->assertSession()->pageTextContains('This occurrence can no longer be rescheduled.');
    $this->assertCount(1, $this->activeReschedules());

    $persistedUser = $this->reloadUser((int) $authorized->id());
    $persistedUser->set(
      HouseholdAuthorizationService::FIELD_NAME,
      [['target_id' => (int) $h1->id()]],
    );
    $persistedUser->save();

    [$personRace, $personRaceTarget] = $this->createSeriesWithResponsibility(
      'CurrentPerson race reschedule target',
      $h1,
      $current,
      $nowLocal->modify('+6 days')->setTime(8, 30),
      'FREQ=DAILY;COUNT=1',
    );
    $personRaceUrl = $this->rescheduleUrl($personRace, $personRaceTarget->originalOccurrenceKey);
    $this->drupalGet($personRaceUrl);
    $this->assertSession()->statusCodeEquals(200);
    $persistedUser = $this->reloadUser((int) $authorized->id());
    $persistedUser->set(
      CurrentPersonResolver::FIELD_NAME,
      ['target_id' => (int) $other->id()],
    );
    $persistedUser->save();
    $this->submitReschedule($personRaceTarget, 30);
    $this->assertSession()->addressEquals('/personal-secretary/upcoming/mine');
    $this->assertSession()->pageTextContains('This occurrence can no longer be rescheduled.');
    $this->assertCount(1, $this->activeReschedules());

    $persistedUser = $this->reloadUser((int) $authorized->id());
    $persistedUser->set(
      CurrentPersonResolver::FIELD_NAME,
      ['target_id' => (int) $current->id()],
    );
    $persistedUser->save();

    [$responsibilityRace, $responsibilityRaceTarget] = $this->createSeriesWithResponsibility(
      'Responsibility race reschedule target',
      $h1,
      $current,
      $nowLocal->modify('+6 days')->setTime(13, 0),
      'FREQ=DAILY;COUNT=1',
    );
    $responsibilityRaceUrl = $this->rescheduleUrl(
      $responsibilityRace,
      $responsibilityRaceTarget->originalOccurrenceKey,
    );
    $this->drupalGet($responsibilityRaceUrl);
    $this->assertSession()->statusCodeEquals(200);
    $instant = new DateTimeImmutable($responsibilityRaceTarget->utcStart);
    $effectiveTarget = $effective->project(
      $responsibilityRace,
      $instant->modify('-1 second'),
      $instant->modify('+1 second'),
    )[0];
    $responsibility->createAssignOverride(
      $responsibilityRace,
      $effectiveTarget,
      (int) $other->id(),
    );
    $this->submitReschedule($responsibilityRaceTarget, 30);
    $this->assertSession()->addressEquals('/personal-secretary/upcoming/mine');
    $this->assertSession()->pageTextContains('This occurrence can no longer be rescheduled.');
    $this->assertCount(1, $this->activeReschedules());

    [$cancelled, $cancelledTarget] = $this->createSeriesWithResponsibility(
      'Cancelled stale reschedule target',
      $h1,
      $current,
      $nowLocal->modify('+2 days')->setTime(16, 0),
      'FREQ=DAILY;COUNT=1',
    );
    $cancel->cancel((int) $cancelled->id(), $cancelledTarget->originalOccurrenceKey);
    $this->drupalGet($this->rescheduleUrl($cancelled, $cancelledTarget->originalOccurrenceKey));
    $this->assertSession()->statusCodeEquals(404);
    $this->assertCount(1, $this->activeReschedules());

    [$alreadyRescheduled, $alreadyRescheduledTarget] = $this->createSeriesWithResponsibility(
      'Already rescheduled stale target',
      $h1,
      $current,
      $nowLocal->modify('+3 days')->setTime(16, 30),
      'FREQ=DAILY;COUNT=1',
    );
    $existingStartUtc = (new DateTimeImmutable($alreadyRescheduledTarget->utcStart))->modify('+30 minutes');
    $exceptions->createReschedule(
      $alreadyRescheduled,
      $alreadyRescheduledTarget,
      $existingStartUtc,
      $existingStartUtc->modify('+1 hour'),
      $alreadyRescheduledTarget->sourceTimezone,
    );
    $beforeRejectedReschedule = count($this->activeReschedules());
    $this->drupalGet($this->rescheduleUrl(
      $alreadyRescheduled,
      $alreadyRescheduledTarget->originalOccurrenceKey,
    ));
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSame($beforeRejectedReschedule, count($this->activeReschedules()));
  }

  /**
   * @return array{0: ActivitySeries, 1: \Drupal\personal_secretary\Value\BaseOccurrence}
   */
  private function createSeriesWithResponsibility(
    string $label,
    Household $household,
    Person $responsible,
    DateTimeImmutable $localStart,
    string $rrule,
  ): array {
    [$series, $target] = $this->createSeriesWithoutResponsibility(
      $label,
      $household,
      $localStart,
      $rrule,
    );
    $this->container->get('personal_secretary.responsibility_mutation')->createResponsibilityRule(
      $series,
      (int) $responsible->id(),
      $localStart,
      $localStart->modify('+1 hour'),
      $rrule,
    );
    return [$series, $target];
  }

  /**
   * @return array{0: ActivitySeries, 1: \Drupal\personal_secretary\Value\BaseOccurrence}
   */
  private function createSeriesWithoutResponsibility(
    string $label,
    Household $household,
    DateTimeImmutable $localStart,
    string $rrule,
  ): array {
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $series = $domain->createActivitySeries(
      $label,
      (int) $household->id(),
      $localStart,
      $localStart->modify('+1 hour'),
      $rrule,
    );
    $utcStart = $localStart->setTimezone(new DateTimeZone('UTC'));
    $projected = $this->container->get('personal_secretary.occurrence_projection')->project(
      $series,
      $utcStart->modify('-1 minute'),
      $utcStart->modify('+2 hours'),
    );
    $this->assertNotEmpty($projected);
    return [$series, $projected[0]];
  }

  /**
   * @return array{0: ActivitySeries, 1: \Drupal\personal_secretary\Value\BaseOccurrence}
   */
  private function createAllDaySeriesWithResponsibility(
    string $label,
    Household $household,
    Person $responsible,
    DateTimeImmutable $localStart,
  ): array {
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $localEnd = $localStart->modify('+1 day');
    $series = $domain->createActivitySeries(
      $label,
      (int) $household->id(),
      $localStart,
      $localEnd,
      'FREQ=DAILY;COUNT=1',
      '',
      [],
      ActivitySeries::TIME_MODE_ALL_DAY,
    );
    $this->container->get('personal_secretary.responsibility_mutation')->createResponsibilityRule(
      $series,
      (int) $responsible->id(),
      $localStart,
      $localEnd,
      'FREQ=DAILY;COUNT=1',
    );
    $utcStart = $localStart->setTimezone(new DateTimeZone('UTC'));
    $projected = $this->container->get('personal_secretary.occurrence_projection')->project(
      $series,
      $utcStart->modify('-1 minute'),
      $localEnd->setTimezone(new DateTimeZone('UTC'))->modify('+1 minute'),
      2,
    );
    $this->assertNotEmpty($projected);
    return [$series, $projected[0]];
  }

  private function rescheduleUrl(ActivitySeries $series, string $originalOccurrenceKey): string {
    return Url::fromRoute(
      'personal_secretary.reschedule_occurrence',
      [
        'series' => $series->id(),
        'original_occurrence_key' => $originalOccurrenceKey,
      ],
    )->toString();
  }

  private function submitReschedule($target, int $offsetMinutes): void {
    $start = (new DateTimeImmutable($target->sourceLocalStart))->modify(sprintf('+%d minutes', $offsetMinutes));
    $end = $start->modify('+1 hour');
    $this->submitForm([
      'new_date' => $start->format('Y-m-d'),
      'new_local_start_time' => $start->format('H:i'),
      'new_local_end_time' => $end->format('H:i'),
    ], 'Reschedule occurrence');
  }

  /**
   * @return \Drupal\personal_secretary\Entity\ActivityException[]
   */
  private function activeReschedules(): array {
    $reschedules = [];
    $storage = $this->container->get('entity_type.manager')->getStorage('personal_sec_activity_exception');
    foreach ($storage->loadMultiple() as $exception) {
      if (
        $exception instanceof ActivityException
        && (string) $exception->get('action')->value === ActivityException::ACTION_RESCHEDULE
        && (string) $exception->get('status')->value === ActivityException::STATUS_ACTIVE
      ) {
        $reschedules[] = $exception;
      }
    }
    return $reschedules;
  }

  private function createScopedUser(
    ?Person $person,
    array $householdIds,
    bool $withPermission,
  ): UserInterface {
    $permissions = $withPermission ? [HouseholdAuthorizationService::PRODUCT_USE_PERMISSION] : [];
    $user = $this->drupalCreateUser($permissions);
    $this->assertInstanceOf(UserInterface::class, $user);
    if ($person instanceof Person) {
      $user->set(
        CurrentPersonResolver::FIELD_NAME,
        ['target_id' => (int) $person->id()],
      );
    }
    $user->set(
      HouseholdAuthorizationService::FIELD_NAME,
      array_map(
        static fn(int $householdId): array => ['target_id' => $householdId],
        $householdIds,
      ),
    );
    $user->save();
    return $user;
  }

  private function reloadUser(int $uid): UserInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    $storage->resetCache([$uid]);
    $user = $storage->load($uid);
    $this->assertInstanceOf(UserInterface::class, $user);
    return $user;
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
