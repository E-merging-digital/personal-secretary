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
 * Proves bounded current-user self-cancellation authority and fresh races.
 *
 * @group personal_secretary
 */
final class AuthorizedOccurrenceCancellationTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testAccessNonDisclosureSelfCancellationAndAdminPreservation(): void {
    $this->installUserScopeFields();

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\ActivityExceptionService $exceptions */
    $exceptions = $this->container->get('personal_secretary.activity_exception');
    /** @var \Drupal\personal_secretary\Service\EffectiveOccurrenceProjectionService $effective */
    $effective = $this->container->get('personal_secretary.effective_occurrence_projection');

    $timezone = new DateTimeZone('Europe/Brussels');
    $utc = new DateTimeZone('UTC');
    $nowUtc = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone($utc);
    $nowLocal = $nowUtc->setTimezone($timezone);

    $current = $domain->createPerson('Cancellation Current Person');
    $other = $domain->createPerson('Cancellation Other Person');
    $outsider = $domain->createPerson('Cancellation Outside Person');
    $h1 = $domain->createHousehold(
      'Cancellation Household H1',
      [(int) $current->id(), (int) $other->id()],
    );
    $h2 = $domain->createHousehold(
      'Secret Household H2',
      [(int) $outsider->id()],
    );

    [$weekly, $weeklyTarget] = $this->createSeriesWithResponsibility(
      'Authorized weekly cancellation',
      $h1,
      $current,
      $nowLocal->modify('+1 day')->setTime(9, 0),
      'FREQ=WEEKLY;INTERVAL=1',
    );
    [$oneOff, $oneOffTarget] = $this->createSeriesWithResponsibility(
      'Authorized one-off cancellation',
      $h1,
      $current,
      $nowLocal->modify('+2 days')->setTime(14, 0),
      'FREQ=DAILY;COUNT=1',
    );
    [$secret, $secretTarget] = $this->createSeriesWithResponsibility(
      'Secret H2 cancellation target',
      $h2,
      $outsider,
      $nowLocal->modify('+3 days')->setTime(10, 0),
      'FREQ=DAILY;COUNT=1',
    );
    [$unassigned, $unassignedTarget] = $this->createSeriesWithoutResponsibility(
      'Unassigned H1 cancellation target',
      $h1,
      $nowLocal->modify('+4 days')->setTime(11, 0),
      'FREQ=DAILY;COUNT=1',
    );

    $weeklyUrl = $this->cancelUrl($weekly, $weeklyTarget->originalOccurrenceKey);
    $oneOffUrl = $this->cancelUrl($oneOff, $oneOffTarget->originalOccurrenceKey);
    $secretUrl = $this->cancelUrl($secret, $secretTarget->originalOccurrenceKey);
    $unassignedUrl = $this->cancelUrl($unassigned, $unassignedTarget->originalOccurrenceKey);

    $this->drupalGet($weeklyUrl);
    $this->assertSession()->statusCodeEquals(403);

    $withoutPermission = $this->createScopedUser($current, [(int) $h1->id()], FALSE);
    $this->drupalLogin($withoutPermission);
    $this->drupalGet($weeklyUrl);
    $this->assertSession()->statusCodeEquals(403);

    $withoutTargetGrant = $this->createScopedUser($current, [(int) $h2->id()], TRUE);
    $this->drupalLogout();
    $this->drupalLogin($withoutTargetGrant);
    $this->drupalGet($weeklyUrl);
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Authorized weekly cancellation');

    $withoutCurrentPerson = $this->createScopedUser(NULL, [(int) $h1->id()], TRUE);
    $this->drupalLogout();
    $this->drupalLogin($withoutCurrentPerson);
    $this->drupalGet($weeklyUrl);
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Authorized weekly cancellation');

    $notMember = $this->createScopedUser($outsider, [(int) $h1->id()], TRUE);
    $this->drupalLogout();
    $this->drupalLogin($notMember);
    $this->drupalGet($weeklyUrl);
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Authorized weekly cancellation');

    $authorized = $this->createScopedUser($current, [(int) $h1->id()], TRUE);
    $this->drupalLogout();
    $this->drupalLogin($authorized);

    $this->drupalGet($secretUrl);
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Secret H2 cancellation target');
    $this->assertSession()->pageTextNotContains($secretTarget->sourceLocalStart);

    $this->drupalGet($unassignedUrl);
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->pageTextNotContains('Unassigned H1 cancellation target');

    $this->drupalGet('/personal-secretary/upcoming/mine');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Add activity');
    $this->assertSession()->linkByHrefExists('/personal-secretary/activities/add');
    $this->assertSession()->linkExists('Cancel occurrence');
    $this->assertSession()->linkByHrefExists($weeklyUrl);
    $this->assertSession()->linkByHrefExists($oneOffUrl);
    foreach ([
      'Change recurring schedule',
      'Change recurring responsibility',
      'Change time commitment',
      'Pause recurring activity',
      'Change responsibility',
      'Reschedule occurrence',
    ] as $forbiddenOrdinaryLink) {
      $this->assertSession()->linkNotExists($forbiddenOrdinaryLink);
    }

    $this->drupalGet($weeklyUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Cancel occurrence of Authorized weekly cancellation');
    $this->submitForm([], 'Cancel occurrence');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming/mine');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame(1, $this->cancelCount());

    $weeklyWindowStart = (new DateTimeImmutable($weeklyTarget->utcStart))->modify('-1 hour');
    $weeklyWindowEnd = $weeklyWindowStart->modify('+16 days');
    $remainingWeekly = $effective->project($weekly, $weeklyWindowStart, $weeklyWindowEnd);
    $remainingKeys = array_map(
      static fn($occurrence): string => $occurrence->originalOccurrenceKey,
      $remainingWeekly,
    );
    $this->assertNotContains($weeklyTarget->originalOccurrenceKey, $remainingKeys);
    $this->assertNotEmpty($remainingKeys, 'Cancelling one Weekly occurrence must preserve a future sibling.');

    $this->drupalGet($oneOffUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([], 'Cancel occurrence');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming/mine');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('Authorized one-off cancellation');
    $this->assertSame(2, $this->cancelCount());

    $this->drupalGet($oneOffUrl);
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSame(2, $this->cancelCount(), 'A duplicate CANCEL row must not be created.');

    [$rescheduled, $rescheduledTarget] = $this->createSeriesWithResponsibility(
      'Rescheduled target cannot be cancelled',
      $h1,
      $current,
      $nowLocal->modify('+5 days')->setTime(12, 0),
      'FREQ=DAILY;COUNT=1',
    );
    $rescheduledStart = (new DateTimeImmutable($rescheduledTarget->utcStart))->modify('+2 hours');
    $exceptions->createReschedule(
      $rescheduled,
      $rescheduledTarget,
      $rescheduledStart,
      $rescheduledStart->modify('+1 hour'),
      $rescheduledTarget->sourceTimezone,
    );
    $this->drupalGet($this->cancelUrl($rescheduled, $rescheduledTarget->originalOccurrenceKey));
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSame(2, $this->cancelCount());

    [$adminSeries, $adminTarget] = $this->createSeriesWithResponsibility(
      'Admin cancellation preserved',
      $h1,
      $other,
      $nowLocal->modify('+6 days')->setTime(15, 0),
      'FREQ=WEEKLY;INTERVAL=1',
    );
    $admin = $this->drupalCreateUser([HouseholdAuthorizationService::ADMIN_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $admin);
    $this->drupalLogout();
    $this->drupalLogin($admin);
    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    foreach ([
      'Change recurring schedule',
      'Change recurring responsibility',
      'Change time commitment',
      'Pause recurring activity',
      'Change responsibility',
      'Reschedule occurrence',
      'Cancel occurrence',
    ] as $adminLink) {
      $this->assertSession()->linkExists($adminLink);
    }

    $this->drupalGet($this->cancelUrl($adminSeries, $adminTarget->originalOccurrenceKey));
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([], 'Cancel occurrence');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame(3, $this->cancelCount());
  }

  public function testFreshGrantCurrentPersonMembershipAndResponsibilityRacesFailClosed(): void {
    $this->installUserScopeFields();

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\ResponsibilityMutationService $responsibility */
    $responsibility = $this->container->get('personal_secretary.responsibility_mutation');
    /** @var \Drupal\personal_secretary\Service\EffectiveOccurrenceProjectionService $effective */
    $effective = $this->container->get('personal_secretary.effective_occurrence_projection');

    $timezone = new DateTimeZone('Europe/Brussels');
    $utc = new DateTimeZone('UTC');
    $nowUtc = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone($utc);
    $nowLocal = $nowUtc->setTimezone($timezone);

    $current = $domain->createPerson('Race cancellation Current Person');
    $other = $domain->createPerson('Race cancellation Other Person');
    $h1 = $domain->createHousehold(
      'Race cancellation H1',
      [(int) $current->id(), (int) $other->id()],
    );
    $h2 = $domain->createHousehold(
      'Race route-preserving H2',
      [(int) $current->id()],
    );
    [$series, $target] = $this->createSeriesWithResponsibility(
      'Race cancellation target',
      $h1,
      $current,
      $nowLocal->modify('+1 day')->setTime(8, 0),
      'FREQ=WEEKLY;INTERVAL=1',
    );
    $url = $this->cancelUrl($series, $target->originalOccurrenceKey);

    $user = $this->createScopedUser($current, [(int) $h1->id(), (int) $h2->id()], TRUE);
    $this->drupalLogin($user);

    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(200);
    $persistedUser = $this->reloadUser((int) $user->id());
    $persistedUser->set(HouseholdAuthorizationService::FIELD_NAME, [['target_id' => (int) $h2->id()]]);
    $persistedUser->save();
    $this->submitForm([], 'Cancel occurrence');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming/mine');
    $this->assertSame(0, $this->cancelCount());

    $persistedUser = $this->reloadUser((int) $user->id());
    $persistedUser->set(HouseholdAuthorizationService::FIELD_NAME, [
      ['target_id' => (int) $h1->id()],
      ['target_id' => (int) $h2->id()],
    ]);
    $persistedUser->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $current->id()]);
    $persistedUser->save();

    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(200);
    $persistedUser = $this->reloadUser((int) $user->id());
    $persistedUser->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $other->id()]);
    $persistedUser->save();
    $this->submitForm([], 'Cancel occurrence');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming/mine');
    $this->assertSame(0, $this->cancelCount());

    $persistedUser = $this->reloadUser((int) $user->id());
    $persistedUser->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $current->id()]);
    $persistedUser->save();

    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(200);
    $persistedH1 = $this->reloadHousehold((int) $h1->id());
    $persistedH1->set('members', [['target_id' => (int) $other->id()]]);
    $persistedH1->save();
    $this->submitForm([], 'Cancel occurrence');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming/mine');
    $this->assertSame(0, $this->cancelCount());

    $persistedH1 = $this->reloadHousehold((int) $h1->id());
    $persistedH1->set('members', [
      ['target_id' => (int) $current->id()],
      ['target_id' => (int) $other->id()],
    ]);
    $persistedH1->save();

    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(200);
    $instant = new DateTimeImmutable($target->utcStart);
    $occurrence = $effective->project(
      $series,
      $instant->modify('-1 second'),
      $instant->modify('+1 second'),
    )[0];
    $responsibility->createAssignOverride($series, $occurrence, (int) $other->id());
    $this->submitForm([], 'Cancel occurrence');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming/mine');
    $this->assertSame(0, $this->cancelCount());
    $this->assertSession()->pageTextContains('This occurrence can no longer be cancelled.');
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
    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
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

  private function cancelUrl(ActivitySeries $series, string $originalOccurrenceKey): string {
    return Url::fromRoute(
      'personal_secretary.cancel_occurrence',
      [
        'series' => $series->id(),
        'original_occurrence_key' => $originalOccurrenceKey,
      ],
    )->toString();
  }

  private function cancelCount(): int {
    $count = 0;
    foreach ($this->container->get('entity_type.manager')->getStorage('personal_sec_activity_exception')->loadMultiple() as $exception) {
      if (
        $exception instanceof ActivityException
        && (string) $exception->get('action')->value === ActivityException::ACTION_CANCEL
        && (string) $exception->get('status')->value === ActivityException::STATUS_ACTIVE
      ) {
        $count++;
      }
    }
    return $count;
  }

  private function createScopedUser(?Person $person, array $householdIds, bool $withPermission): UserInterface {
    $permissions = $withPermission ? [HouseholdAuthorizationService::PRODUCT_USE_PERMISSION] : [];
    $user = $this->drupalCreateUser($permissions);
    $this->assertInstanceOf(UserInterface::class, $user);
    if ($person instanceof Person) {
      $user->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $person->id()]);
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
