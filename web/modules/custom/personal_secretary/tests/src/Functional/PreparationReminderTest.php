<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\personal_secretary\Entity\PreparationReminderDelivery;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\personal_secretary\Service\PreparationReminderCandidateService;
use Drupal\personal_secretary\Service\PreparationReminderDeliveryService;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;

/**
 * Proves the bounded local/synthetic preparation reminder contract.
 *
 * @group personal_secretary
 */
final class PreparationReminderTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testOptInDerivedCandidateDeliveryReplayAndCanonicalTruth(): void {
    $this->installUserReferenceField(CurrentPersonResolver::FIELD_NAME, 'personal_secretary_person', 1, 'Personal Secretary person');
    $this->installUserReferenceField(HouseholdAuthorizationService::FIELD_NAME, 'personal_secretary_household', FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED, 'Personal Secretary households');
    $this->installReminderField();
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();

    $domain = $this->container->get('personal_secretary.domain_mutation');
    $responsibility = $this->container->get('personal_secretary.responsibility_mutation');
    $preparations = $this->container->get('personal_secretary.preparation_requirement_mutation');
    $now = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))->setTimezone(new DateTimeZone('UTC'));

    $person = $domain->createPerson('Synthetic reminder person');
    $household = $domain->createHousehold('Synthetic reminder household', [(int) $person->id()]);
    $start = $now->modify('+1 day');
    $series = $domain->createActivitySeries('FORBIDDEN synthetic activity label', (int) $household->id(), $start, $start->modify('+1 hour'), 'FREQ=DAILY;COUNT=1');
    $responsibility->createResponsibilityRule($series, (int) $person->id(), $start, $start->modify('+1 hour'), 'FREQ=DAILY;COUNT=1');
    $requirement = $preparations->createPreparationRequirement($series, 'FORBIDDEN synthetic preparation instruction', 2 * 86400, $now->modify('-30 days'));

    $user = $this->productUser($person->id(), [$household->id()], FALSE, 'reminder-a@example.test');
    $candidateService = PreparationReminderCandidateService::create($this->container);
    $this->assertSame([], $candidateService->dueForUser($user, $now), 'Opt-in defaults false and suppresses candidates.');

    $this->drupalLogin($user);
    $this->drupalGet('/personal-secretary/reminders');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->checkboxNotChecked('preparation_reminders');
    $this->submitForm(['preparation_reminders' => TRUE], 'Save reminder settings');
    $this->assertSession()->checkboxChecked('preparation_reminders');

    $user = $this->reloadUser((int) $user->id());
    $candidates = $candidateService->dueForUser($user, $now);
    $this->assertCount(1, $candidates);
    $candidate = $candidates[0];
    $this->assertSame((int) $requirement->id(), $candidate->requirementId);
    $this->assertLessThanOrEqual($now->getTimestamp(), (new DateTimeImmutable($candidate->intendedDueAtUtc))->getTimestamp());

    // The current-user wrappers and account-parameterized path consume the same
    // deterministic preparation truth without switching global current_user.
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($user);
    try {
      $currentModel = $this->container->get('personal_secretary.current_user_preparation')->mine($now);
      $parameterizedModel = $this->container->get('personal_secretary.current_user_preparation')->mineForUser($user, $now);
      $this->assertSame($currentModel, $parameterizedModel);
    }
    finally {
      $switcher->switchBack();
    }

    $deliveryService = PreparationReminderDeliveryService::create($this->container);
    $this->assertSame(1, $deliveryService->enqueueDueReminders());
    $queue = $this->container->get('queue')->get(PreparationReminderDeliveryService::QUEUE_ID);
    $item = $queue->claimItem();
    $this->assertNotFalse($item);
    $payload = (array) $item->data;
    $this->assertSame($candidate->queuePayload(), $payload);
    $deliveryService->processPayload($payload);

    $deliveries = $this->container->get('entity_type.manager')->getStorage(PreparationReminderDelivery::ENTITY_TYPE_ID)->loadMultiple();
    $this->assertCount(1, $deliveries);
    $delivery = reset($deliveries);
    $this->assertInstanceOf(PreparationReminderDelivery::class, $delivery);
    $this->assertSame(PreparationReminderDelivery::STATE_SUBMITTED, (string) $delivery->get('state')->value);
    $this->assertSame(1, (int) $delivery->get('attempt_count')->value);

    $mails = $this->container->get('state')->get('system.test_mail_collector', []);
    $this->assertCount(1, $mails);
    $serialized = serialize($mails[0]);
    $this->assertStringContainsString('Personal Secretary — préparation à effectuer', $serialized);
    $this->assertStringContainsString('Vous avez une préparation à effectuer dans Personal Secretary.', $serialized);
    $this->assertStringContainsString('/personal-secretary/preparations/mine', $serialized);
    $this->assertStringNotContainsString('FORBIDDEN synthetic activity label', $serialized);
    $this->assertStringNotContainsString('FORBIDDEN synthetic preparation instruction', $serialized);
    $this->assertStringNotContainsString($candidate->originalOccurrenceKey, $serialized);

    $completionStorage = $this->container->get('entity_type.manager')->getStorage('personal_sec_prep_completion');
    $this->assertCount(0, $completionStorage->loadMultiple(), 'Submitting a reminder must not mark preparation complete.');

    // SUBMITTED suppresses replay.
    $deliveryService->processPayload($payload);
    $this->assertCount(1, $this->container->get('state')->get('system.test_mail_collector', []));

    // UNKNOWN and stale ATTEMPTING suppress blind replay.
    $delivery->set('state', PreparationReminderDelivery::STATE_UNKNOWN)->save();
    $deliveryService->processPayload($payload);
    $this->assertCount(1, $this->container->get('state')->get('system.test_mail_collector', []));
    $delivery->set('state', PreparationReminderDelivery::STATE_ATTEMPTING)->save();
    $deliveryService->processPayload($payload);
    $this->assertCount(1, $this->container->get('state')->get('system.test_mail_collector', []));

    // A known first non-submission permits one revalidated retry; attempt 2 is terminal.
    $delivery->set('state', PreparationReminderDelivery::STATE_KNOWN_NOT_SUBMITTED)->set('attempt_count', 1)->save();
    $deliveryService->processPayload($payload);
    $delivery = $this->reloadDelivery((int) $delivery->id());
    $this->assertSame(PreparationReminderDelivery::STATE_SUBMITTED, (string) $delivery->get('state')->value);
    $this->assertSame(2, (int) $delivery->get('attempt_count')->value);
    $this->assertCount(2, $this->container->get('state')->get('system.test_mail_collector', []));
    $delivery->set('state', PreparationReminderDelivery::STATE_KNOWN_NOT_SUBMITTED)->set('attempt_count', 2)->save();
    $deliveryService->processPayload($payload);
    $this->assertCount(2, $this->container->get('state')->get('system.test_mail_collector', []));

    // Opt-out after enqueue makes the stale queue payload non-authoritative.
    $user->set(PreparationReminderCandidateService::OPT_IN_FIELD, 0)->save();
    $delivery->delete();
    $deliveryService->processPayload($payload);
    $this->assertCount(0, $this->container->get('entity_type.manager')->getStorage(PreparationReminderDelivery::ENTITY_TYPE_ID)->loadMultiple());
    $this->assertCount(2, $this->container->get('state')->get('system.test_mail_collector', []));
  }

  private function productUser(int|string $personId, array $householdIds, bool $optIn, string $email): UserInterface {
    $user = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $user);
    $user->setEmail($email);
    $user->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $personId]);
    $user->set(HouseholdAuthorizationService::FIELD_NAME, array_map(static fn($id): array => ['target_id' => (int) $id], $householdIds));
    $user->set(PreparationReminderCandidateService::OPT_IN_FIELD, $optIn ? 1 : 0);
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

  private function reloadDelivery(int $id): PreparationReminderDelivery {
    $storage = $this->container->get('entity_type.manager')->getStorage(PreparationReminderDelivery::ENTITY_TYPE_ID);
    $storage->resetCache([$id]);
    $delivery = $storage->load($id);
    $this->assertInstanceOf(PreparationReminderDelivery::class, $delivery);
    return $delivery;
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
}
