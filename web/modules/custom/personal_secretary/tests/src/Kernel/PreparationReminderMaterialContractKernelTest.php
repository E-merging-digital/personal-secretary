<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Database\Database;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\Queue\DatabaseQueue;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\PreparationCompletion;
use Drupal\personal_secretary\Entity\PreparationReminderDelivery;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\personal_secretary\Service\PreparationReminderCandidateService;
use Drupal\personal_secretary\Service\PreparationReminderDeliveryService;
use Drupal\personal_secretary\Value\PreparationReminderCandidate;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;

/**
 * Proves the missing bounded material contracts from #124 post-green review.
 *
 * @group personal_secretary
 */
final class PreparationReminderMaterialContractKernelTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'datetime',
    'datetime_range',
    'date_recur',
    'personal_secretary',
  ];

  private string $roleId = 'personal_secretary_reminder_test';

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('personal_secretary_person');
    $this->installEntitySchema('personal_secretary_household');
    $this->installEntitySchema('personal_sec_activity_series');
    $this->installEntitySchema('personal_sec_activity_exception');
    $this->installEntitySchema('personal_sec_resp_rule');
    $this->installEntitySchema('personal_sec_resp_override');
    $this->installEntitySchema('personal_sec_prep_req');
    $this->installEntitySchema(PreparationCompletion::ENTITY_TYPE_ID);
    $this->installEntitySchema(PreparationReminderDelivery::ENTITY_TYPE_ID);
    $this->installConfig(['system', 'user']);

    // Queue and lock tables are owned by their Core database backends in
    // Drupal 11, not by system.install's hook_schema(). Materialize only those
    // test seams explicitly instead of inventing module schema authority.
    $connection = Database::getConnection();
    $schema = $connection->schema();
    $schema->createTable(DatabaseQueue::TABLE_NAME, (new DatabaseQueue(self::class, $connection))->schemaDefinition());
    $schema->createTable(DatabaseLockBackend::TABLE_NAME, (new DatabaseLockBackend($connection))->schemaDefinition());

    Role::create(['id' => $this->roleId, 'label' => 'Personal Secretary reminder test'])
      ->grantPermission(HouseholdAuthorizationService::PRODUCT_USE_PERMISSION)
      ->save();

    $this->installUserReferenceField(CurrentPersonResolver::FIELD_NAME, 'personal_secretary_person', 1, 'Personal Secretary person');
    $this->installUserReferenceField(HouseholdAuthorizationService::FIELD_NAME, 'personal_secretary_household', FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED, 'Personal Secretary households');
    $this->installReminderField();
  }

  public function testCandidateAuthorityLifecycleAndRescheduleIdentity(): void {
    $now = $this->now();
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $candidateService = PreparationReminderCandidateService::create($this->container);
    $timeline = $this->container->get('personal_secretary.revision_timeline');
    $exceptions = $this->container->get('personal_secretary.activity_exception');

    $personA = $domain->createPerson('Reminder Person A');
    $personB = $domain->createPerson('Reminder Person B');
    $household = $domain->createHousehold('Reminder Household', [(int) $personA->id(), (int) $personB->id()]);

    $userA = $this->productUser((int) $personA->id(), [(int) $household->id()], TRUE, 'a@example.test');
    $samePersonAuthorized = $this->productUser((int) $personA->id(), [(int) $household->id()], TRUE, 'a2@example.test');
    $samePersonOptedOut = $this->productUser((int) $personA->id(), [(int) $household->id()], FALSE, 'a3@example.test');
    $samePersonNoGrant = $this->productUser((int) $personA->id(), [], TRUE, 'a4@example.test');
    $missingPerson = $this->productUser(NULL, [(int) $household->id()], TRUE, 'missing@example.test');
    $personBUser = $this->productUser((int) $personB->id(), [(int) $household->id()], TRUE, 'b@example.test');

    [$dueSeries, $dueRequirement] = $this->seriesWithRequirement('Due', (int) $household->id(), (int) $personA->id(), $now->modify('+2 hours'), 3 * 3600, $now);
    $dueA = $this->candidateForRequirement($candidateService, $userA, (int) $dueRequirement->id(), $now);
    $dueA2 = $this->candidateForRequirement($candidateService, $samePersonAuthorized, (int) $dueRequirement->id(), $now);
    $this->assertNotSame($dueA->identityHash(), $dueA2->identityHash(), 'Same Person Users have independent recipient delivery identity.');
    $this->assertSame([], $candidateService->dueForUser($samePersonOptedOut, $now));
    $this->assertSame([], $candidateService->dueForUser($samePersonNoGrant, $now));
    $this->assertSame([], $candidateService->dueForUser($missingPerson, $now));
    $this->assertSame([], $candidateService->dueForUser($personBUser, $now), 'Responsibility assigned elsewhere produces no candidate.');

    [, $futureRequirement] = $this->seriesWithRequirement('Not yet due', (int) $household->id(), (int) $personA->id(), $now->modify('+4 hours'), 3600, $now);
    $this->assertNull($this->findCandidate($candidateService, $userA, (int) $futureRequirement->id(), $now));

    [, $pastRequirement] = $this->seriesWithRequirement('Past', (int) $household->id(), (int) $personA->id(), $now->modify('-1 hour'), 3600, $now);
    $this->assertNull($this->findCandidate($candidateService, $userA, (int) $pastRequirement->id(), $now));

    [$cancelSeries, $cancelRequirement] = $this->seriesWithRequirement('Cancelled', (int) $household->id(), (int) $personA->id(), $now->modify('+3 hours'), 4 * 3600, $now);
    $cancelCandidate = $this->candidateForRequirement($candidateService, $userA, (int) $cancelRequirement->id(), $now);
    $cancelBase = $timeline->projectBaseWindow($cancelSeries, $now, $now->modify('+1 day'))[0];
    $exceptions->createCancel($cancelSeries, $cancelBase);
    $this->assertNull($this->findCandidate($candidateService, $userA, (int) $cancelRequirement->id(), $now));
    $this->assertNull($candidateService->rederiveExact($cancelCandidate->queuePayload(), $now));

    [, $completedRequirement] = $this->seriesWithRequirement('Completed', (int) $household->id(), (int) $personA->id(), $now->modify('+3 hours'), 4 * 3600, $now);
    $completedCandidate = $this->candidateForRequirement($candidateService, $userA, (int) $completedRequirement->id(), $now);
    $this->complete($completedCandidate, (int) $personA->id(), $userA);
    $this->assertNull($this->findCandidate($candidateService, $userA, (int) $completedRequirement->id(), $now));

    [$rescheduleSeries, $rescheduleRequirement] = $this->seriesWithRequirement('Reschedule', (int) $household->id(), (int) $personA->id(), $now->modify('+3 hours'), 5 * 3600, $now);
    $before = $this->candidateForRequirement($candidateService, $userA, (int) $rescheduleRequirement->id(), $now);
    $base = $timeline->projectBaseWindow($rescheduleSeries, $now, $now->modify('+1 day'))[0];
    $exceptions->createReschedule($rescheduleSeries, $base, $now->modify('+3 hours 30 minutes'), $now->modify('+4 hours 30 minutes'), 'UTC');
    $after = $this->candidateForRequirement($candidateService, $userA, (int) $rescheduleRequirement->id(), $now);
    $this->assertNotSame($before->intendedDueAtUtc, $after->intendedDueAtUtc);
    $this->assertNotSame($before->identityHash(), $after->identityHash());
    $this->assertNull($candidateService->rederiveExact($before->queuePayload(), $now));
    $this->assertSame((int) $dueSeries->id(), $dueA->seriesId);
  }

  public function testStaleQueueLockAndDisabledMailPreserveCanonicalTruth(): void {
    $now = $this->now();
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $candidateService = PreparationReminderCandidateService::create($this->container);
    $deliveryService = PreparationReminderDeliveryService::create($this->container);
    $queue = $this->container->get('queue')->get(PreparationReminderDeliveryService::QUEUE_ID);
    $responsibility = $this->container->get('personal_secretary.responsibility_mutation');
    $effective = $this->container->get('personal_secretary.effective_occurrence_projection');
    $preparations = $this->container->get('personal_secretary.current_user_preparation');

    $personA = $domain->createPerson('Queue Person A');
    $personB = $domain->createPerson('Queue Person B');
    $household = $domain->createHousehold('Queue Household', [(int) $personA->id(), (int) $personB->id()]);

    $inertUser = $this->container->get('entity_type.manager')->getStorage('user')->create([
      'name' => 'uid1-inert-fixture',
      'status' => 0,
    ]);
    $inertUser->save();

    $user = $this->productUser((int) $personA->id(), [(int) $household->id()], TRUE, 'queue@example.test');
    $this->assertGreaterThan(1, (int) $user->id(), 'Queue recipient must be an ordinary Drupal User, not uid=1 super-user.');

    [, $completionRequirement] = $this->seriesWithRequirement('Queue completion', (int) $household->id(), (int) $personA->id(), $now->modify('+2 hours'), 3 * 3600, $now);
    $completionCandidate = $this->candidateForRequirement($candidateService, $user, (int) $completionRequirement->id(), $now);
    $payload = $this->enqueueAndClaim($queue, $completionCandidate);
    $this->complete($completionCandidate, (int) $personA->id(), $user);
    $deliveryService->processPayload($payload);
    $this->assertSame(0, $this->deliveryCount());

    [, $grantRequirement] = $this->seriesWithRequirement('Queue grant', (int) $household->id(), (int) $personA->id(), $now->modify('+2 hours 10 minutes'), 3 * 3600, $now);
    $grantCandidate = $this->candidateForRequirement($candidateService, $user, (int) $grantRequirement->id(), $now);
    $payload = $this->enqueueAndClaim($queue, $grantCandidate);
    $user->set(HouseholdAuthorizationService::FIELD_NAME, [])->save();
    $deliveryService->processPayload($payload);
    $this->assertSame(0, $this->deliveryCount());
    $user->set(HouseholdAuthorizationService::FIELD_NAME, [['target_id' => (int) $household->id()]])->save();

    [$responsibilitySeries, $responsibilityRequirement] = $this->seriesWithRequirement('Queue responsibility', (int) $household->id(), (int) $personA->id(), $now->modify('+2 hours 20 minutes'), 3 * 3600, $now);
    $responsibilityCandidate = $this->candidateForRequirement($candidateService, $user, (int) $responsibilityRequirement->id(), $now);
    $payload = $this->enqueueAndClaim($queue, $responsibilityCandidate);
    $occurrence = $effective->project($responsibilitySeries, $now, $now->modify('+1 day'))[0];
    $responsibility->createAssignOverride($responsibilitySeries, $occurrence, (int) $personB->id());
    $deliveryService->processPayload($payload);
    $this->assertSame(0, $this->deliveryCount());

    [, $lockRequirement] = $this->seriesWithRequirement('Queue lock', (int) $household->id(), (int) $personA->id(), $now->modify('+2 hours 30 minutes'), 3 * 3600, $now);
    $lockCandidate = $this->candidateForRequirement($candidateService, $user, (int) $lockRequirement->id(), $now);
    Database::getConnection()->insert('semaphore')->fields([
      'name' => 'personal_secretary:prep-reminder:' . $lockCandidate->identityHash(),
      'value' => 'other-worker',
      'expire' => microtime(TRUE) + 60,
    ])->execute();
    $deliveryService->processPayload($lockCandidate->queuePayload());
    $this->assertSame(0, $this->deliveryCount());

    [, $disabledRequirement] = $this->seriesWithRequirement('Disabled mail', (int) $household->id(), (int) $personA->id(), $now->modify('+2 hours 40 minutes'), 3 * 3600, $now);
    $disabledCandidate = $this->candidateForRequirement($candidateService, $user, (int) $disabledRequirement->id(), $now);
    $mineBefore = $preparations->mineForUser($user, $now);
    $dayStart = $now->setTime(0, 0, 0);
    $todayBefore = $preparations->todayForUser($user, $now, $dayStart, $dayStart->modify('+1 day'));
    $completionCount = $this->completionCount();

    $originalDdev = getenv('IS_DDEV_PROJECT');
    putenv('IS_DDEV_PROJECT=false');
    $this->config('system.mail')->set('interface.default', 'php_mail')->save();
    try {
      $deliveryService->processPayload($disabledCandidate->queuePayload());
    }
    finally {
      if ($originalDdev === FALSE) {
        putenv('IS_DDEV_PROJECT');
      }
      else {
        putenv('IS_DDEV_PROJECT=' . $originalDdev);
      }
    }

    $this->assertSame($completionCount, $this->completionCount());
    $this->assertSame($mineBefore, $preparations->mineForUser($user, $now));
    $this->assertSame($todayBefore, $preparations->todayForUser($user, $now, $dayStart, $dayStart->modify('+1 day')));
    $deliveries = $this->container->get('entity_type.manager')->getStorage(PreparationReminderDelivery::ENTITY_TYPE_ID)->loadMultiple();
    $this->assertCount(1, $deliveries);
    $delivery = reset($deliveries);
    $this->assertInstanceOf(PreparationReminderDelivery::class, $delivery);
    $this->assertSame(PreparationReminderDelivery::STATE_KNOWN_NOT_SUBMITTED, (string) $delivery->get('state')->value);
  }

  public function testOptedInUserBoundActualCountAndCandidateOverflowFailClosed(): void {
    $now = $this->now();
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $requirements = $this->container->get('personal_secretary.preparation_requirement_mutation');
    $candidateService = PreparationReminderCandidateService::create($this->container);
    $deliveryService = PreparationReminderDeliveryService::create($this->container);
    $queue = $this->container->get('queue')->get(PreparationReminderDeliveryService::QUEUE_ID);

    $person = $domain->createPerson('Bound Person');
    $household = $domain->createHousehold('Bound Household', [(int) $person->id()]);
    $user = $this->productUser((int) $person->id(), [(int) $household->id()], TRUE, 'bound@example.test');

    for ($i = 0; $i < 65; ++$i) {
      $this->productUser((int) $person->id(), [(int) $household->id()], FALSE, sprintf('unrelated-%d@example.test', $i));
    }

    [$series, $firstRequirement] = $this->seriesWithRequirement('Bound candidates', (int) $household->id(), (int) $person->id(), $now->modify('+2 hours'), 3 * 3600, $now);
    $this->assertSame(1, $deliveryService->enqueueDueReminders());
    $this->assertSame(1, $queue->numberOfItems());
    $queue->deleteQueue();

    $firstCandidate = $this->candidateForRequirement($candidateService, $user, (int) $firstRequirement->id(), $now);
    $this->submittedDelivery($firstCandidate);
    $this->assertSame(0, $deliveryService->enqueueDueReminders());
    $this->assertSame(0, $queue->numberOfItems());

    for ($i = 1; $i < PreparationReminderDeliveryService::CANDIDATE_LIMIT + 1; ++$i) {
      $requirements->createPreparationRequirement($series, sprintf('Bound requirement %d', $i), 3 * 3600, $now->modify('-1 day'));
    }
    $this->assertCount(PreparationReminderDeliveryService::CANDIDATE_LIMIT + 1, $candidateService->dueForUser($user, $now));
    $this->assertSame(0, $deliveryService->enqueueDueReminders());
    $this->assertSame(0, $queue->numberOfItems(), 'Candidate 257 must fail closed before any queue item is created.');
  }

  private function seriesWithRequirement(string $label, int $householdId, int $personId, DateTimeImmutable $start, int $leadSeconds, DateTimeImmutable $now): array {
    $end = $start->modify('+1 hour');
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $series = $domain->createActivitySeries($label, $householdId, $start, $end, 'FREQ=DAILY;COUNT=1');
    $this->container->get('personal_secretary.responsibility_mutation')->createResponsibilityRule($series, $personId, $start, $end, 'FREQ=DAILY;COUNT=1');
    $requirement = $this->container->get('personal_secretary.preparation_requirement_mutation')->createPreparationRequirement($series, $label . ' preparation', $leadSeconds, $now->modify('-1 day'));
    return [$series, $requirement];
  }

  private function candidateForRequirement(PreparationReminderCandidateService $service, UserInterface $user, int $requirementId, DateTimeImmutable $now): PreparationReminderCandidate {
    $candidate = $this->findCandidate($service, $user, $requirementId, $now);
    $this->assertInstanceOf(PreparationReminderCandidate::class, $candidate);
    return $candidate;
  }

  private function findCandidate(PreparationReminderCandidateService $service, UserInterface $user, int $requirementId, DateTimeImmutable $now): ?PreparationReminderCandidate {
    foreach ($service->dueForUser($user, $now) as $candidate) {
      if ($candidate->requirementId === $requirementId) {
        return $candidate;
      }
    }
    return NULL;
  }

  private function complete(PreparationReminderCandidate $candidate, int $personId, UserInterface $user): void {
    $this->container->get('entity_type.manager')->getStorage(PreparationCompletion::ENTITY_TYPE_ID)->create([
      'series' => $candidate->seriesId,
      'target_revision_id' => $candidate->targetRevisionId,
      'original_occurrence_key' => $candidate->originalOccurrenceKey,
      'preparation_requirement' => $candidate->requirementId,
      'responsible_person' => $personId,
      'prepared_at' => $this->container->get('datetime.time')->getCurrentTime(),
      'prepared_by_user' => (int) $user->id(),
    ])->save();
  }

  private function submittedDelivery(PreparationReminderCandidate $candidate): void {
    $now = $this->container->get('datetime.time')->getCurrentTime();
    $this->container->get('entity_type.manager')->getStorage(PreparationReminderDelivery::ENTITY_TYPE_ID)->create([
      'recipient_user' => $candidate->recipientUserId,
      'series' => $candidate->seriesId,
      'target_revision_id' => $candidate->targetRevisionId,
      'original_occurrence_key' => $candidate->originalOccurrenceKey,
      'preparation_requirement' => $candidate->requirementId,
      'intended_due_at' => (new DateTimeImmutable($candidate->intendedDueAtUtc))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s'),
      'channel' => PreparationReminderDelivery::CHANNEL_EMAIL,
      'state' => PreparationReminderDelivery::STATE_SUBMITTED,
      'attempt_count' => 1,
      'last_attempt_at' => $now,
      'submitted_at' => $now,
    ])->save();
  }

  private function enqueueAndClaim($queue, PreparationReminderCandidate $candidate): array {
    $queue->createItem($candidate->queuePayload());
    $item = $queue->claimItem();
    $this->assertNotFalse($item);
    return (array) $item->data;
  }

  private function productUser(?int $personId, array $householdIds, bool $optIn, string $email): UserInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    $user = $storage->create([
      'name' => 'reminder-' . substr(hash('sha256', $email), 0, 12),
      'mail' => $email,
      'status' => 1,
    ]);
    $user->addRole($this->roleId);
    if ($personId !== NULL) {
      $user->set(CurrentPersonResolver::FIELD_NAME, [['target_id' => $personId]]);
    }
    $user->set(HouseholdAuthorizationService::FIELD_NAME, array_map(static fn(int $id): array => ['target_id' => $id], $householdIds));
    $user->set(PreparationReminderCandidateService::OPT_IN_FIELD, $optIn ? 1 : 0);
    $user->save();
    $this->assertInstanceOf(UserInterface::class, $user);
    return $user;
  }

  private function installReminderField(): void {
    FieldStorageConfig::create([
      'field_name' => PreparationReminderCandidateService::OPT_IN_FIELD,
      'entity_type' => 'user',
      'type' => 'boolean',
      'cardinality' => 1,
      'translatable' => FALSE,
    ])->save();
    FieldConfig::create([
      'field_name' => PreparationReminderCandidateService::OPT_IN_FIELD,
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Preparation reminders',
      'required' => FALSE,
      'translatable' => FALSE,
      'default_value' => [['value' => 0]],
      'settings' => ['on_label' => 'On', 'off_label' => 'Off'],
    ])->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
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

  private function completionCount(): int {
    return count($this->container->get('entity_type.manager')->getStorage(PreparationCompletion::ENTITY_TYPE_ID)->loadMultiple());
  }

  private function deliveryCount(): int {
    return count($this->container->get('entity_type.manager')->getStorage(PreparationReminderDelivery::ENTITY_TYPE_ID)->loadMultiple());
  }

  private function now(): DateTimeImmutable {
    return (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))->setTimezone(new DateTimeZone('UTC'));
  }
}
