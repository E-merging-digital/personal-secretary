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
 * Proves reversible inline task completion with scoped server-side reads.
 *
 * @group personal_secretary
 */
#[RunTestsInSeparateProcesses]
final class PersonalTaskInlineCompletionAjaxJavascriptTest extends WebDriverTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];
  protected $defaultTheme = 'personal_secretary_product';

  public function testInlineCompletionUndoFiltersAndHouseholdIsolation(): void {
    $this->installUserReferenceField(CurrentPersonResolver::FIELD_NAME, 'personal_secretary_person', 1);
    $this->installUserReferenceField(HouseholdAuthorizationService::FIELD_NAME, 'personal_secretary_household', FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    $domain = $this->container->get('personal_secretary.domain_mutation');
    $tasks = $this->container->get('personal_secretary.personal_task_mutation');
    $person = $domain->createPerson('Synthetic AJAX task owner');
    $outsider = $domain->createPerson('Synthetic AJAX task outsider');
    $household = $domain->createHousehold('Synthetic AJAX task household', [(int) $person->id()]);
    $otherHousehold = $domain->createHousehold('Synthetic AJAX private household', [(int) $outsider->id()]);
    $user = $this->taskUser((int) $person->id(), (int) $household->id());
    $otherUser = $this->taskUser((int) $outsider->id(), (int) $otherHousehold->id());
    $unlinkedUser = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);

    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($user);
    try {
      $alpha = $tasks->createTask('Synthetic AJAX Alpha', (int) $household->id(), PersonalTask::DUE_NONE);
      $beta = $tasks->createTask('Synthetic AJAX Beta', (int) $household->id(), PersonalTask::DUE_NONE);
    }
    finally {
      $switcher->switchBack();
    }
    $switcher->switchTo($otherUser);
    try {
      $private = $tasks->createTask('Synthetic AJAX private task', (int) $otherHousehold->id(), PersonalTask::DUE_NONE);
    }
    finally {
      $switcher->switchBack();
    }

    $alphaId = (int) $alpha->id();
    $betaId = (int) $beta->id();
    $privateId = (int) $private->id();
    $mine = '/personal-secretary/tasks/mine';
    $this->drupalLogin($user);
    $this->getSession()->resizeWindow(390, 844);
    $this->drupalGet($mine);
    $this->assertSession()->elementExists('css', '#ps-task-live');
    $this->assertSession()->elementExists('css', '#ps-task-feedback[role="status"][aria-live="polite"]');
    $this->assertSession()->elementExists('css', $this->rowSelector($alphaId));
    $this->assertSession()->elementExists('css', $this->rowSelector($betaId));
    $this->assertSession()->pageTextNotContains('Synthetic AJAX private task');
    $this->markPage('open-view');

    $this->clickComplete($alphaId);
    $this->waitForUndo();
    $this->assertSession()->elementNotExists('css', $this->rowSelector($alphaId));
    $this->assertSession()->elementExists('css', $this->rowSelector($betaId));
    $this->assertSession()->addressEquals($mine);
    $this->assertPageMarker('open-view');
    $this->assertTaskStatus($alphaId, PersonalTask::STATUS_COMPLETED);
    $this->assertTaskStatus($betaId, PersonalTask::STATUS_OPEN);
    $this->assertTaskStatus($privateId, PersonalTask::STATUS_OPEN);
    $this->assertSession()->pageTextContains('Task completed. You can reopen it below if this was accidental.');
    $this->assertFocused('.ps-task-undo__action');

    $this->clickUndo();
    $this->waitForComplete($alphaId);
    $this->assertSession()->elementNotExists('css', '.ps-task-undo__action');
    $this->assertSession()->addressEquals($mine);
    $this->assertPageMarker('open-view');
    $this->assertTaskStatus($alphaId, PersonalTask::STATUS_OPEN);
    $this->assertFocused($this->rowSelector($alphaId) . ' .ps-task-row__complete');
    $this->assertSession()->pageTextContains('Task reopened.');

    // The same actions preserve a filtered all-status URL and its server view.
    $allUrl = $mine . '?status=all&q=Synthetic';
    $this->drupalGet($mine, ['query' => ['status' => 'all', 'q' => 'Synthetic']]);
    $this->assertSession()->addressEquals($allUrl);
    $this->assertSession()->elementExists('css', $this->rowSelector($alphaId));
    $this->assertSession()->elementExists('css', $this->rowSelector($betaId));
    $this->markPage('filtered-all');

    $this->clickComplete($betaId);
    $this->waitForUndo();
    $this->assertSession()->elementExists('css', $this->rowSelector($betaId) . ' .ps-task-row__status');
    $this->assertSame('Completed', trim($this->assertSession()->elementExists('css', $this->rowSelector($betaId) . ' .ps-task-row__status')->getText()));
    $this->assertSession()->addressEquals($allUrl);
    $this->assertPageMarker('filtered-all');
    $this->assertTaskStatus($betaId, PersonalTask::STATUS_COMPLETED);
    $this->assertFocused('.ps-task-undo__action');

    $this->clickUndo();
    $this->waitForComplete($betaId);
    $this->assertSession()->elementNotExists('css', '.ps-task-undo__action');
    $this->assertSame('Open', trim($this->assertSession()->elementExists('css', $this->rowSelector($betaId) . ' .ps-task-row__status')->getText()));
    $this->assertSession()->addressEquals($allUrl);
    $this->assertPageMarker('filtered-all');
    $this->assertTaskStatus($betaId, PersonalTask::STATUS_OPEN);
    $this->assertFocused($this->rowSelector($betaId) . ' .ps-task-row__complete');

    // Search is a separate GET/redirect action, not an AJAX mutation.
    $this->getSession()->getPage()->fillField('Search task titles', 'Beta');
    $this->getSession()->getPage()->pressButton('Search tasks');
    $searchNavigated = $this->getSession()->wait(
      5000,
      'location.pathname === "/personal-secretary/tasks/mine" && new URLSearchParams(location.search).get("q") === "Beta"',
    );
    if (!$searchNavigated) {
      $diagnostic = (string) $this->getSession()->evaluateScript(<<<'JS'
JSON.stringify({
  path: location.pathname + location.search,
  submittedSearchValue: document.querySelector('[name="title"]')?.value || null,
  selectedStatus: document.querySelector('[name="status"]')?.value || null,
  ajaxResponses: window.psTaskAjaxResponses || [],
  errors: window.psTaskJavascriptErrors || [],
})
JS);
      $this->fail('Search tasks did not navigate via GET/redirect: ' . $diagnostic);
    }
    $this->assertSession()->addressEquals($mine . '?status=all&q=Beta');
    $this->assertSession()->pageTextContains('Synthetic AJAX Beta');
    $this->assertSession()->pageTextNotContains('Synthetic AJAX Alpha');
    $this->assertTaskStatus($alphaId, PersonalTask::STATUS_OPEN);
    $this->assertTaskStatus($betaId, PersonalTask::STATUS_OPEN);

    // Unlinked users cannot see or mutate the owner/private household tasks.
    $this->drupalLogout();
    $this->drupalLogin($unlinkedUser);
    $this->drupalGet($mine);
    $this->assertSession()->pageTextContains('Link your account to a valid Household member to see My tasks.');
    $this->assertSession()->elementNotExists('css', '.ps-task-row__complete');
    $this->assertTaskStatus($alphaId, PersonalTask::STATUS_OPEN);
    $this->assertTaskStatus($privateId, PersonalTask::STATUS_OPEN);
  }

  private function rowSelector(int $id): string {
    return '[data-ps-task-id="' . $id . '"]';
  }

  private function clickComplete(int $id): void {
    $this->assertSession()->elementExists('css', $this->rowSelector($id) . ' .ps-task-row__complete')->click();
  }

  private function clickUndo(): void {
    $this->assertSession()->elementExists('css', '.ps-task-undo__action')->click();
  }

  private function waitForUndo(): void {
    $undo = $this->assertSession()->waitForElement('css', '#ps-task-live .ps-task-undo__action', 10000);
    $this->assertNotNull($undo, 'AJAX completion must render the authorized Undo button.');
  }

  private function waitForComplete(int $id): void {
    $button = $this->assertSession()->waitForElement(
      'css',
      '#ps-task-live ' . $this->rowSelector($id) . ' .ps-task-row__complete',
      10000,
    );
    if ($button === NULL) {
      $diagnostic = (string) $this->getSession()->evaluateScript(<<<'JS'
JSON.stringify({
  path: location.pathname + location.search,
  marker: window.psTaskDocumentMarker || null,
  feedback: document.querySelector('#ps-task-feedback')?.textContent?.trim() || null,
  undo: !!document.querySelector('.ps-task-undo__action'),
  rowIds: [...document.querySelectorAll('[data-ps-task-id]')].map((row) => row.dataset.psTaskId),
  errors: window.psTaskJavascriptErrors || [],
  responses: window.psTaskAjaxResponses || [],
  ajaxBusy: Object.values(Drupal.ajax?.instances || {}).filter((value) => value?.ajaxing).length,
})
JS);
      $this->fail('AJAX reopening did not render completion: ' . $diagnostic);
    }
  }

  private function markPage(string $marker): void {
    $this->getSession()->executeScript('window.psTaskDocumentMarker = ' . json_encode($marker, JSON_THROW_ON_ERROR) . ';');
    $this->getSession()->executeScript(<<<'JS'
window.psTaskAjaxResponses = [];
window.psTaskJavascriptErrors = [];
window.addEventListener('error', (event) => window.psTaskJavascriptErrors.push(event.message));
if (window.jQuery) {
  jQuery(document).ajaxComplete((_event, xhr, options) => {
    let commands = [];
    try {
      const body = JSON.parse(xhr.responseText);
      commands = Array.isArray(body)
        ? body.map(({command, selector}) => ({command, selector}))
        : ['unexpected-response'];
    }
    catch {
      commands = ['non-json-response'];
    }
    window.psTaskAjaxResponses.push({
      status: xhr.status,
      path: options.url?.split('?')[0],
      commands,
    });
  });
}
JS);
  }

  private function assertPageMarker(string $marker): void {
    $this->assertSame($marker, $this->getSession()->evaluateScript('window.psTaskDocumentMarker'));
  }

  private function assertFocused(string $selector): void {
    $this->assertTrue((bool) $this->getSession()->evaluateScript(
      'document.activeElement === document.querySelector(' . json_encode($selector, JSON_THROW_ON_ERROR) . ')',
    ), 'Keyboard focus must follow the reversible task action.');
  }

  private function assertTaskStatus(int $id, string $status): void {
    $storage = $this->container->get('entity_type.manager')->getStorage(PersonalTask::ENTITY_TYPE_ID);
    $storage->resetCache([$id]);
    $task = $storage->load($id);
    $this->assertInstanceOf(PersonalTask::class, $task);
    $this->assertSame($status, (string) $task->get('status')->value);
  }

  private function taskUser(int $personId, int $householdId): UserInterface {
    $user = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $user);
    $user->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => $personId]);
    $user->set(HouseholdAuthorizationService::FIELD_NAME, [['target_id' => $householdId]]);
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
