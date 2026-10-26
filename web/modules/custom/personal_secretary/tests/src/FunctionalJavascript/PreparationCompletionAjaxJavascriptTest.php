<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\FunctionalJavascript;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\personal_secretary\Entity\PreparationCompletion;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves inline preparation transitions without losing the active horizon.
 *
 * @group personal_secretary
 */
#[RunTestsInSeparateProcesses]
final class PreparationCompletionAjaxJavascriptTest extends WebDriverTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];
  protected $defaultTheme = 'personal_secretary_product';

  public function testInlineUndoHorizonFocusAndCurrentUserIsolation(): void {
    $this->installUserReferenceField(CurrentPersonResolver::FIELD_NAME, 'personal_secretary_person', 1);
    $this->installUserReferenceField(HouseholdAuthorizationService::FIELD_NAME, 'personal_secretary_household', FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    $this->config('system.date')->set('timezone.default', 'Europe/Brussels')->save();

    $domain = $this->container->get('personal_secretary.domain_mutation');
    $requirements = $this->container->get('personal_secretary.preparation_requirement_mutation');
    $responsibility = $this->container->get('personal_secretary.responsibility_mutation');
    $person = $domain->createPerson('Synthetic AJAX owner');
    $otherPerson = $domain->createPerson('Synthetic AJAX other');
    $household = $domain->createHousehold('Synthetic AJAX household', [(int) $person->id()]);
    $otherHousehold = $domain->createHousehold('Synthetic AJAX other household', [(int) $otherPerson->id()]);
    $user = $this->productUser((int) $person->id(), (int) $household->id());
    $otherUser = $this->productUser((int) $otherPerson->id(), (int) $otherHousehold->id());

    $now = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone(new DateTimeZone('UTC'));
    foreach ([
      ['Synthetic AJAX near preparation', (int) $household->id(), (int) $person->id(), '+2 days'],
      ['Synthetic AJAX day twenty-one preparation', (int) $household->id(), (int) $person->id(), '+21 days'],
      ['Synthetic AJAX private preparation', (int) $otherHousehold->id(), (int) $otherPerson->id(), '+2 days'],
    ] as [$instruction, $householdId, $personId, $delay]) {
      $start = $now->modify($delay);
      $series = $domain->createActivitySeries(
        'Activity for ' . $instruction,
        $householdId,
        $start,
        $start->modify('+1 hour'),
        'FREQ=DAILY;COUNT=1',
      );
      $responsibility->createResponsibilityRule($series, $personId, $start, $start->modify('+1 hour'), 'FREQ=DAILY;COUNT=1');
      $requirements->createPreparationRequirement($series, $instruction, 3600, $now->modify('-1 day'));
    }

    $mine = '/personal-secretary/preparations/mine';
    $this->drupalLogin($user);
    $this->getSession()->resizeWindow(390, 844);
    $this->drupalGet($mine, ['query' => ['days' => '30']]);
    $this->assertSession()->elementExists('css', '#ps-preparation-live');
    $this->assertSession()->pageTextContains('Synthetic AJAX day twenty-one preparation');
    $this->assertSession()->pageTextNotContains('Synthetic AJAX private preparation');
    $this->assertSession()->elementExists('css', 'a[aria-current="page"][href$="days=30"]');
    $this->assertSame('status', $this->assertSession()->elementExists('css', '#ps-preparation-feedback')->getAttribute('role'));
    $this->getSession()->executeScript('window.psPreparationAjaxPage = "same-document";');

    $rowId = $this->rowId('Synthetic AJAX day twenty-one preparation');
    $this->clickAction('Synthetic AJAX day twenty-one preparation', 'Mark prepared');
    $this->waitForRowState($rowId, 'prepared');
    $this->assertSession()->addressEquals($mine . '?days=30');
    $this->assertSame('same-document', $this->getSession()->evaluateScript('window.psPreparationAjaxPage'));
    $this->assertSession()->pageTextContains('Preparation marked prepared.');
    $this->assertSession()->elementExists('css', '#' . $rowId . ' input[value="Mark not prepared"]');
    $this->assertTrue((bool) $this->getSession()->evaluateScript(
      'document.activeElement?.matches("#' . $rowId . ' input[value=\\"Mark not prepared\\"]")',
    ));
    $this->assertSame('dashed', $this->getSession()->evaluateScript(
      'getComputedStyle(document.getElementById("' . $rowId . '")).borderTopStyle',
    ));
    $this->assertSame('rgb(96, 112, 128)', $this->getSession()->evaluateScript(
      'getComputedStyle(document.querySelector("#' . $rowId . ' .personal-secretary-preparation-item h3")).color',
    ));
    $this->assertCount(1, $this->completionRows());

    $this->clickAction('Synthetic AJAX day twenty-one preparation', 'Mark not prepared');
    $this->waitForRowState($rowId, 'pending');
    $this->assertSession()->pageTextContains('Preparation marked not prepared.');
    $this->assertSession()->elementExists('css', '#' . $rowId . ' input[value="Mark prepared"]');
    $this->assertSession()->addressEquals($mine . '?days=30');
    $this->assertSame('same-document', $this->getSession()->evaluateScript('window.psPreparationAjaxPage'));
    $this->assertCount(0, $this->completionRows());

    // Default seven-day view also updates in place without admitting day 21.
    $this->drupalGet($mine);
    $this->assertSession()->pageTextNotContains('Synthetic AJAX day twenty-one preparation');
    $this->getSession()->executeScript('window.psPreparationAjaxPage = "seven-days";');
    $nearId = $this->rowId('Synthetic AJAX near preparation');
    $this->clickAction('Synthetic AJAX near preparation', 'Mark prepared');
    $this->waitForRowState($nearId, 'prepared');
    $this->assertSession()->addressEquals($mine);
    $this->assertSame('seven-days', $this->getSession()->evaluateScript('window.psPreparationAjaxPage'));
    $this->assertSession()->elementExists('css', 'a[aria-current="page"][href$="days=7"]');
    $this->assertSession()->pageTextNotContains('Synthetic AJAX day twenty-one preparation');
    $this->assertCount(1, $this->completionRows());

    // Another household's user only sees their own derived preparations.
    $this->drupalLogout();
    $this->drupalLogin($otherUser);
    $this->drupalGet($mine, ['query' => ['days' => '30']]);
    $this->assertSession()->pageTextContains('Synthetic AJAX private preparation');
    $this->assertSession()->pageTextNotContains('Synthetic AJAX near preparation');
    $this->assertSession()->pageTextNotContains('Synthetic AJAX day twenty-one preparation');
  }

  private function rowId(string $instruction): string {
    $row = $this->assertSession()->elementExists(
      'xpath',
      '//div[contains(@class, "ps-preparation-row")][.//h3[normalize-space()="' . $instruction . '"]]',
    );
    return (string) $row->getAttribute('id');
  }

  private function clickAction(string $instruction, string $label): void {
    $button = $this->assertSession()->elementExists(
      'xpath',
      '//div[contains(@class, "ps-preparation-row")][.//h3[normalize-space()="' . $instruction . '"]]//input[@type="submit" and @value="' . $label . '"]',
    );
    $button->click();
  }

  private function waitForRowState(string $rowId, string $state): void {
    $this->assertNotNull($this->assertSession()->waitForElement(
      'css',
      '#' . $rowId . '.ps-preparation-row--' . $state,
      10000,
    ));
  }

  private function completionRows(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage(PreparationCompletion::ENTITY_TYPE_ID);
    $storage->resetCache();
    return $storage->loadMultiple();
  }

  private function productUser(int $personId, int $householdId): UserInterface {
    $user = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $user);
    $user->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => $personId]);
    $user->set(HouseholdAuthorizationService::FIELD_NAME, [['target_id' => $householdId]]);
    $user->set('timezone', 'Europe/Brussels');
    $user->save();
    return $user;
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
