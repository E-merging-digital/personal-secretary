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
 * Proves DATE overdue semantics use the viewing User's civil date.
 *
 * @group personal_secretary
 */
final class PersonalTaskDuePresentationTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testDateDueTodayIsNotOverdueButYesterdayIs(): void {
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

    $person = $domain->createPerson('Synthetic civil-date task person');
    $household = $domain->createHousehold('Synthetic civil-date task household', [(int) $person->id()]);
    $user = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $user);
    $user->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $person->id()]);
    $user->set(HouseholdAuthorizationService::FIELD_NAME, [['target_id' => (int) $household->id()]]);
    $user->set('timezone', 'Europe/Brussels');
    $user->save();

    $timestamp = $this->container->get('datetime.time')->getCurrentTime();
    $localToday = (new DateTimeImmutable('@' . $timestamp))
      ->setTimezone(new DateTimeZone('Europe/Brussels'));
    $today = $localToday->format('Y-m-d');
    $yesterday = $localToday->modify('-1 day')->format('Y-m-d');

    $accountSwitcher = $this->container->get('account_switcher');
    $accountSwitcher->switchTo($user);
    try {
      $dueToday = $mutations->createTask(
        'Synthetic task due today',
        (int) $household->id(),
        PersonalTask::DUE_DATE,
        $today,
      );
      $dueYesterday = $mutations->createTask(
        'Synthetic task due yesterday',
        (int) $household->id(),
        PersonalTask::DUE_DATE,
        $yesterday,
      );
      $undated = $mutations->createTask(
        'Synthetic undated task',
        (int) $household->id(),
        PersonalTask::DUE_NONE,
      );
      $timed = $mutations->createTask(
        'Synthetic timed overdue task',
        (int) $household->id(),
        PersonalTask::DUE_DATE_TIME,
        NULL,
        $yesterday . ' 12:30',
      );

      $formatter = $this->container->get('date.formatter');
      $timezone = new DateTimeZone('Europe/Brussels');
      $expectedDate = $formatter->format(
        (new DateTimeImmutable($yesterday . ' 12:00:00', $timezone))->getTimestamp(),
        'custom', 'j F Y', 'Europe/Brussels',
      );
      $timedUtc = new DateTimeImmutable((string) $timed->get('due_at')->value, new DateTimeZone('UTC'));
      $expectedTimed = $formatter->format(
        $timedUtc->getTimestamp(), 'custom', 'j F Y H:i', 'Europe/Brussels',
      );

      $todayModel = $query->present($dueToday);
      $yesterdayModel = $query->present($dueYesterday);
      $timedModel = $query->present($timed);
      $this->assertFalse($todayModel['overdue'], 'A DATE task due on the viewing User civil date is not overdue merely because local midnight passed.');
      $this->assertTrue($yesterdayModel['overdue'], 'A DATE task becomes overdue only after its civil due date has passed.');
      $this->assertTrue($timedModel['overdue']);
      $this->assertFalse($query->present($undated)['overdue'], 'An undated task is never overdue.');
      $this->assertSame($yesterday, $yesterdayModel['due_label'], 'Machine date remains ISO for domain consumers.');
      $this->assertSame($expectedDate, $yesterdayModel['due_display_label']);
      $this->assertSame($yesterday . ' 12:30', $timedModel['due_label'], 'Machine timestamp label is unchanged.');
      $this->assertSame($expectedTimed, $timedModel['due_display_label']);

      $this->assertTrue($mutations->completeTask((int) $undated->id()));
      $storage = $this->container->get('entity_type.manager')->getStorage(PersonalTask::ENTITY_TYPE_ID);
      $storage->resetCache([(int) $undated->id()]);
      $completed = $storage->load($undated->id());
      $this->assertInstanceOf(PersonalTask::class, $completed);
      $completedAt = (int) $completed->get('completed_at')->value;
      $completedModel = $query->present($completed);
      $this->assertSame(
        (new DateTimeImmutable('@' . $completedAt))->setTimezone($timezone)->format('Y-m-d H:i'),
        $completedModel['completed_label'],
      );
      $this->assertSame(
        $formatter->format($completedAt, 'custom', 'j F Y H:i', 'Europe/Brussels'),
        $completedModel['completed_display_label'],
      );
    }
    finally {
      $accountSwitcher->switchBack();
    }

    $this->drupalLogin($user);
    $this->drupalGet('/personal-secretary/today');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Overdue: ' . $expectedTimed);
    $this->assertSession()->pageTextContains('Overdue: ' . $expectedDate);
    foreach ($this->getSession()->getPage()->findAll('css', '.ps-task-card__due') as $element) {
      $this->assertStringNotContainsString($yesterday, $element->getText());
    }

    $this->drupalGet('/personal-secretary/tasks/mine');
    $this->assertSession()->pageTextContains('Overdue: ' . $expectedTimed);
    $this->assertSession()->pageTextContains('Overdue: ' . $expectedDate);
    foreach ($this->getSession()->getPage()->findAll('css', '.ps-task-row__due') as $element) {
      $this->assertStringNotContainsString($yesterday, $element->getText());
    }

    $this->drupalGet('/personal-secretary/tasks/' . (int) $timed->id());
    $this->assertSession()->pageTextContains('Due: ' . $expectedTimed);
    $this->assertSession()->pageTextNotContains($yesterday . ' 12:30');
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
