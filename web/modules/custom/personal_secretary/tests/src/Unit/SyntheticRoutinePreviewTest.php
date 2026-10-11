<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Unit;

use Drupal\personal_secretary\Service\SyntheticRoutinePreviewService;
use Drupal\personal_secretary\Value\SyntheticRoutineProposal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Checks the read-only, entirely fictional fourteen-day demonstration.
 */
#[Group('personal_secretary')]
final class SyntheticRoutinePreviewTest extends TestCase {

  private static function example(): array {
    $factory = require dirname(__DIR__, 2) . '/fixtures/synthetic-routine-context.php';
    return $factory();
  }

  public function testTransitionsSchoolClosuresAndTimePending(): void {
    [$proposal, $context] = self::example();
    $service = new SyntheticRoutinePreviewService();
    $items = $service->preview($proposal, $proposal->householdKey, $context);
    self::assertCount(14, $items);
    $byDate = array_column($items, NULL, 'date');
    self::assertSame(SyntheticRoutinePreviewService::NOT_APPLICABLE, $byDate['2027-03-22']['morning']);
    self::assertSame(SyntheticRoutinePreviewService::APPLICABLE, $byDate['2027-03-22']['afternoon']);
    self::assertSame(2, $this->kindCount($byDate['2027-03-23'], 'TRAVEL_STOP_SUGGESTION'));
    self::assertSame(1, $this->kindCount($byDate['2027-03-25'], 'TRAVEL_STOP_SUGGESTION'));
    self::assertSame(SyntheticRoutinePreviewService::NEEDS_CONFIRMATION, $byDate['2027-03-26']['morning']);
    self::assertSame(SyntheticRoutinePreviewService::NOT_APPLICABLE, $byDate['2027-03-28']['morning']);
    self::assertSame('EXACT_TIME_PENDING', $byDate['2027-03-28']['exchange']);
    self::assertSame(SyntheticRoutinePreviewService::APPLICABLE, $byDate['2027-03-29']['morning']);
    self::assertSame(1, $this->kindCount($byDate['2027-03-29'], 'PREPARATION_SUGGESTION'));
    self::assertSame(2, $this->kindCount($byDate['2027-03-29'], 'TRAVEL_STOP_SUGGESTION'));
    self::assertSame(SyntheticRoutinePreviewService::NOT_APPLICABLE, $byDate['2027-03-29']['afternoon']);
    self::assertSame(0, $this->kindCount($byDate['2027-03-29'], 'COLLECTION_STOP_SUGGESTION'));
    self::assertSame('EXACT_TIME_PENDING', $byDate['2027-03-29']['exchange']);
    self::assertSame(SyntheticRoutinePreviewService::NOT_APPLICABLE, $byDate['2027-04-01']['status']);
    self::assertSame('Europe/Brussels', $byDate['2027-03-28']['timezone']);
    self::assertSame('2027-04-04', $items[13]['date']);
    self::assertStringContainsString('PREVIEW_ONLY / NO_SAVED', $service->markdown($items));
    self::assertStringContainsString('FICTIVE_CENTRE_ALPHA', $service->markdown($items));
  }

  public function testConflictFreshnessAndNoFabricatedAppointment(): void {
    [$proposal, $context] = self::example();
    $items = array_column((new SyntheticRoutinePreviewService())->preview($proposal, $proposal->householdKey, $context), NULL, 'date');
    self::assertContains('POTENTIAL_BUSY_CONFLICT', $items['2027-03-23']['conflicts']);
    self::assertContains('INFORMATION_ONLY', $items['2027-03-24']['conflicts']);
    self::assertContains('SOURCE_STALE_AVAILABILITY_UNKNOWN', $items['2027-03-26']['conflicts']);
    self::assertSame(SyntheticRoutinePreviewService::NEEDS_CONFIRMATION, $items['2027-03-26']['status']);
    self::assertSame('EXACT_TIME_PENDING', $items['2027-03-28']['exchange']);
    self::assertStringNotContainsString('11:30', json_encode($items['2027-03-28'], JSON_THROW_ON_ERROR));
    self::assertSame(0, $this->kindCount($items['2027-03-26'], 'TASK'));
    self::assertFalse($items['2027-03-23']['saved']);
  }

  public function testTypedProposalChangesActionsAndSchoolCondition(): void {
    [$proposal, $context] = self::example();
    $service = new SyntheticRoutinePreviewService();
    $base = array_column($service->preview($proposal, $proposal->householdKey, $context), NULL, 'date');
    $noTravel = self::copyWith($proposal, suggestMorningStops: FALSE);
    $changed = array_column($service->preview($noTravel, $proposal->householdKey, $context), NULL, 'date');
    self::assertSame(2, $this->kindCount($base['2027-03-23'], 'TRAVEL_STOP_SUGGESTION'));
    self::assertSame(0, $this->kindCount($changed['2027-03-23'], 'TRAVEL_STOP_SUGGESTION'));
    self::assertSame(1, $this->kindCount($changed['2027-03-23'], 'PREPARATION_SUGGESTION'));
    self::assertNotSame($base['2027-03-23'], $changed['2027-03-23']);

    // A second typed guard controls a weekday-vs-holiday condition.
    $holidayRoutine = self::copyWith($proposal, requireSchoolDay: FALSE);
    $holiday = array_column($service->preview($holidayRoutine, $proposal->householdKey, $context), NULL, 'date');
    self::assertSame(SyntheticRoutinePreviewService::NOT_APPLICABLE, $base['2027-03-27']['morning']);
    self::assertSame(SyntheticRoutinePreviewService::APPLICABLE, $holiday['2027-03-27']['morning']);
    self::assertSame(1, $this->kindCount($holiday['2027-03-27'], 'PREPARATION_SUGGESTION'));
    self::assertSame(0, $this->kindCount($holiday['2027-03-27'], 'TRAVEL_STOP_SUGGESTION'));
    self::assertSame(SyntheticRoutinePreviewService::NOT_APPLICABLE, $holiday['2027-03-22']['morning']);
  }

  public function testExactlyOneDayCorrectionLeavesOtherThirteenIdentical(): void {
    [$proposal, $context] = self::example();
    $service = new SyntheticRoutinePreviewService();
    $before = $service->preview($proposal, $proposal->householdKey, $context);
    $after = $service->preview($proposal, $proposal->householdKey, $context, [
      '2027-03-24' => ['school_day' => FALSE],
    ]);
    self::assertSame($before, $service->preview($proposal, $proposal->householdKey, $context));
    $changed = 0;
    foreach ($before as $i => $day) {
      if ($day['date'] === '2027-03-24') {
        self::assertNotSame($day, $after[$i]);
        $changed++;
      }
      else {
        self::assertSame($day, $after[$i]);
      }
      $keys = array_column($day['actions'], 'key');
      self::assertCount(count(array_unique($keys)), $keys);
    }
    self::assertSame(1, $changed);
    self::assertSame(SyntheticRoutinePreviewService::NOT_APPLICABLE, $after[2]['morning']);
  }

  public function testSeparateSameDayExactTimeDoesNotModifyOtherDates(): void {
    [$proposal, $context] = self::example();
    $service = new SyntheticRoutinePreviewService();
    $before = $service->preview($proposal, $proposal->householdKey, $context);
    $after = $service->preview($proposal, $proposal->householdKey, $context, [
      '2027-03-28' => ['exchange' => ['window' => ['10:40', '12:20'], 'exact' => '11:15']],
    ]);
    foreach ($before as $i => $day) {
      if ($day['date'] !== '2027-03-28') {
        self::assertSame($day, $after[$i]);
      }
    }
    self::assertSame('EXACT_TIME_PENDING', $before[6]['exchange']);
    self::assertSame('EXACT_TIME_CONFIRMED', $after[6]['exchange']);
    self::assertSame('EXACT_TIME_PENDING', $after[7]['exchange']);
  }

  public function testVirtualSuppressionByStableIdentityDoesNotRespawn(): void {
    [$proposal, $context] = self::example();
    $service = new SyntheticRoutinePreviewService();
    $base = array_column($service->preview($proposal, $proposal->householdKey, $context), NULL, 'date');
    $target = $base['2027-03-23'];
    $prep = array_values(array_filter($target['actions'],
      static fn(array $action): bool => $action['kind'] === 'PREPARATION_SUGGESTION'))[0];
    $override = ['2027-03-23' => ['suppressed_virtual_keys' => [$prep['key']]]];
    $modified = array_column($service->preview($proposal, $proposal->householdKey, $context, $override), NULL, 'date');
    $again = array_column($service->preview($proposal, $proposal->householdKey, $context, $override), NULL, 'date');
    self::assertSame($modified, $again);
    self::assertSame(0, $this->kindCount($modified['2027-03-23'], 'PREPARATION_SUGGESTION'));
    self::assertSame(2, $this->kindCount($modified['2027-03-23'], 'TRAVEL_STOP_SUGGESTION'));
    foreach ($base as $date => $day) {
      if ($date !== '2027-03-23') {
        self::assertSame($day, $modified[$date]);
      }
    }
    self::assertSame(1, $this->kindCount($base['2027-03-23'], 'PREPARATION_SUGGESTION'));
  }

  public function testInvalidSyntheticIntentionAndSuppressionFailClosed(): void {
    [$proposal, $context] = self::example();
    try {
      self::copyWith($proposal, requirePhysicalPresence: FALSE);
      self::fail('A fictional school-care routine without presence guard must be rejected.');
    }
    catch (\InvalidArgumentException) {
    }
    $this->expectException(\InvalidArgumentException::class);
    (new SyntheticRoutinePreviewService())->preview($proposal, $proposal->householdKey, $context, [
      '2027-03-23' => ['suppressed_virtual_keys' => ['UNVALIDATED_ACTION']],
    ]);
  }

  private static function copyWith(
    SyntheticRoutineProposal $source,
    ?bool $requireSchoolDay = NULL,
    ?bool $requirePhysicalPresence = NULL,
    ?bool $suggestMorningStops = NULL,
  ): SyntheticRoutineProposal {
    return new SyntheticRoutineProposal(
      key: $source->key,
      version: $source->version,
      fictionalSentence: $source->fictionalSentence,
      householdKey: $source->householdKey,
      startDate: $source->startDate,
      requireSchoolDay: $requireSchoolDay ?? $source->requireSchoolDay,
      requirePhysicalPresence: $requirePhysicalPresence ?? $source->requirePhysicalPresence,
      suggestPreparation: $source->suggestPreparation,
      suggestMorningStops: $suggestMorningStops ?? $source->suggestMorningStops,
      suggestAfternoonCollection: $source->suggestAfternoonCollection,
      timezone: $source->timezone,
    );
  }

  public function testHouseholdDenialAndIncompleteHorizonFailClosed(): void {
    [$proposal, $context] = self::example();
    $service = new SyntheticRoutinePreviewService();
    try {
      $service->preview($proposal, 'SYNTHETIC_OTHER_HOUSEHOLD', $context);
      self::fail('Cross-Household preview must fail.');
    }
    catch (\InvalidArgumentException) {
    }
    unset($context['2027-03-24']);
    $this->expectException(\InvalidArgumentException::class);
    $service->preview($proposal, $proposal->householdKey, $context);
  }

  private function kindCount(array $day, string $kind): int {
    return count(array_filter($day['actions'], static fn(array $action): bool => $action['kind'] === $kind));
  }

}
