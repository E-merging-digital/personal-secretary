<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\FunctionalJavascript;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Url;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\personal_secretary\Entity\TimeCommitmentRule;
use Drupal\personal_secretary\Service\EditTimeCommitmentService;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves only changed visible time-commitment form fields enable Save.
 *
 * @group personal_secretary
 */
#[RunTestsInSeparateProcesses]
final class TimeCommitmentDirtyStateJavascriptTest extends WebDriverTestBase {

  protected static $modules = ['personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testCleanDirtyRevertCancelAndValidSave(): void {
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $person = $domain->createPerson('Synthetic commitment browser person');
    $household = $domain->createHousehold(
      'Synthetic commitment browser household',
      [(int) $person->id()],
    );
    $timezone = new DateTimeZone('Europe/Brussels');
    $now = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone($timezone);
    $firstStart = $now->modify('+1 day')->setTime(9, 0);
    $series = $domain->createActivitySeries(
      'Synthetic recurring commitment browser',
      (int) $household->id(),
      $firstStart,
      $firstStart->modify('+1 hour'),
      'FREQ=WEEKLY;INTERVAL=1',
    );
    $seriesId = (int) $series->id();
    $storage = $this->container->get('entity_type.manager')
      ->getStorage(TimeCommitmentRule::ENTITY_TYPE_ID);
    $seriesStorage = $this->container->get('entity_type.manager')
      ->getStorage('personal_sec_activity_series');
    $revision = (string) $series->getRevisionId();
    $editorUrl = Url::fromRoute('personal_secretary.edit_time_commitment', [
      'series' => $seriesId,
    ])->toString();
    $upcomingUrl = Url::fromRoute('personal_secretary.upcoming')->toString();

    $admin = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->drupalLogin($admin);
    $this->getSession()->resizeWindow(390, 844);
    $this->drupalGet($editorUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', 'form[data-ps-time-commitment-editor]');
    $this->assertSession()->elementExists('css', '[data-ps-time-commitment-save]');
    $this->assertSession()->elementExists('css', '[data-ps-time-commitment-clean]');

    $originalDate = (string) $this->getSession()->evaluateScript(
      'document.querySelector("[data-ps-time-commitment-date]").value',
    );
    $originalMode = (string) $this->getSession()->evaluateScript(
      'document.querySelector("form[data-ps-time-commitment-editor] input[name=mode]:checked").value',
    );
    $this->assertSame(EditTimeCommitmentService::MODE_NONE, $originalMode);
    $otherDate = (new DateTimeImmutable($originalDate))->modify('+1 day')->format('Y-m-d');

    $this->assertSaveState(FALSE);
    $this->assertSession()->pageTextContains('No changes to save.');
    $this->assertNoMutation($storage, $seriesStorage, $seriesId, $revision);

    // Both input and change events are supported, including keyboard edits.
    $this->setDate($otherDate);
    $this->assertSaveState(TRUE);
    $this->setDate($originalDate);
    $this->assertSaveState(FALSE);

    $this->setMode(EditTimeCommitmentService::MODE_FULL_OCCURRENCE);
    $this->assertSaveState(TRUE);
    $this->setMode($originalMode);
    $this->assertSaveState(FALSE);

    // The form becomes clean only when both rendered defaults are restored.
    $this->setDate($otherDate);
    $this->setMode(EditTimeCommitmentService::MODE_FULL_OCCURRENCE);
    $this->setDate($originalDate);
    $this->assertSaveState(TRUE);
    $this->setMode($originalMode);
    $this->assertSaveState(FALSE);

    // A Drupal behavior reattachment must not create duplicate helpers or
    // incorrectly reset the initial comparison values.
    $this->getSession()->executeScript('Drupal.attachBehaviors(document, drupalSettings);');
    $this->getSession()->executeScript('Drupal.attachBehaviors(document, drupalSettings);');
    $this->assertSame(1, (int) $this->getSession()->evaluateScript(
      'document.querySelectorAll("[data-ps-time-commitment-clean]").length',
    ));
    $this->setMode(EditTimeCommitmentService::MODE_FULL_OCCURRENCE);
    $this->assertSaveState(TRUE);
    $this->assertNoMutation($storage, $seriesStorage, $seriesId, $revision);

    // Cancel remains a non-mutating navigation even after visible edits.
    $this->assertSession()->linkByHrefExists($upcomingUrl);
    $this->clickLink('Cancel');
    $this->assertSession()->addressEquals($upcomingUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertNoMutation($storage, $seriesStorage, $seriesId, $revision);

    // A valid changed form still goes through the original server submit.
    $this->drupalGet($editorUrl);
    $this->assertSaveState(FALSE);
    $this->setMode(EditTimeCommitmentService::MODE_FULL_OCCURRENCE);
    $this->assertSaveState(TRUE);
    $this->submitForm([], 'Save time commitment');
    $this->assertSession()->addressEquals($upcomingUrl);
    $rules = array_values($storage->loadMultiple());
    $this->assertCount(1, $rules);
    $rule = $rules[0];
    $this->assertInstanceOf(TimeCommitmentRule::class, $rule);
    $this->assertSame(TimeCommitmentRule::MODE_FULL_OCCURRENCE, (string) $rule->get('mode')->value);
    $this->assertSame(
      $firstStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s'),
      (string) $rule->get('effective_from')->value,
    );
    $seriesStorage->resetCache([$seriesId]);
    $this->assertSame($revision, (string) $seriesStorage->load($seriesId)?->getRevisionId());
  }

  private function setDate(string $value): void {
    $encoded = json_encode($value, JSON_THROW_ON_ERROR);
    $this->getSession()->executeScript(
      'const field = document.querySelector("[data-ps-time-commitment-date]");'
      . 'field.value = ' . $encoded . ';'
      . 'field.dispatchEvent(new Event("input", { bubbles: true }));'
      . 'field.dispatchEvent(new Event("change", { bubbles: true }));',
    );
  }

  private function setMode(string $mode): void {
    $encoded = json_encode($mode, JSON_THROW_ON_ERROR);
    $this->getSession()->executeScript(
      'document.querySelectorAll("form[data-ps-time-commitment-editor] input[name=mode]")'
      . '.forEach((radio) => { if (radio.value === ' . $encoded . ') { radio.click(); } });',
    );
  }

  private function assertSaveState(bool $enabled): void {
    $actual = (bool) $this->getSession()->evaluateScript(
      '!document.querySelector("[data-ps-time-commitment-save]").disabled',
    );
    $this->assertSame($enabled, $actual);
    $hidden = (bool) $this->getSession()->evaluateScript(
      'document.querySelector("[data-ps-time-commitment-clean]").hidden',
    );
    $this->assertSame($enabled, $hidden);
    $this->assertSame('status', $this->getSession()->evaluateScript(
      'document.querySelector("[data-ps-time-commitment-clean]").getAttribute("role")',
    ));
  }

  private function assertNoMutation(
    $storage,
    $seriesStorage,
    int $seriesId,
    string $revision,
  ): void {
    $this->assertCount(0, $storage->loadMultiple());
    $seriesStorage->resetCache([$seriesId]);
    $this->assertSame($revision, (string) $seriesStorage->load($seriesId)?->getRevisionId());
  }

}
