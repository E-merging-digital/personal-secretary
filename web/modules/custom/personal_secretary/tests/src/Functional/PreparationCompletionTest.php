<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\PersonalTask;
use Drupal\personal_secretary\Entity\PreparationCompletion;
use Drupal\personal_secretary\Entity\ResponsibilityOverride;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use RuntimeException;

/**
 * Proves governed current-user preparation completion behavior.
 *
 * @group personal_secretary
 */
final class PreparationCompletionTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];
  protected $defaultTheme = 'olivero';

  public function testPreparationCompletionLifecycleAndIsolation(): void {
    $mine = '/personal-secretary/preparations/mine';
    $this->drupalGet($mine);
    $this->assertSession()->statusCodeEquals(403);

    $this->installUserReferenceField(CurrentPersonResolver::FIELD_NAME, 'personal_secretary_person', 1);
    $this->installUserReferenceField(HouseholdAuthorizationService::FIELD_NAME, 'personal_secretary_household', FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    $this->config('system.date')->set('timezone.default', 'Europe/Brussels')->save();

    $domain = $this->container->get('personal_secretary.domain_mutation');
    $responsibility = $this->container->get('personal_secretary.responsibility_mutation');
    $requirements = $this->container->get('personal_secretary.preparation_requirement_mutation');
    $completion = $this->container->get('personal_secretary.preparation_completion');
    $preparations = $this->container->get('personal_secretary.current_user_preparation');
    $today = $this->container->get('personal_secretary.today');
    $timeline = $this->container->get('personal_secretary.revision_timeline');
    $effective = $this->container->get('personal_secretary.effective_occurrence_projection');
    $exceptions = $this->container->get('personal_secretary.activity_exception');
    $switcher = $this->container->get('account_switcher');
    $manager = $this->container->get('entity_type.manager');
    $now = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))->setTimezone(new DateTimeZone('UTC'));

    $a = $domain->createPerson('Completion A');
    $b = $domain->createPerson('Completion B');
    $h1 = $domain->createHousehold('Completion H1', [(int) $a->id(), (int) $b->id()]);
    $h2 = $domain->createHousehold('Completion H2', [(int) $a->id()]);
    $u1 = $this->productUser((int) $a->id(), [(int) $h1->id()]);
    $u2 = $this->productUser((int) $a->id(), [(int) $h1->id()]);
    $ub = $this->productUser((int) $b->id(), [(int) $h1->id()]);
    $noGrant = $this->productUser((int) $a->id(), []);
    $unlinked = $this->productUser(NULL, [(int) $h1->id()]);
    $stale = $this->productUser(999999, [(int) $h1->id()]);

    $series = $this->series('Completion main activity', (int) $h1->id(), (int) $a->id(), $now->modify('+2 hours'));
    $req = $requirements->createPreparationRequirement($series, 'Completion main preparation', 3 * 3600, $now->modify('-2 days'));
    $h2Series = $this->series('Completion H2 activity', (int) $h2->id(), (int) $a->id(), $now->modify('+3 hours'));
    $h2Req = $requirements->createPreparationRequirement($h2Series, 'Completion H2 preparation', 4 * 3600, $now->modify('-2 days'));

    $before = $this->readMine($u1, $now);
    $item = $this->item($before, 'Completion main preparation');
    $this->assertFalse($item['prepared']);
    $this->assertArrayNotHasKey('Completion H2 preparation', $this->index($before));

    // Store real unauthorized H2 completion truth; H1 reads must still not expose it.
    $h2Base = $timeline->projectBaseWindow($h2Series, $now, $now->modify('+1 day'))[0];
    $this->syntheticCompletion($h2Series, (int) $h2Base->seriesRevisionId, $h2Base->originalOccurrenceKey, (int) $h2Req->id(), (int) $a->id(), (int) $u1->id());
    $this->assertArrayNotHasKey('Completion H2 preparation', $this->index($this->readMine($u1, $now)));

    // Mark from Today through the real ConfirmForm POST path; the item disappears immediately.
    $taskCount = count($manager->getStorage(PersonalTask::ENTITY_TYPE_ID)->loadMultiple());
    $this->drupalLogin($u1);
    $markToday = $this->actionUrl('personal_secretary.mark_preparation_prepared', $item, 'today');
    $this->drupalGet($markToday);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame(1, $this->completionCount()); // Only the pre-seeded unauthorized H2 row exists before POST.
    $this->submitForm([], 'Mark prepared');
    $this->assertSession()->addressEquals('/personal-secretary/today');
    $this->assertSession()->pageTextNotContains('Completion main preparation');
    $this->drupalLogout();
    $switcher->switchTo($u1);
    try {
      $this->assertFalse($completion->markPrepared((int) $series->id(), $item['_completion_original_occurrence_key'], (int) $req->id()));
    }
    finally {
      $switcher->switchBack();
    }
    $this->assertSame($taskCount, count($manager->getStorage(PersonalTask::ENTITY_TYPE_ID)->loadMultiple()));
    $rows = $this->exactRows($item);
    $this->assertCount(1, $rows);
    $row = reset($rows);
    $this->assertInstanceOf(PreparationCompletion::class, $row);
    $this->assertSame((int) $series->id(), (int) $row->get('series')->target_id);
    $this->assertSame((int) $req->id(), (int) $row->get('preparation_requirement')->target_id);
    $this->assertSame((int) $a->id(), (int) $row->get('responsible_person')->target_id);
    $this->assertSame((int) $u1->id(), (int) $row->get('prepared_by_user')->target_id);
    $this->assertGreaterThan(0, (int) $row->get('prepared_at')->value);

    $this->assertTrue($this->item($this->readMine($u2, $now), 'Completion main preparation')['prepared']);
    $this->drupalLogin($noGrant);
    $this->drupalGet($mine);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogout();
    foreach ([$unlinked, $stale] as $invalid) {
      $this->drupalLogin($invalid);
      $count = $this->completionCount();
      $this->drupalGet($mine);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains('Link your account to a valid Household member');
      $this->assertSession()->pageTextNotContains('Completion main preparation');
      $this->assertSame($count, $this->completionCount());
      $this->drupalLogout();
    }

    // Today is action-oriented: prepared candidate is filtered; real undo POST makes it eligible again.
    $switcher->switchTo($u1);
    try {
      $this->assertArrayNotHasKey('Completion main preparation', $this->index($today->today()['preparations']));
    }
    finally {
      $switcher->switchBack();
    }

    // GET product and confirm-form routes never mutate completion state; only POST removes it.
    $this->drupalLogin($u1);
    $count = $this->completionCount();
    $this->drupalGet($mine);
    $this->assertSession()->pageTextContains('To prepare');
    $this->assertSession()->pageTextContains('Prepared');
    $this->assertSession()->linkExists('Mark not prepared');
    $this->assertSame($count, $this->completionCount());
    $form = $this->actionUrl('personal_secretary.mark_preparation_not_prepared', $item, 'mine');
    $this->drupalGet($form);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame($count, $this->completionCount());
    $this->submitForm([], 'Mark not prepared');
    $this->assertSession()->addressEquals($mine);
    $this->assertSame($count - 1, $this->completionCount());
    $this->drupalGet('/personal-secretary/today');
    $this->assertSession()->pageTextContains('Completion main preparation');
    $this->drupalLogout();

    $switcher->switchTo($u1);
    try {
      $this->assertFalse($completion->markNotPrepared((int) $series->id(), $item['_completion_original_occurrence_key'], (int) $req->id()));
      $this->assertTrue($completion->markPrepared((int) $series->id(), $item['_completion_original_occurrence_key'], (int) $req->id()));
    }
    finally {
      $switcher->switchBack();
    }

    // Rescheduling preserves semantic identity and current display is recalculated.
    $base = $timeline->projectBaseWindow($series, $now, $now->modify('+1 day'))[0];
    $exceptions->createReschedule($series, $base, $now->modify('+2 hours 30 minutes'), $now->modify('+3 hours 30 minutes'), 'UTC');
    $rescheduled = $this->item($this->readMine($u1, $now), 'Completion main preparation');
    $this->assertTrue($rescheduled['prepared']);
    $this->assertSame($item['_completion_original_occurrence_key'], $rescheduled['_completion_original_occurrence_key']);
    $this->assertNotSame($item['activity_start_iso'], $rescheduled['activity_start_iso']);

    // A -> B gives B a distinct unprepared state; B -> A restores A's sparse completion.
    $occurrence = $this->findOccurrence($effective->project($series, $now, $now->modify('+1 day')), $item['_completion_original_occurrence_key']);
    $away = $responsibility->createAssignOverride($series, $occurrence, (int) $b->id());
    $this->assertArrayNotHasKey('Completion main preparation', $this->index($this->readMine($u1, $now)));
    $this->assertFalse($this->item($this->readMine($ub, $now), 'Completion main preparation')['prepared']);
    $responsibility->supersedeOverride($away, $series, $occurrence, ResponsibilityOverride::ACTION_ASSIGN_PERSON, (int) $a->id());
    $this->assertTrue($this->item($this->readMine($u1, $now), 'Completion main preparation')['prepared']);

    // Requirement replacement is a new unprepared identity.
    $life = $this->series('Completion lifecycle', (int) $h1->id(), (int) $a->id(), $now->modify('+5 hours'));
    $old = $requirements->createPreparationRequirement($life, 'Completion old requirement', 6 * 3600, $now->modify('-2 days'));
    $oldItem = $this->item($this->readMine($u1, $now), 'Completion old requirement');
    $switcher->switchTo($u1);
    try {
      $completion->markPrepared((int) $life->id(), $oldItem['_completion_original_occurrence_key'], (int) $old->id());
    }
    finally {
      $switcher->switchBack();
    }
    $cut = $now->modify('+4 hours');
    $requirements->retirePreparationRequirement($old, $cut);
    $requirements->createPreparationRequirement($life, 'Completion replacement requirement', 2 * 3600, $cut);
    $lifeItems = $this->index($this->readMine($u1, $now));
    $this->assertArrayNotHasKey('Completion old requirement', $lifeItems);
    $this->assertFalse($lifeItems['Completion replacement requirement']['prepared']);

    // Cancellation and past start remove derived preparation without completion cleanup/resurrection.
    $cancel = $this->series('Completion cancel', (int) $h1->id(), (int) $a->id(), $now->modify('+6 hours'));
    $cancelReq = $requirements->createPreparationRequirement($cancel, 'Completion cancel preparation', 7 * 3600, $now->modify('-2 days'));
    $cancelItem = $this->item($this->readMine($u1, $now), 'Completion cancel preparation');
    $this->syntheticCompletion($cancel, (int) $cancelItem['_completion_target_revision_id'], $cancelItem['_completion_original_occurrence_key'], (int) $cancelReq->id(), (int) $a->id(), (int) $u1->id());
    $beforeCancelCount = $this->completionCount();
    $exceptions->createCancel($cancel, $timeline->projectBaseWindow($cancel, $now, $now->modify('+1 day'))[0]);
    $this->assertArrayNotHasKey('Completion cancel preparation', $this->index($this->readMine($u1, $now)));
    $this->assertSame($beforeCancelCount, $this->completionCount());

    $past = $this->series('Completion past', (int) $h1->id(), (int) $a->id(), $now->modify('-2 hours'));
    $pastReq = $requirements->createPreparationRequirement($past, 'Completion past preparation', 3600, $now->modify('-2 days'));
    $pastBase = $timeline->projectBaseWindow($past, $now->modify('-1 day'), $now)[0];
    $this->syntheticCompletion($past, (int) $pastBase->seriesRevisionId, $pastBase->originalOccurrenceKey, (int) $pastReq->id(), (int) $a->id(), (int) $u1->id());
    $this->assertArrayNotHasKey('Completion past preparation', $this->index($this->readMine($u1, $now)));

    // Exact duplicate rows are corrupt state and fail closed.
    $current = $this->item($this->readMine($u1, $now), 'Completion main preparation');
    $this->syntheticCompletion($series, (int) $current['_completion_target_revision_id'], $current['_completion_original_occurrence_key'], (int) $req->id(), (int) $a->id(), (int) $u1->id());
    $switcher->switchTo($u1);
    try {
      $this->expectException(RuntimeException::class);
      $preparations->mine($now);
    }
    finally {
      $switcher->switchBack();
    }
  }

  private function series(string $label, int $household, int $person, DateTimeImmutable $start): ActivitySeries {
    $end = $start->modify('+1 hour');
    $series = $this->container->get('personal_secretary.domain_mutation')->createActivitySeries($label, $household, $start, $end, 'FREQ=DAILY;COUNT=1');
    $this->container->get('personal_secretary.responsibility_mutation')->createResponsibilityRule($series, $person, $start, $end, 'FREQ=DAILY;COUNT=1');
    return $series;
  }

  private function productUser(?int $person, array $households): UserInterface {
    $user = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $user);
    if ($person !== NULL) {
      $user->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => $person]);
    }
    $user->set(HouseholdAuthorizationService::FIELD_NAME, array_map(static fn(int $id): array => ['target_id' => $id], $households));
    $user->set('timezone', 'Europe/Brussels');
    $user->save();
    return $user;
  }

  private function readMine(UserInterface $user, DateTimeImmutable $now): array {
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($user);
    try {
      return $this->container->get('personal_secretary.current_user_preparation')->mine($now)['items'];
    }
    finally {
      $switcher->switchBack();
    }
  }

  private function index(array $items): array {
    $out = [];
    foreach ($items as $item) {
      $out[(string) $item['instruction']] = $item;
    }
    return $out;
  }

  private function item(array $items, string $instruction): array {
    $indexed = $this->index($items);
    $this->assertArrayHasKey($instruction, $indexed);
    return $indexed[$instruction];
  }

  private function exactRows(array $item): array {
    return array_values($this->container->get('entity_type.manager')->getStorage(PreparationCompletion::ENTITY_TYPE_ID)->loadByProperties([
      'series' => $item['_completion_series_id'],
      'target_revision_id' => $item['_completion_target_revision_id'],
      'original_occurrence_key' => $item['_completion_original_occurrence_key'],
      'preparation_requirement' => $item['_completion_requirement_id'],
      'responsible_person' => $item['_completion_responsible_person_id'],
    ]));
  }

  private function completionCount(): int {
    return count($this->container->get('entity_type.manager')->getStorage(PreparationCompletion::ENTITY_TYPE_ID)->loadMultiple());
  }

  private function syntheticCompletion(ActivitySeries $series, int $revision, string $key, int $requirement, int $person, int $user): void {
    $row = $this->container->get('entity_type.manager')->getStorage(PreparationCompletion::ENTITY_TYPE_ID)->create([
      'series' => $series->id(), 'target_revision_id' => $revision, 'original_occurrence_key' => $key,
      'preparation_requirement' => $requirement, 'responsible_person' => $person,
      'prepared_at' => $this->container->get('datetime.time')->getCurrentTime(), 'prepared_by_user' => $user,
    ]);
    $row->save();
  }

  private function actionUrl(string $route, array $item, string $return = 'mine'): string {
    return Url::fromRoute($route, [
      'series' => $item['_completion_series_id'],
      'original_occurrence_key' => $item['_completion_original_occurrence_key'],
      'preparation_requirement' => $item['_completion_requirement_id'],
      'return_surface' => $return,
    ])->toString();
  }

  private function findOccurrence(array $occurrences, string $key): object {
    foreach ($occurrences as $occurrence) {
      if ($occurrence->originalOccurrenceKey === $key) {
        return $occurrence;
      }
    }
    throw new RuntimeException('Expected occurrence not found.');
  }

  private function installUserReferenceField(string $name, string $target, int $cardinality): void {
    FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'user', 'type' => 'entity_reference', 'settings' => ['target_type' => $target], 'cardinality' => $cardinality])->save();
    FieldConfig::create(['field_name' => $name, 'entity_type' => 'user', 'bundle' => 'user', 'label' => $name, 'settings' => ['handler' => 'default:' . $target]])->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

}
