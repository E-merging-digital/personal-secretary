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

      $this->assertFalse($query->present($dueToday)['overdue'], 'A DATE task due on the viewing User civil date is not overdue merely because local midnight passed.');
      $this->assertTrue($query->present($dueYesterday)['overdue'], 'A DATE task becomes overdue only after its civil due date has passed.');
      $this->assertFalse($query->present($undated)['overdue'], 'An undated task is never overdue.');
    }
    finally {
      $accountSwitcher->switchBack();
    }
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
