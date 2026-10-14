<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\personal_secretary\Entity\PersonalTask;
use Drupal\personal_secretary\Service\ActivityCaptureResolver;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\personal_secretary\Service\ThreeIntentReadProposeService;
use Drupal\personal_secretary\Value\ActivityCaptureExtraction;
use Drupal\personal_secretary\Value\ActivityCaptureInput;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Exercises actual current-user scoped Today and zero-write typed proposals.
 */
#[Group('personal_secretary')]
#[RunTestsInSeparateProcesses]
final class ThreeIntentReadProposeKernelTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'datetime', 'datetime_range',
    'date_recur', 'key', 'phpmailer_smtp', 'personal_secretary',
  ];

  protected function setUp(): void {
    parent::setUp();
    foreach ([
      'user',
      'personal_secretary_person',
      'personal_secretary_household',
      'personal_sec_task',
      'personal_sec_activity_series',
      'personal_sec_prep_req',
      'personal_sec_calendar_connection',
      'personal_sec_ext_event_shadow',
      'personal_sec_calendar_sync_state',
    ] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['system', 'user']);
    Role::create(['id' => 'typed_reader', 'label' => 'Synthetic typed reader'])
      ->grantPermission(HouseholdAuthorizationService::PRODUCT_USE_PERMISSION)
      ->save();

    $this->installReference(CurrentPersonResolver::FIELD_NAME, 'personal_secretary_person', 1);
    $this->installReference(HouseholdAuthorizationService::FIELD_NAME, 'personal_secretary_household', FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    // uid=1 must not inadvertently obtain special permissions.
    $this->container->get('entity_type.manager')->getStorage('user')
      ->create(['name' => 'inert-fixture', 'status' => 0])->save();
  }

  public function testRealTodayAuthorizationAndBoundedReadDoNotPersist(): void {
    $me = $this->person('Synthetic Reader');
    $other = $this->person('Synthetic Other');
    $h1 = $this->household('Synthetic permitted Household', [$me]);
    $h2 = $this->household('Synthetic forbidden Household', [$me, $other]);

    $user = $this->user('synthetic-scoped-reader', $me, [$h1]);
    $noGrant = $this->user('synthetic-no-grant', $me, []);
    $unlinked = $this->user('synthetic-unlinked', NULL, [$h1]);

    $today = $this->container->get('personal_secretary.today');
    $now = new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime());
    $window = $today->windowFor($user, $now);
    $this->fixtureTask('Synthetic permitted due task', (int) $h1->id(), (int) $me->id(), $window['local_date']);
    $this->fixtureTask('Synthetic forbidden due task', (int) $h2->id(), (int) $me->id(), $window['local_date']);
    $before = $this->domainCounts();

    $this->setCurrentUser($user);
    $read = $this->seam()->handle(ThreeIntentReadProposeService::GET_TODAY);
    self::assertSame('READ_SUCCESS', $read['status']);
    self::assertSame('READ', $read['effect']);
    self::assertSame(['READ'], $read['allowed_effects']);
    self::assertFalse($read['saved']);
    self::assertSame('Europe/Brussels', $read['data']['timezone']);
    self::assertSame($window['local_date'], $read['data']['local_date']);
    self::assertSame(['Synthetic permitted due task'], array_column($read['data']['tasks'], 'title'));
    self::assertArrayNotHasKey('id', $read['data']['tasks'][0]);
    self::assertSame([], $read['clarifications']);
    self::assertSame('UNAVAILABLE', $this->seam()->handle('GET_TODAY', ['user_id' => (int) $noGrant->id()])['status']);

    $this->setCurrentUser($noGrant);
    $denied = $this->seam()->handle(ThreeIntentReadProposeService::GET_TODAY);
    self::assertSame('ACCESS_DENIED', $denied['status']);
    self::assertNull($denied['data']);
    self::assertSame([], $denied['allowed_effects']);

    $this->setCurrentUser($unlinked);
    self::assertSame('ACCESS_DENIED', $this->seam()->handle(ThreeIntentReadProposeService::GET_TODAY)['status']);
    self::assertSame($before, $this->domainCounts(), 'READ must neither persist nor leak cross-Household records.');
  }

  public function testTypedTaskAndActivityProposalsNeverWriteOrConfirm(): void {
    $me = $this->person('Synthetic proposer');
    $household = $this->household('Synthetic proposal Household', [$me]);
    $account = $this->user('synthetic-proposer', $me, [$household]);
    $this->setCurrentUser($account);
    $before = $this->domainCounts();
    $seam = $this->seam();

    $task = $seam->handle(ThreeIntentReadProposeService::PROPOSE_TASK, [
      'title' => '  Synthetic task  ',
      'due_mode' => PersonalTask::DUE_DATE,
      'due_date' => '2026-10-28',
    ]);
    self::assertSame('PROPOSAL_READY', $task['status']);
    self::assertSame('Synthetic task', $task['data']['title']);
    self::assertSame((int) $household->id(), $task['data']['household_id']);
    self::assertSame(['PROPOSE'], $task['allowed_effects']);
    self::assertFalse($task['saved']);

    $missing = $seam->handle(ThreeIntentReadProposeService::PROPOSE_TASK, [
      'title' => ' ',
      'due_mode' => PersonalTask::DUE_DATE,
      'due_date' => '2026-02-30',
    ]);
    self::assertSame('NEEDS_CLARIFICATION', $missing['status']);
    self::assertContains('title_required_or_invalid', $missing['clarifications']);
    self::assertContains('due_date_required_or_invalid', $missing['clarifications']);
    self::assertNull($missing['data']);
    self::assertSame('UNAVAILABLE', $seam->handle(ThreeIntentReadProposeService::PROPOSE_TASK, ['title' => 'synthetic', 'confirm' => 'oui'])['status']);
    self::assertSame('UNAVAILABLE', $seam->handle('WRITE_TASK', ['title' => 'synthetic'])['status']);
    self::assertSame('NONE', $seam->handle('WRITE_TASK')['effect']);

    $context = new ActivityCaptureInput(
      'Synthetic prestructured proposal, no provider',
      new DateTimeImmutable('2026-10-14T12:00:00Z'),
      'Europe/Brussels',
    );
    $ready = $seam->handle(ThreeIntentReadProposeService::PROPOSE_ACTIVITY, [
      'context' => $context,
      'extraction' => $this->extraction(),
    ]);
    self::assertSame('PROPOSAL_READY', $ready['status']);
    self::assertSame('PROPOSE', $ready['effect']);
    self::assertFalse($ready['saved']);
    self::assertSame((int) $household->id(), $ready['data']['household_id']);
    self::assertTrue($ready['data']['ready_for_confirmation']);

    $clarify = $seam->handle(ThreeIntentReadProposeService::PROPOSE_ACTIVITY, [
      'context' => $context,
      'extraction' => $this->extraction(['end_time_expression' => NULL]),
    ]);
    self::assertSame('NEEDS_CLARIFICATION', $clarify['status']);
    self::assertContains(ActivityCaptureResolver::CLARIFICATION_END_TIME_REQUIRED, $clarify['clarifications']);
    self::assertFalse($clarify['saved']);
    self::assertSame('UNAVAILABLE', $seam->handle(ThreeIntentReadProposeService::PROPOSE_ACTIVITY, ['text' => 'Free text not allowed'])['status']);

    $this->setCurrentUser($this->user('synthetic-without-authority', NULL, []));
    self::assertSame('ACCESS_DENIED', $seam->handle(ThreeIntentReadProposeService::PROPOSE_TASK, ['title' => 'No permission'])['status']);
    self::assertSame('ACCESS_DENIED', $seam->handle(ThreeIntentReadProposeService::PROPOSE_ACTIVITY, [
      'context' => $context,
      'extraction' => $this->extraction(),
    ])['status']);
    // User fixtures are persisted by the test setup only; no proposal writes.
    $after = $this->domainCounts();
    foreach (['task', 'series', 'preparation', 'household', 'person'] as $type) {
      self::assertSame($before[$type], $after[$type], "Proposals must not persist {$type}.");
    }
  }

  private function seam(): ThreeIntentReadProposeService {
    return new ThreeIntentReadProposeService(
      $this->container->get('personal_secretary.today'),
      $this->container->get('personal_secretary.current_user_activity_creation'),
      $this->container->get('personal_secretary.activity_capture_resolver'),
    );
  }

  /**
   * @param array<string, mixed> $override
   */
  private function extraction(array $override = []): ActivityCaptureExtraction {
    return ActivityCaptureExtraction::fromArray(array_replace([
      'label_text' => 'Synthetic one-off activity',
      'location_text' => NULL,
      'concerned_person_mentions' => [],
      'unclassified_person_mentions' => [],
      'concerned_person_alternative' => FALSE,
      'responsibility_candidate' => 'SELF',
      'date_expression' => '20 October 2026',
      'date_day' => 20,
      'date_month' => 10,
      'date_year' => 2026,
      'relative_day_offset' => NULL,
      'start_time_expression' => '09h00',
      'end_time_expression' => '10h00',
      'recurrence_expression' => NULL,
      'explicit_all_day_signal' => FALSE,
      'explicit_timed_signal' => TRUE,
    ], $override));
  }

  private function person(string $label): object {
    $person = $this->container->get('entity_type.manager')->getStorage('personal_secretary_person')
      ->create(['name' => $label]);
    $person->save();
    return $person;
  }

  /**
   * @param object[] $people
   */
  private function household(string $label, array $people): object {
    $household = $this->container->get('entity_type.manager')->getStorage('personal_secretary_household')
      ->create([
        'name' => $label,
        'members' => array_map(static fn(object $p): array => ['target_id' => (int) $p->id()], $people),
      ]);
    $household->save();
    return $household;
  }

  /**
   * @param object[] $households
   */
  private function user(string $name, ?object $person, array $households): UserInterface {
    $user = $this->container->get('entity_type.manager')->getStorage('user')->create([
      'name' => $name,
      'status' => 1,
      'roles' => ['typed_reader'],
      'timezone' => 'Europe/Brussels',
      CurrentPersonResolver::FIELD_NAME => $person ? ['target_id' => (int) $person->id()] : NULL,
      HouseholdAuthorizationService::FIELD_NAME => array_map(
        static fn(object $h): array => ['target_id' => (int) $h->id()],
        $households,
      ),
    ]);
    $user->save();
    return $user;
  }

  private function fixtureTask(string $title, int $householdId, int $personId, string $date): void {
    $this->container->get('entity_type.manager')->getStorage('personal_sec_task')
      ->create([
        'title' => $title,
        'household' => $householdId,
        'assigned_person' => $personId,
        'due_mode' => PersonalTask::DUE_DATE,
        'due_date' => $date,
        'status' => PersonalTask::STATUS_OPEN,
      ])->save();
  }

  private function setCurrentUser(UserInterface $user): void {
    $this->container->get('current_user')->setAccount($user);
  }

  /**
   * @return array<string, int>
   */
  private function domainCounts(): array {
    $storage = $this->container->get('entity_type.manager');
    $result = [];
    foreach ([
      'task' => 'personal_sec_task',
      'series' => 'personal_sec_activity_series',
      'preparation' => 'personal_sec_prep_req',
      'person' => 'personal_secretary_person',
      'household' => 'personal_secretary_household',
    ] as $key => $entityType) {
      $result[$key] = count($storage->getStorage($entityType)->loadMultiple());
    }
    return $result;
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
