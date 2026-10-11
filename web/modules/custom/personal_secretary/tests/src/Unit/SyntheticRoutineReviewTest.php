<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Unit;

use Drupal\personal_secretary\Service\SyntheticRoutinePreviewService;
use Drupal\personal_secretary\Service\SyntheticRoutineReviewService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Proves only an offline, fictional typed conversation and preview review.
 */
#[Group('personal_secretary')]
final class SyntheticRoutineReviewTest extends TestCase {

  private static function example(bool $reviewable = TRUE): array {
    $factory = require dirname(__DIR__, 2) . '/fixtures/synthetic-routine-context.php';
    [$proposal, $days] = $factory();
    if ($reviewable) {
      // Explicitly resolve the fabricated uncertainties, never assume them.
      $days['2027-03-26']['schools']['FICTIVE_CENTRE_BETA'] = 'OPEN';
      $days['2027-03-26']['external']['freshness'] = 'CURRENT';
      foreach (['2027-03-28', '2027-03-29'] as $date) {
        $days[$date]['exchange']['exact'] = '11:15';
      }
    }
    return [$proposal, $days];
  }

  /** Exact invented phrases are a test double, NOT natural-language parsing. */
  private static function fictitiousCommand(string $utterance, int $revision): array {
    return match ($utterance) {
      'Pour cette fiction, enlever les étapes du matin.' => [
        'type' => 'INTENTION', 'expected_revision' => $revision,
        'field' => 'suggestMorningStops', 'enabled' => FALSE,
      ],
      'Le mercredi fictif, le centre ferme.' => [
        'type' => 'DAY_SCHOOL', 'expected_revision' => $revision,
        'date' => '2027-03-24', 'school_day' => FALSE,
      ],
      default => ['type' => 'AMBIGUOUS', 'expected_revision' => $revision],
    };
  }

  public function testThreeTurnTypedCorrectionAndExactPreviewApproval(): void {
    [$proposal, $days] = self::example();
    $service = new SyntheticRoutineReviewService();
    // Fictional turn 1: the already-existing #241 typed proposal.
    $first = $service->start($proposal, $proposal->householdKey, $days);
    self::assertSame('READY_FOR_REVIEW', $first['status']);
    self::assertCount(14, $first['preview']);
    // Fictional turn 2: deterministic typed interpretation of exact invented text.
    $second = $service->correct($first, self::fictitiousCommand('Pour cette fiction, enlever les étapes du matin.', 1));
    self::assertSame('READY_FOR_REVIEW', $second['status']);
    self::assertSame(2, $second['revision']);
    self::assertSame(2, $second['proposal']->version);
    $before = array_column($second['before'], NULL, 'date');
    $after = array_column($second['preview'], NULL, 'date');
    self::assertSame(2, self::kindCount($before['2027-03-23'], 'TRAVEL_STOP_SUGGESTION'));
    self::assertSame(0, self::kindCount($after['2027-03-23'], 'TRAVEL_STOP_SUGGESTION'));
    self::assertSame(1, self::kindCount($after['2027-03-23'], 'PREPARATION_SUGGESTION'));
    self::assertSame('EXACT_TIME_CONFIRMED', $after['2027-03-28']['exchange']);
    $summary = $service->markdown($second);
    self::assertStringContainsString('Avant', $summary);
    self::assertStringContainsString('Après', $summary);
    self::assertStringContainsString('Conditions', $summary);
    self::assertStringContainsString('PREVIEW_ONLY / NOT_SAVED', $summary);
    self::assertStringContainsString('SYNTHETIC_ONLY', $summary);
    self::assertSame(14, substr_count($summary, '| 2027-'));
    // Fictional turn 3: explicit exact-snapshot review (NOT a saved rule).
    $third = $service->review($second, [
      'type' => 'REVIEW', 'expected_revision' => $second['revision'],
      'expected_fingerprint' => $second['fingerprint'], 'action' => 'ACCEPT_PREVIEW',
    ]);
    self::assertSame('PREVIEW_ACCEPTED_ONLY', $third['status']);
    self::assertFalse($third['saved']);
    self::assertSame($second['preview'], $third['preview']);
    self::assertSame('STALE_PROPOSAL', $service->review($third, [
      'type' => 'REVIEW', 'expected_revision' => 2,
      'expected_fingerprint' => $third['fingerprint'], 'action' => 'ACCEPT_PREVIEW',
    ])['status']);
  }

  public function testOneDayCorrectionPreservesExactlyThirteenDays(): void {
    [$proposal, $days] = self::example();
    $service = new SyntheticRoutineReviewService();
    $first = $service->start($proposal, $proposal->householdKey, $days);
    $second = $service->correct($first, self::fictitiousCommand('Le mercredi fictif, le centre ferme.', 1));
    self::assertSame('READY_FOR_REVIEW', $second['status']);
    self::assertSame(1, $second['proposal']->version);
    self::assertSame(2, $second['revision']);
    $changed = 0;
    foreach ($second['before'] as $index => $row) {
      if ($row['date'] === '2027-03-24') {
        self::assertNotSame($row, $second['preview'][$index]);
        ++$changed;
      }
      else {
        self::assertSame($row, $second['preview'][$index]);
      }
    }
    self::assertSame(1, $changed);
    self::assertSame(2, self::kindCount(array_column($first['preview'], NULL, 'date')['2027-03-24'], 'TRAVEL_STOP_SUGGESTION'));
    self::assertSame(0, self::kindCount(array_column($second['preview'], NULL, 'date')['2027-03-24'], 'TRAVEL_STOP_SUGGESTION'));
    self::assertSame($second['preview'], $service->correct($first, self::fictitiousCommand('Le mercredi fictif, le centre ferme.', 1))['preview']);
  }

  public function testOldRevisionPreviewFingerprintAndContextAreRejected(): void {
    [$proposal, $days] = self::example();
    $service = new SyntheticRoutineReviewService();
    $initial = $service->start($proposal, $proposal->householdKey, $days);
    $edited = $service->correct($initial, self::fictitiousCommand('Pour cette fiction, enlever les étapes du matin.', 1));
    $stale = $service->review($edited, [
      'type' => 'REVIEW', 'expected_revision' => 1,
      'expected_fingerprint' => $initial['fingerprint'], 'action' => 'ACCEPT_PREVIEW',
    ]);
    self::assertSame('STALE_PROPOSAL', $stale['status']);
    self::assertSame($edited['preview'], $stale['preview']);
    $staleToken = $service->review($edited, [
      'type' => 'REVIEW', 'expected_revision' => 2,
      'expected_fingerprint' => str_repeat('0', 64), 'action' => 'ACCEPT_PREVIEW',
    ]);
    self::assertSame('STALE_PROPOSAL', $staleToken['status']);
    $changedContext = $days;
    $changedContext['2027-03-23']['school_day'] = FALSE;
    $changed = $service->review($edited, [
      'type' => 'REVIEW', 'expected_revision' => 2,
      'expected_fingerprint' => $edited['fingerprint'], 'action' => 'ACCEPT_PREVIEW',
    ], $changedContext);
    self::assertSame('STALE_PROPOSAL', $changed['status']);
    self::assertFalse($changed['saved']);
    self::assertSame('STALE_PROPOSAL', $service->correct($edited, [
      'type' => 'DAY_SCHOOL', 'expected_revision' => 1,
      'date' => '2027-03-24', 'school_day' => FALSE,
    ])['status']);
  }

  public function testAmbiguousUnsupportedImplicitAndDeclinedReviewHaveNoEffect(): void {
    [$proposal, $days] = self::example();
    $service = new SyntheticRoutineReviewService();
    $first = $service->start($proposal, $proposal->householdKey, $days);
    $ambiguous = $service->correct($first, self::fictitiousCommand('Peut-être seulement parfois ?', 1));
    self::assertSame('NEEDS_CLARIFICATION', $ambiguous['status']);
    self::assertSame($first['proposal'], $ambiguous['proposal']);
    self::assertSame($first['preview'], $ambiguous['preview']);
    self::assertSame($first['revision'], $ambiguous['revision']);
    self::assertSame('NEEDS_CLARIFICATION', $service->review($ambiguous, [
      'type' => 'REVIEW', 'expected_revision' => 1,
      'expected_fingerprint' => $first['fingerprint'], 'action' => 'ACCEPT_PREVIEW',
    ])['status']);
    self::assertSame('NEEDS_CLARIFICATION', $service->review($first, [
      'type' => 'TEXT', 'value' => 'oui',
    ])['status']);
    $declined = $service->review($first, [
      'type' => 'REVIEW', 'expected_revision' => 1,
      'expected_fingerprint' => $first['fingerprint'], 'action' => 'DECLINE',
    ]);
    self::assertSame('DECLINED', $declined['status']);
    self::assertFalse($declined['saved']);
    self::assertSame($first['preview'], $declined['preview']);
    self::assertSame('STALE_PROPOSAL', $service->review($declined, [
      'type' => 'REVIEW', 'expected_revision' => 1,
      'expected_fingerprint' => $first['fingerprint'], 'action' => 'ACCEPT_PREVIEW',
    ])['status']);
  }

  public function testUnknownCalendarAndPendingExchangeBlockPreviewAcceptance(): void {
    [$proposal, $days] = self::example(FALSE);
    $service = new SyntheticRoutineReviewService();
    $state = $service->start($proposal, $proposal->householdKey, $days);
    self::assertSame('NEEDS_CLARIFICATION', $state['status']);
    $review = $service->review($state, [
      'type' => 'REVIEW', 'expected_revision' => 1,
      'expected_fingerprint' => $state['fingerprint'], 'action' => 'ACCEPT_PREVIEW',
    ]);
    self::assertSame('NEEDS_CLARIFICATION', $review['status']);
    self::assertFalse($review['saved']);
    $day = array_column($review['preview'], NULL, 'date');
    self::assertSame('EXACT_TIME_PENDING', $day['2027-03-28']['exchange']);
    self::assertContains('SOURCE_STALE_AVAILABILITY_UNKNOWN', $day['2027-03-26']['conflicts']);
    self::assertContains('POTENTIAL_BUSY_CONFLICT', $day['2027-03-23']['conflicts']);
    self::assertContains('INFORMATION_ONLY', $day['2027-03-24']['conflicts']);
    self::assertSame(SyntheticRoutinePreviewService::APPLICABLE, $day['2027-03-29']['morning']);
    self::assertSame(SyntheticRoutinePreviewService::NOT_APPLICABLE, $day['2027-03-29']['afternoon']);
  }

  public function testSyntheticHouseholdBoundaryAndUnsupportedFieldsFailClosed(): void {
    [$proposal, $days] = self::example();
    $service = new SyntheticRoutineReviewService();
    try {
      $service->start($proposal, 'SYNTHETIC_OTHER_HOUSEHOLD', $days);
      self::fail('Cross household must be rejected by existing evaluator.');
    }
    catch (\InvalidArgumentException) {
    }
    $state = $service->start($proposal, $proposal->householdKey, $days);
    $unsupported = $service->correct($state, [
      'type' => 'CREATE_TASK', 'expected_revision' => 1,
    ]);
    self::assertSame('NEEDS_CLARIFICATION', $unsupported['status']);
    self::assertSame($state['preview'], $unsupported['preview']);
    $this->expectException(\InvalidArgumentException::class);
    $service->correct($state, [
      'type' => 'DAY_SCHOOL', 'expected_revision' => 1,
      'date' => '2027-03-24', 'school_day' => FALSE,
      'real_person' => 'FORBIDDEN',
    ]);
  }

  private static function kindCount(array $day, string $kind): int {
    return count(array_filter($day['actions'], static fn(array $a): bool => $a['kind'] === $kind));
  }

}
