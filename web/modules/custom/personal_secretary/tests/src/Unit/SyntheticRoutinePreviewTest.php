<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Unit;

use Drupal\personal_secretary\Service\SyntheticRoutinePreviewService;
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
    self::assertSame(SyntheticRoutinePreviewService::NOT_APPLICABLE, $byDate['2027-03-29']['afternoon']);
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

  public function testSingleDayOverrideAndDeterministicDerivedIdentity(): void {
    [$proposal, $context] = self::example();
    $service = new SyntheticRoutinePreviewService();
    $before = $service->preview($proposal, $proposal->householdKey, $context);
    $after = $service->preview($proposal, $proposal->householdKey, $context, [
      '2027-03-24' => ['school_day' => FALSE],
      '2027-03-28' => ['exchange' => ['window' => ['10:40', '12:20'], 'exact' => '11:15']],
    ]);
    $this->assertSame($before, $service->preview($proposal, $proposal->householdKey, $context));
    foreach ($before as $i => $day) {
      if (!in_array($day['date'], ['2027-03-24', '2027-03-28'], TRUE)) {
        self::assertSame($day, $after[$i]);
      }
      $keys = array_column($day['actions'], 'key');
      self::assertCount(count(array_unique($keys)), $keys);
    }
    self::assertSame(SyntheticRoutinePreviewService::NOT_APPLICABLE, $after[2]['morning']);
    self::assertSame('EXACT_TIME_CONFIRMED', $after[6]['exchange']);
    self::assertSame('EXACT_TIME_PENDING', $after[7]['exchange']);
    self::assertSame('EXACT_TIME_PENDING', $before[6]['exchange']);
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
