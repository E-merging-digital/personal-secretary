<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Unit;

use DateTimeImmutable;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Value\ActivityCaptureExtraction;
use Drupal\personal_secretary\Value\ActivityCaptureInput;
use Drupal\personal_secretary\Value\ActivityCaptureProposal;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Proves the provider-neutral activity-capture value contracts.
 */
#[Group('personal_secretary')]
final class ActivityCaptureValueTest extends TestCase {

  public function testInputNormalizesSyntheticContext(): void {
    $input = new ActivityCaptureInput(
      "  Déposer le colis.  ",
      new DateTimeImmutable('2026-09-16T14:30:00+02:00'),
      'Europe/Brussels',
    );

    self::assertSame('Déposer le colis.', $input->text);
    self::assertSame('2026-09-16T12:30:00Z', $input->contextInstantIso8601());
    self::assertSame('Europe/Brussels', $input->sourceTimezone);
  }

  #[DataProvider('invalidInputProvider')]
  public function testInputRejectsInvalidTextOrTimezone(string $text, string $timezone): void {
    $this->expectException(InvalidArgumentException::class);
    new ActivityCaptureInput($text, new DateTimeImmutable('2026-09-16T12:00:00Z'), $timezone);
  }

  public static function invalidInputProvider(): array {
    return [
      'empty text' => ['   ', 'Europe/Brussels'],
      'invalid timezone' => ['Synthetic activity', 'Not/A_Timezone'],
    ];
  }

  public function testNarrowExtractionRoundTripContainsNoInternalIdentity(): void {
    $data = self::validExtraction();
    $extraction = ActivityCaptureExtraction::fromProviderArray($data);

    self::assertCount(16, ActivityCaptureExtraction::structuredJsonSchema()['properties']);
    self::assertCount(16, ActivityCaptureExtraction::structuredJsonSchema()['required']);
    self::assertSame($data, $extraction->toArray());
    self::assertFalse($extraction->containsInternalIdentity());
    self::assertArrayNotHasKey('source_timezone', $data);
    self::assertArrayNotHasKey('intent', $data);
    self::assertArrayNotHasKey('unsupported', $data);
    self::assertArrayNotHasKey('ambiguous', $data);
  }

  public function testCurrentProviderHydrationRequiresUnclassifiedPersonMentions(): void {
    $data = self::validExtraction();
    self::assertSame([], ActivityCaptureExtraction::fromProviderArray($data)->unclassifiedPersonMentions);

    unset($data['unclassified_person_mentions']);
    $this->expectException(InvalidArgumentException::class);
    ActivityCaptureExtraction::fromProviderArray($data);
  }

  public function testHistoricalCompatibilityDefaultsOnlyUnclassifiedPersonMentions(): void {
    $data = self::validExtraction();
    unset($data['unclassified_person_mentions']);

    $extraction = ActivityCaptureExtraction::fromArray($data);
    self::assertSame([], $extraction->unclassifiedPersonMentions);
    self::assertSame([], $extraction->toArray()['unclassified_person_mentions']);
  }

  public function testCrossRoleConsistencyRejectsUnclassifiedContradictionsAndAllowsDualRole(): void {
    $concernedConflict = self::validExtraction();
    $concernedConflict['concerned_person_mentions'] = [' Personne Bêta '];
    $concernedConflict['unclassified_person_mentions'] = ['personne bêta'];
    try {
      ActivityCaptureExtraction::fromProviderArray($concernedConflict);
      self::fail('Concerned and unclassified copies of the same Person mention must be rejected.');
    }
    catch (InvalidArgumentException) {
    }

    $responsibilityConflict = self::validExtraction();
    $responsibilityConflict['responsibility_candidate'] = 'Personne Bêta';
    $responsibilityConflict['unclassified_person_mentions'] = ['  personne bêta  '];
    try {
      ActivityCaptureExtraction::fromProviderArray($responsibilityConflict);
      self::fail('Responsible and unclassified copies of the same Person mention must be rejected.');
    }
    catch (InvalidArgumentException) {
    }

    $dualRole = self::validExtraction();
    $dualRole['concerned_person_mentions'] = ['Personne Bêta'];
    $dualRole['responsibility_candidate'] = 'personne bêta';
    $extraction = ActivityCaptureExtraction::fromProviderArray($dualRole);
    self::assertSame(['Personne Bêta'], $extraction->concernedPersonMentions);
    self::assertSame('personne bêta', $extraction->responsibilityCandidate);
  }

  #[DataProvider('invalidExtractionProvider')]
  public function testExtractionRejectsInvalidOrIdentityBearingShape(callable $mutation): void {
    $data = $mutation(self::validExtraction());
    $this->expectException(InvalidArgumentException::class);
    ActivityCaptureExtraction::fromArray($data);
  }

  public static function invalidExtractionProvider(): array {
    return [
      'missing field' => [static function (array $data): array {
        unset($data['label_text']);
        return $data;
      }],
      'unknown internal field' => [static function (array $data): array {
        $data['person_id'] = 42;
        return $data;
      }],
      'uuid in text' => [static function (array $data): array {
        $data['location_text'] = '123e4567-e89b-42d3-a456-426614174000';
        return $data;
      }],
      'uuid in unclassified Person mention' => [static function (array $data): array {
        $data['unclassified_person_mentions'] = ['123e4567-e89b-42d3-a456-426614174000'];
        return $data;
      }],
      'unbounded offset' => [static function (array $data): array {
        $data['relative_day_offset'] = 500;
        return $data;
      }],
    ];
  }

  public function testReviewProposalRequiresCompleteDeterministicEndSemantics(): void {
    $ready = new ActivityCaptureProposal(
      householdId: 7,
      intent: ActivityCaptureProposal::INTENT_ONE_OFF,
      label: 'Synthetic activity',
      location: NULL,
      timeMode: ActivitySeries::TIME_MODE_TIMED,
      absoluteDate: '2026-09-30',
      localStartTime: '08:00',
      localEndTime: '09:00',
      sourceTimezone: 'Europe/Brussels',
      weekday: NULL,
      concernedPersonIds: [11],
      responsiblePersonId: NULL,
      clarifications: [],
    );
    self::assertTrue($ready->readyForConfirmation());

    $missingEnd = new ActivityCaptureProposal(
      householdId: 7,
      intent: ActivityCaptureProposal::INTENT_ONE_OFF,
      label: 'Synthetic activity',
      location: NULL,
      timeMode: ActivitySeries::TIME_MODE_TIMED,
      absoluteDate: '2026-09-30',
      localStartTime: '08:00',
      localEndTime: NULL,
      sourceTimezone: 'Europe/Brussels',
      weekday: NULL,
      concernedPersonIds: [],
      responsiblePersonId: NULL,
      clarifications: ['end_time_required'],
    );
    self::assertFalse($missingEnd->readyForConfirmation());
  }

  private static function validExtraction(): array {
    return [
      'label_text' => 'Déposer un colis',
      'location_text' => NULL,
      'concerned_person_mentions' => ['Personne Alpha'],
      'unclassified_person_mentions' => [],
      'concerned_person_alternative' => FALSE,
      'responsibility_candidate' => 'SELF',
      'date_expression' => 'demain',
      'date_day' => NULL,
      'date_month' => NULL,
      'date_year' => NULL,
      'relative_day_offset' => 1,
      'start_time_expression' => '08h',
      'end_time_expression' => NULL,
      'recurrence_expression' => NULL,
      'explicit_all_day_signal' => FALSE,
      'explicit_timed_signal' => TRUE,
    ];
  }

}
