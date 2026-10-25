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
 * Proves explicit, single-step task creation from the Today surface.
 *
 * @group personal_secretary
 */
final class TodayQuickTaskTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testQuickTaskCreationWithLocalDateAndHouseholdScope(): void {
    $todayUrl = '/personal-secretary/today';
    $this->drupalGet($todayUrl);
    $this->assertSession()->statusCodeEquals(403);

    $this->installUserReferenceField(CurrentPersonResolver::FIELD_NAME, 'personal_secretary_person', 1, 'Personal Secretary person');
    $this->installUserReferenceField(HouseholdAuthorizationService::FIELD_NAME, 'personal_secretary_household', FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED, 'Personal Secretary households');

    $domain = $this->container->get('personal_secretary.domain_mutation');
    $person = $domain->createPerson('Quick task person');
    $h1 = $domain->createHousehold('Quick task household one', [(int) $person->id()]);
    $h2 = $domain->createHousehold('Quick task household two', [(int) $person->id()]);
    $h3 = $domain->createHousehold('Quick task forbidden household', [(int) $person->id()]);

    $user = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $user);
    $user->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $person->id()]);
    $user->set(HouseholdAuthorizationService::FIELD_NAME, [['target_id' => (int) $h1->id()]]);
    $user->set('timezone', 'Europe/Brussels');
    $user->save();

    $unlinked = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $unlinked->set(HouseholdAuthorizationService::FIELD_NAME, [['target_id' => (int) $h1->id()]]);
    $unlinked->save();

    $storage = $this->container->get('entity_type.manager')->getStorage(PersonalTask::ENTITY_TYPE_ID);
    $window = $this->container->get('personal_secretary.today')->windowFor(
      $user,
      (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))->setTimezone(new DateTimeZone('UTC')),
    );
    $localDate = $window['local_date'];
    $tomorrow = (new DateTimeImmutable($localDate, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');

    $this->drupalLogin($user);
    $this->drupalGet($todayUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('quick_task_title');
    $this->assertSession()->fieldExists('quick_task_due');
    $this->assertSession()->fieldNotExists('quick_task_household');
    $this->assertSession()->buttonExists('Add task now');
    $this->assertSession()->linkByHrefExists('/personal-secretary/tasks/add');
    $this->assertSame([], $storage->loadMultiple());

    // Invalid input is rejected without creating any task.
    $this->submitForm([
      'quick_task_title' => '',
      'quick_task_due' => 'today',
    ], 'Add task now');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame([], $storage->loadMultiple());

    // A POST explicitly creates a self-owned task for the user's civil day.
    $this->drupalGet($todayUrl);
    $this->submitForm([
      'quick_task_title' => 'Today quick task',
      'quick_task_due' => 'today',
    ], 'Add task now');
    $this->assertSession()->addressEquals($todayUrl);
    $this->assertSession()->pageTextContains('Today quick task');
    $todayTasks = $storage->loadByProperties(['title' => 'Today quick task']);
    $this->assertCount(1, $todayTasks);
    $task = reset($todayTasks);
    $this->assertInstanceOf(PersonalTask::class, $task);
    $this->assertSame((int) $h1->id(), (int) $task->get('household')->target_id);
    $this->assertSame((int) $person->id(), (int) $task->get('assigned_person')->target_id);
    $this->assertSame(PersonalTask::DUE_DATE, (string) $task->get('due_mode')->value);
    $this->assertSame($localDate, (string) $task->get('due_date')->value);
    $this->assertSame(PersonalTask::STATUS_OPEN, (string) $task->get('status')->value);

    // Refresh uses PRG and never duplicates the task.
    $this->getSession()->reload();
    $this->assertCount(1, $storage->loadByProperties(['title' => 'Today quick task']));

    $this->submitForm([
      'quick_task_title' => 'Tomorrow quick task',
      'quick_task_due' => 'tomorrow',
    ], 'Add task now');
    $this->assertSession()->addressEquals($todayUrl);
    $tomorrowTasks = $storage->loadByProperties(['title' => 'Tomorrow quick task']);
    $this->assertCount(1, $tomorrowTasks);
    $tomorrowTask = reset($tomorrowTasks);
    $this->assertSame($tomorrow, (string) $tomorrowTask->get('due_date')->value);
    $this->assertSession()->pageTextNotContains('Tomorrow quick task');

    $this->submitForm([
      'quick_task_title' => 'Undated quick task',
      'quick_task_due' => 'none',
    ], 'Add task now');
    $undatedTasks = $storage->loadByProperties(['title' => 'Undated quick task']);
    $this->assertCount(1, $undatedTasks);
    $undatedTask = reset($undatedTasks);
    $this->assertSame(PersonalTask::DUE_NONE, (string) $undatedTask->get('due_mode')->value);
    $this->assertSame('', (string) ($undatedTask->get('due_date')->value ?? ''));

    // Multiple grants require an explicit, eligible Household selection.
    $user->set(HouseholdAuthorizationService::FIELD_NAME, [
      ['target_id' => (int) $h1->id()],
      ['target_id' => (int) $h2->id()],
    ]);
    $user->save();
    $this->drupalGet($todayUrl);
    $this->assertSession()->fieldExists('quick_task_household');
    $this->assertSession()->pageTextNotContains((string) $h3->label());
    $this->submitForm([
      'quick_task_title' => 'Second household quick task',
      'quick_task_due' => 'none',
      'quick_task_household' => (string) $h2->id(),
    ], 'Add task now');
    $selectedTasks = $storage->loadByProperties(['title' => 'Second household quick task']);
    $this->assertCount(1, $selectedTasks);
    $selectedTask = reset($selectedTasks);
    $this->assertSame((int) $h2->id(), (int) $selectedTask->get('household')->target_id);
    $this->drupalLogout();

    // An account without a linked CurrentPerson cannot create tasks from Today.
    $this->drupalLogin($unlinked);
    $this->drupalGet($todayUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->buttonNotExists('Add task now');
    $this->assertSession()->fieldNotExists('quick_task_title');
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
