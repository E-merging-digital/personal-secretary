<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\personal_secretary\Entity\PersonalTask;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Exercises a real browser session through the no-write guided assistant.
 *
 * @group personal_secretary
 */
#[RunTestsInSeparateProcesses]
final class GuidedAssistantTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'dblog', 'personal_secretary'];
  protected $defaultTheme = 'olivero';

  protected function setUp(): void {
    parent::setUp();
    $this->installReference(CurrentPersonResolver::FIELD_NAME, 'personal_secretary_person', 1);
    $this->installReference(HouseholdAuthorizationService::FIELD_NAME, 'personal_secretary_household', FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
  }

  public function testProtectedTodayGuidanceThreeIntentsAndNoWrite(): void {
    $route = '/personal-secretary/assistant';
    $this->drupalGet($route);
    $this->assertSession()->statusCodeEquals(403);

    $person = $this->container->get('personal_secretary.domain_mutation')->createPerson('Synthetic Guided Reader');
    $h1 = $this->container->get('personal_secretary.domain_mutation')->createHousehold('Synthetic allowed', [(int) $person->id()]);
    $h2 = $this->container->get('personal_secretary.domain_mutation')->createHousehold('Synthetic disallowed', [(int) $person->id()]);
    $authorized = $this->productUser($person, [$h1]);
    $unlinked = $this->productUser(NULL, [$h1]);
    $withoutGrant = $this->productUser($person, []);
    $ordinary = $this->drupalCreateUser([]);

    $now = $this->container->get('datetime.time')->getCurrentTime();
    $localDate = (new \DateTimeImmutable('@' . $now))
      ->setTimezone(new \DateTimeZone('Europe/Brussels'))->format('Y-m-d');
    $storage = $this->container->get('entity_type.manager')->getStorage(PersonalTask::ENTITY_TYPE_ID);
    // Test fixtures are persisted in the isolated test database only.
    foreach ([
      ['Synthetic visible Today task', (int) $h1->id()],
      ['Synthetic forbidden Today task', (int) $h2->id()],
    ] as [$title, $householdId]) {
      $storage->create([
        'title' => $title,
        'household' => $householdId,
        'assigned_person' => (int) $person->id(),
        'due_mode' => PersonalTask::DUE_DATE,
        'due_date' => $localDate,
        'status' => PersonalTask::STATUS_OPEN,
      ])->save();
    }

    $this->drupalLogin($ordinary);
    $this->drupalGet($route);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogout();
    $this->drupalLogin($unlinked);
    $this->drupalGet('/personal-secretary/today');
    $this->assertSession()->linkNotExists('Talk to the assistant');
    $this->drupalGet($route);
    $this->assertSession()->pageTextContains('Link your account to an authorized Household member');
    $this->assertSession()->pageTextNotContains('Synthetic visible Today task');
    $this->assertSession()->elementNotExists('css', 'input[name="op"]');

    $this->drupalLogout();
    $this->drupalLogin($withoutGrant);
    $this->drupalGet($route);
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->pageTextNotContains('Synthetic forbidden Today task');

    $this->drupalLogout();
    $this->drupalLogin($authorized);
    $this->drupalGet('/personal-secretary/today');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkByHrefExists($route);
    $this->assertCount(1, $this->getSession()->getPage()->findAll('css', '.ps-today-assistant__link'));
    $this->assertSession()->elementExists('css', '.ps-today-assistant ~ .ps-today-section--tasks');

    $this->drupalGet($route, ['query' => [
      'user_id' => $ordinary->id(),
      'household_id' => $h2->id(),
    ]]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkByHrefExists('/personal-secretary/today');
    $this->assertSession()->pageTextContains('Choose an action');
    $this->assertSession()->pageTextContains('View my day');
    $this->assertSession()->pageTextContains('Prepare a task');
    $this->assertSession()->pageTextContains('Prepare an activity');
    $this->assertSession()->pageTextNotContains('Synthetic forbidden Today task');
    $this->assertSession()->elementNotExists('css', 'textarea');
    $this->assertSession()->elementNotExists('css', '[name="user_id"]');
    $this->assertSession()->elementNotExists('css', '[name="confirm"]');

    $before = $this->domainCounts();
    $this->submitForm(['intent' => 'GET_TODAY'], 'Show preview');
    $this->assertSession()->pageTextContains('READ_SUCCESS');
    $this->assertSession()->pageTextContains('Synthetic visible Today task');
    $this->assertSession()->pageTextNotContains('Synthetic forbidden Today task');
    $this->assertSession()->pageTextContains('Preview not saved');
    $this->assertSame($before, $this->domainCounts());

    $task = ['intent' => 'PROPOSE_TASK', 'task_title' => 'Synthetic guided task', 'task_due_mode' => PersonalTask::DUE_NONE];
    $this->submitForm($task, 'Show preview');
    // R10: Classify only synthetic server errors, never expose log payloads.
    $exceptionClass = 'NOT_CAPTURED';
    $sourceBasename = 'NOT_CAPTURED';
    $sourceLine = 'UNKNOWN';
    $serverLogPresent = 'NO';
    if ($this->getSession()->getStatusCode() === 500) {
      try {
        $database = $this->container->get('database');
        if ($database->schema()->tableExists('watchdog')) {
          $events = $database->select('watchdog', 'w')
            ->fields('w', ['type', 'variables'])
            ->condition('severity', 3, '<=')
            ->orderBy('wid', 'DESC')
            ->range(0, 5)
            ->execute()
            ->fetchAll();
          $serverLogPresent = $events === [] ? 'NO' : 'YES';
          foreach ($events as $event) {
            if ($event->type !== 'php' || !is_string($event->variables)) {
              continue;
            }
            $values = @unserialize($event->variables, ['allowed_classes' => FALSE]);
            if (!is_array($values) || !is_string($values['%type'] ?? NULL)) {
              continue;
            }
            $type = ltrim($values['%type'], '\\');
            $exceptionClass = in_array($type, [
              'TypeError', 'Error', 'ArgumentCountError',
              'InvalidArgumentException', 'RuntimeException', 'LogicException',
            ], TRUE) ? $type : 'OTHER';
            $file = $values['%file'] ?? NULL;
            if (is_string($file)) {
              $basename = basename(str_replace('\\', '/', $file));
              $sourceBasename = in_array($basename, [
                'GuidedAssistantForm.php', 'ThreeIntentReadProposeService.php',
                'TodayService.php', 'FormBuilder.php', 'FormValidator.php',
              ], TRUE) ? $basename : 'OTHER';
            }
            $line = $values['%line'] ?? NULL;
            if ((is_int($line) && $line > 0)
              || (is_string($line) && ctype_digit($line) && (int) $line > 0)) {
              $sourceLine = (string) (int) $line;
            }
            break;
          }
        }
      }
      catch (\Throwable) {
        // An unavailable diagnostic must not replace the original assertion.
        $exceptionClass = 'NOT_CAPTURED';
        $sourceBasename = 'NOT_CAPTURED';
        $sourceLine = 'UNKNOWN';
        $serverLogPresent = 'NO';
      }
    }
    // R9: Emit bounded synthetic status/field evidence on the first task preview.
    $probeText = $this->getSession()->getPage()->getText();
    $probeStatuses = array_values(array_filter(
      ['READ_SUCCESS', 'PROPOSAL_READY', 'NEEDS_CLARIFICATION', 'ACCESS_DENIED', 'UNAVAILABLE'],
      static fn(string $status): bool => str_contains($probeText, 'Status: ' . $status),
    ));
    $selected = $this->getSession()->getPage()->find('css', 'input[name="intent"]:checked')?->getAttribute('value');
    $selected = in_array($selected, ['GET_TODAY', 'PROPOSE_TASK', 'PROPOSE_ACTIVITY'], TRUE) ? $selected : 'MISSING_OR_OTHER';
    $titleMatches = $this->getSession()->getPage()->findField('task_title')?->getValue() === 'Synthetic guided task';
    $dueNone = $this->getSession()->getPage()->findField('task_due_mode')?->getValue() === PersonalTask::DUE_NONE;
    $this->assertSame(['PROPOSAL_READY'], $probeStatuses, sprintf(
      'R9 synthetic form response: http=%d intent=%s title_matches=%s due_none=%s exception_class=%s source_basename=%s source_line=%s server_log_present=%s',
      $this->getSession()->getStatusCode(),
      $selected,
      $titleMatches ? 'YES' : 'NO',
      $dueNone ? 'YES' : 'NO',
      $exceptionClass,
      $sourceBasename,
      $sourceLine,
      $serverLogPresent,
    ));
    $this->assertSession()->pageTextContains('PROPOSAL_READY');
    $this->assertSession()->pageTextContains('Synthetic guided task');
    $this->assertSession()->pageTextContains('Preview not saved');
    $this->submitForm($task, 'Show preview');
    $this->assertSession()->pageTextContains('PROPOSAL_READY');

    $this->submitForm(['intent' => 'PROPOSE_TASK', 'task_title' => '', 'task_due_mode' => PersonalTask::DUE_DATE], 'Show preview');
    $this->assertSession()->pageTextContains('NEEDS_CLARIFICATION');
    $this->assertSession()->pageTextContains('Enter a valid title.');

    $activity = [
      'intent' => 'PROPOSE_ACTIVITY',
      'activity_label' => 'Synthetic guided walk',
      'activity_date' => '2026-10-22',
      'activity_mode' => 'timed',
      'activity_start' => '09:00',
      'activity_end' => '10:00',
      'activity_responsible_self' => 1,
    ];
    $this->submitForm($activity, 'Show preview');
    $this->assertSession()->pageTextContains('PROPOSAL_READY');
    $this->assertSession()->pageTextContains('Synthetic guided walk');
    $this->assertSession()->pageTextContains('Preview not saved');
    $this->submitForm($activity, 'Show preview');
    $this->assertSession()->pageTextContains('PROPOSAL_READY');

    $activity['activity_end'] = '';
    $this->submitForm($activity, 'Show preview');
    $this->assertSession()->pageTextContains('NEEDS_CLARIFICATION');
    $this->assertSession()->pageTextContains('Enter an end time after the start.');

    $activity['activity_mode'] = 'all_day';
    $this->submitForm($activity, 'Show preview');
    $this->assertSession()->pageTextContains('PROPOSAL_READY');
    $this->assertSame($before, $this->domainCounts(), 'Preview and repeated submit must not persist entities.');

    $this->drupalGet($route);
    $this->assertSession()->pageTextNotContains('Synthetic guided walk');
    $this->assertSession()->pageTextNotContains('PROPOSAL_READY');
    $this->assertSession()->linkByHrefExists('/personal-secretary/today');

    // Existing language negotiation is reused, not a new translation catalog.
    if (ConfigurableLanguage::load('fr') === NULL) {
      ConfigurableLanguage::createFromLangcode('fr')->save();
    }
    $this->config('language.negotiation')
      ->set('url.source', 'path_prefix')
      ->set('url.prefixes', ['fr' => 'fr', 'en' => 'en'])
      ->set('url.domains', ['fr' => '', 'en' => ''])
      ->set('selected_langcode', 'site_default')
      ->save();
    $this->config('language.types')
      ->set('negotiation.language_interface.enabled', [
        'language-url' => 0,
        'language-user' => 1,
        'language-selected' => 2,
      ])->save();
    $this->container->get('language_manager')->reset();

    $this->drupalGet('/fr/personal-secretary/assistant');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Choisir une action');
    $this->assertSession()->pageTextContains('Préparer une tâche');
    $this->assertSession()->linkByHrefExists('/fr/personal-secretary/today');
    $this->drupalGet('/en/personal-secretary/assistant');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Choose an action');
  }

  /**
   * @param object[] $households
   */
  private function productUser(?object $person, array $households): UserInterface {
    $user = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    self::assertInstanceOf(UserInterface::class, $user);
    if ($person !== NULL) {
      $user->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $person->id()]);
    }
    if ($households !== []) {
      $user->set(HouseholdAuthorizationService::FIELD_NAME, array_map(
        static fn(object $h): array => ['target_id' => (int) $h->id()], $households,
      ));
    }
    $user->set('timezone', 'Europe/Brussels');
    $user->save();
    return $user;
  }

  /**
   * @return array<string, int>
   */
  private function domainCounts(): array {
    $manager = $this->container->get('entity_type.manager');
    $counts = [];
    foreach ([
      'task' => 'personal_sec_task',
      'series' => 'personal_sec_activity_series',
      'preparations' => 'personal_sec_prep_req',
      'persons' => 'personal_secretary_person',
      'households' => 'personal_secretary_household',
    ] as $key => $entityType) {
      $counts[$key] = count($manager->getStorage($entityType)->loadMultiple());
    }
    return $counts;
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
