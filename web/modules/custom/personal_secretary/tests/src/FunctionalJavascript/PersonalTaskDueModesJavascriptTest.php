<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\FunctionalJavascript;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\personal_secretary\Entity\PersonalTask;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies task deadline widgets switch exclusively in a real browser.
 *
 * @group personal_secretary
 */
#[RunTestsInSeparateProcesses]
final class PersonalTaskDueModesJavascriptTest extends WebDriverTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testAddAndEditShowOnlyTheSelectedDueWidget(): void {
    $this->installUserReferenceField(CurrentPersonResolver::FIELD_NAME, 'personal_secretary_person', 1);
    $this->installUserReferenceField(HouseholdAuthorizationService::FIELD_NAME, 'personal_secretary_household', FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    $domain = $this->container->get('personal_secretary.domain_mutation');
    $person = $domain->createPerson('Synthetic due-mode browser person');
    $household = $domain->createHousehold('Synthetic due-mode browser household', [(int) $person->id()]);
    $user = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $user);
    $user->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $person->id()]);
    $user->set(HouseholdAuthorizationService::FIELD_NAME, [['target_id' => (int) $household->id()]]);
    $user->save();

    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($user);
    try {
      $task = $this->container->get('personal_secretary.personal_task_mutation')->createTask(
        'Synthetic task with editable due mode',
        (int) $household->id(),
        PersonalTask::DUE_NONE,
      );
    }
    finally {
      $switcher->switchBack();
    }

    $this->drupalLogin($user);
    $this->getSession()->resizeWindow(390, 844);
    $this->drupalGet('/personal-secretary/tasks/add');
    $this->assertDueWidgets(FALSE, FALSE);
    $this->selectDueMode(PersonalTask::DUE_DATE);
    $this->assertDueWidgets(TRUE, FALSE);
    $this->selectDueMode(PersonalTask::DUE_DATE_TIME);
    $this->assertDueWidgets(FALSE, TRUE);
    $this->selectDueMode(PersonalTask::DUE_NONE);
    $this->assertDueWidgets(FALSE, FALSE);

    // The same conditional widgets must behave consistently while editing.
    $this->getSession()->resizeWindow(1280, 900);
    $this->drupalGet('/personal-secretary/tasks/' . $task->id() . '/edit');
    $this->assertDueWidgets(FALSE, FALSE);
    $this->selectDueMode(PersonalTask::DUE_DATE_TIME);
    $this->assertDueWidgets(FALSE, TRUE);
    $this->selectDueMode(PersonalTask::DUE_DATE);
    $this->assertDueWidgets(TRUE, FALSE);
    $this->selectDueMode(PersonalTask::DUE_NONE);
    $this->assertDueWidgets(FALSE, FALSE);
  }

  private function selectDueMode(string $mode): void {
    // Mink's select interaction dispatches native browser change events.
    $this->getSession()->getPage()->selectFieldOption('due_mode', $mode);
  }

  private function assertDueWidgets(bool $dateVisible, bool $dateTimeVisible): void {
    $expectedDate = $dateVisible ? 'true' : 'false';
    $expectedDateTime = $dateTimeVisible ? 'true' : 'false';
    $this->assertJsCondition(<<<JS
(() => {
  const visible = (name) => {
    const input = document.querySelector('input[name="' + name + '"]');
    return !!input && input.getClientRects().length > 0;
  };
  return visible('due_date') === $expectedDate
    && visible('due_at[date]') === $expectedDateTime
    && visible('due_at[time]') === $expectedDateTime;
})()
JS);
  }

  private function installUserReferenceField(string $name, string $target, int $cardinality): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'user',
      'type' => 'entity_reference',
      'settings' => ['target_type' => $target],
      'cardinality' => $cardinality,
    ])->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => $name,
      'settings' => ['handler' => 'default:' . $target],
    ])->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

}
