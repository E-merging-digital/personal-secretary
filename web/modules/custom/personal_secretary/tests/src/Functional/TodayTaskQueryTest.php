<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\personal_secretary\Entity\PersonalTask;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;

/**
 * Proves Today PersonalTask boundaries and account-scoped Household grants.
 *
 * @group personal_secretary
 */
final class TodayTaskQueryTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testTodayTaskQueryIsBoundedBeforeLoadAndDstSafe(): void {
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
    /** @var \Drupal\personal_secretary\Service\PersonalTaskMutationService $mutations */
    $mutations = $this->container->get('personal_secretary.personal_task_mutation');
    /** @var \Drupal\personal_secretary\Service\PersonalTaskQueryService $query */
    $query = $this->container->get('personal_secretary.personal_task_query');
    /** @var \Drupal\personal_secretary\Service\TodayService $today */
    $today = $this->container->get('personal_secretary.today');

    $person = $domain->createPerson('Synthetic Today task person');
    $household1 = $domain->createHousehold('Synthetic Today H1', [(int) $person->id()]);
    $household2 = $domain->createHousehold('Synthetic Today H2', [(int) $person->id()]);

    $userA = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $userA);
    $userA->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $person->id()]);
    $userA->set(HouseholdAuthorizationService::FIELD_NAME, [['target_id' => (int) $household1->id()]]);
    $userA->set('timezone', 'Europe/Brussels');
    $userA->save();

    $userB = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $userB);
    $userB->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $person->id()]);
    $userB->set('timezone', 'Europe/Brussels');
    $userB->save();

    $spring = $today->windowFor(
      $userA,
      new DateTimeImmutable('2026-03-29 12:00:00', new DateTimeZone('UTC')),
    );
    $this->assertSame('Europe/Brussels', $spring['timezone']);
    $this->assertSame('2026-03-29', $spring['local_date']);
    $this->assertSame(
      23 * 3600,
      $spring['utc_end']->getTimestamp() - $spring['utc_start']->getTimestamp(),
      'Spring Today is the civil local day, not a fixed 24-hour interval.',
    );

    $autumn = $today->windowFor(
      $userA,
      new DateTimeImmutable('2026-10-25 12:00:00', new DateTimeZone('UTC')),
    );
    $this->assertSame(
      25 * 3600,
      $autumn['utc_end']->getTimestamp() - $autumn['utc_start']->getTimestamp(),
      'Autumn Today is the civil local day, not a fixed 24-hour interval.',
    );

    $this->config('system.date')
      ->set('timezone.default', 'Europe/Brussels')
      ->set('timezone.user.default', UserInterface::TIMEZONE_EMPTY)
      ->save();
    $fallbackUser = $this->drupalCreateUser();
    $this->assertInstanceOf(UserInterface::class, $fallbackUser);
    $fallbackUser->set('timezone', '');
    $fallbackUser->save();
    $this->assertSame('', (string) $fallbackUser->get('timezone')->value);
    $fallback = $today->windowFor(
      $fallbackUser,
      new DateTimeImmutable('2026-04-06 12:00:00', new DateTimeZone('UTC')),
    );
    $this->assertSame('Europe/Brussels', $fallback['timezone']);

    $fixedNow = new DateTimeImmutable('2026-04-06 10:00:00', new DateTimeZone('Europe/Brussels'));
    $fixedNowUtc = $fixedNow->setTimezone(new DateTimeZone('UTC'));
    $fixedEndUtc = (new DateTimeImmutable('2026-04-07 00:00:00', new DateTimeZone('Europe/Brussels')))
      ->setTimezone(new DateTimeZone('UTC'));

    $accountSwitcher = $this->container->get('account_switcher');
    $accountSwitcher->switchTo($userA);
    try {
      $mutations->createTask('DATE yesterday', (int) $household1->id(), PersonalTask::DUE_DATE, '2026-04-05');
      $mutations->createTask('DATE today', (int) $household1->id(), PersonalTask::DUE_DATE, '2026-04-06');
      $mutations->createTask('DATE tomorrow', (int) $household1->id(), PersonalTask::DUE_DATE, '2026-04-07');
      $mutations->createTask('DATE_TIME overdue', (int) $household1->id(), PersonalTask::DUE_DATE_TIME, NULL, '2026-04-06 09:00');
      $mutations->createTask('DATE_TIME later today', (int) $household1->id(), PersonalTask::DUE_DATE_TIME, NULL, '2026-04-06 17:00');
      $mutations->createTask('DATE_TIME tomorrow', (int) $household1->id(), PersonalTask::DUE_DATE_TIME, NULL, '2026-04-07 09:00');
      $mutations->createTask('Undated excluded', (int) $household1->id(), PersonalTask::DUE_NONE);

      $completed = $mutations->createTask(
        'Completed excluded',
        (int) $household1->id(),
        PersonalTask::DUE_DATE,
        '2026-04-06',
      );
      $this->assertTrue($mutations->completeTask((int) $completed->id()));

      $edited = $mutations->createTask(
        'Edited due',
        (int) $household1->id(),
        PersonalTask::DUE_DATE,
        '2026-04-07',
      );

      /** @var \Drupal\personal_secretary\Entity\PersonalTask $h2Task */
      $h2Task = $this->container->get('entity_type.manager')
        ->getStorage(PersonalTask::ENTITY_TYPE_ID)
        ->create([
          'title' => 'H2 unauthorized task',
          'household' => ['target_id' => (int) $household2->id()],
          'assigned_person' => ['target_id' => (int) $person->id()],
          'due_mode' => PersonalTask::DUE_DATE,
          'due_date' => '2026-04-06',
          'status' => PersonalTask::STATUS_OPEN,
        ]);
      $h2Task->save();

      $items = $this->byTitle($query->todayOpenTasks($fixedNowUtc, $fixedEndUtc));
      $this->assertArrayHasKey('DATE yesterday', $items);
      $this->assertTrue($items['DATE yesterday']['overdue']);
      $this->assertArrayHasKey('DATE today', $items);
      $this->assertFalse($items['DATE today']['overdue']);
      $this->assertArrayNotHasKey('DATE tomorrow', $items);
      $this->assertArrayHasKey('DATE_TIME overdue', $items);
      $this->assertTrue($items['DATE_TIME overdue']['overdue']);
      $this->assertArrayHasKey('DATE_TIME later today', $items);
      $this->assertFalse($items['DATE_TIME later today']['overdue']);
      $this->assertArrayNotHasKey('DATE_TIME tomorrow', $items);
      $this->assertArrayNotHasKey('Undated excluded', $items);
      $this->assertArrayNotHasKey('Completed excluded', $items);
      $this->assertArrayNotHasKey('Edited due', $items);
      $this->assertArrayNotHasKey('H2 unauthorized task', $items);

      $this->assertTrue($mutations->reopenTask((int) $completed->id()));
      $items = $this->byTitle($query->todayOpenTasks($fixedNowUtc, $fixedEndUtc));
      $this->assertArrayHasKey('Completed excluded', $items, 'A reopened Today-relevant task reappears.');

      $mutations->editTask(
        (int) $edited->id(),
        'Edited due',
        PersonalTask::DUE_DATE,
        '2026-04-06',
      );
      $items = $this->byTitle($query->todayOpenTasks($fixedNowUtc, $fixedEndUtc));
      $this->assertArrayHasKey('Edited due', $items, 'A due edit into Today appears on the next read.');

      $mutations->editTask(
        (int) $edited->id(),
        'Edited due',
        PersonalTask::DUE_DATE,
        '2026-04-07',
      );
      $items = $this->byTitle($query->todayOpenTasks($fixedNowUtc, $fixedEndUtc));
      $this->assertArrayNotHasKey('Edited due', $items, 'A due edit out of Today disappears on the next read.');

      $userA->set(HouseholdAuthorizationService::FIELD_NAME, []);
      $userA->save();
      $this->assertSame([], $query->todayOpenTasks($fixedNowUtc, $fixedEndUtc), 'Grant revocation is effective on the next read.');

      $userA->set(HouseholdAuthorizationService::FIELD_NAME, [['target_id' => (int) $household1->id()]]);
      $userA->save();
    }
    finally {
      $accountSwitcher->switchBack();
    }

    $accountSwitcher->switchTo($userB);
    try {
      $this->assertSame([], $query->todayOpenTasks($fixedNowUtc, $fixedEndUtc), 'Same Person does not confer another User Household authority.');

      $userB->set(HouseholdAuthorizationService::FIELD_NAME, [['target_id' => (int) $household1->id()]]);
      $userB->save();
      $items = $this->byTitle($query->todayOpenTasks($fixedNowUtc, $fixedEndUtc));
      $this->assertArrayHasKey('DATE today', $items, 'An independent H1 grant allows the second User to see the same Person task.');
      $this->assertArrayNotHasKey('H2 unauthorized task', $items);

      $userB->set(HouseholdAuthorizationService::FIELD_NAME, []);
      $userB->save();
      $this->assertSame([], $query->todayOpenTasks($fixedNowUtc, $fixedEndUtc));
    }
    finally {
      $accountSwitcher->switchBack();
    }
  }

  /**
   * @param array<int, array<string, mixed>> $items
   *
   * @return array<string, array<string, mixed>>
   */
  private function byTitle(array $items): array {
    $indexed = [];
    foreach ($items as $item) {
      $indexed[(string) $item['title']] = $item;
    }
    return $indexed;
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
