<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Unit;

use DateTimeImmutable;
use Drupal\personal_secretary\Value\ActivityCaptureInput;
use Drupal\personal_secretary\Value\ActivityCaptureProposal;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Proves the provider-neutral activity-capture value contracts.
 *
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

  public function testValidProposalRoundTripHasNoInternalIdentity(): void {
    $data = self::validProposal();
    $proposal = ActivityCaptureProposal::fromArray($data);

    self::assertSame($data, $proposal->toArray());
    self::assertFalse($proposal->containsInternalIdentity());
    self::assertSame($data['source_timezone'], $proposal->sourceTimezone);
  }

  #[DataProvider('invalidProposalProvider')]
  public function testProposalRejectsInvalidShape(callable $mutation): void {
    $data = self::validProposal();
    $data = $mutation($data);
    $this->expectException(InvalidArgumentException::class);
    ActivityCaptureProposal::fromArray($data);
  }

  public static function invalidProposalProvider(): array {
    return [
      'missing field' => [static function (array $data): array {
        unset($data['label']);
        return $data;
      }],
      'unknown field' => [static function (array $data): array {
        $data['person_id'] = 42;
        return $data;
      }],
      'invalid intent' => [static function (array $data): array {
        $data['intent'] = 'MONTHLY';
        return $data;
      }],
      'invalid time mode' => [static function (array $data): array {
        $data['time_mode'] = 'MORNING';
        return $data;
      }],
      'invalid absolute date' => [static function (array $data): array {
        $data['absolute_date'] = '2026-02-31';
        return $data;
      }],
      'invalid local time' => [static function (array $data): array {
        $data['local_time'] = '25:61';
        return $data;
      }],
      'invalid timezone' => [static function (array $data): array {
        $data['source_timezone'] = 'Synthetic/Nowhere';
        return $data;
      }],
      'text responsibility missing text' => [static function (array $data): array {
        $data['responsibility'] = 'TEXT';
        $data['responsibility_text'] = NULL;
        return $data;
      }],
    ];
  }

  public function testInternalIdentityDetectorCatchesProhibitedKeyAndUuidForms(): void {
    $keyData = self::validProposal();
    $keyData['label'] = 'person_id';
    self::assertTrue(ActivityCaptureProposal::fromArray($keyData)->containsInternalIdentity());

    $uuidData = self::validProposal();
    $uuidData['location'] = '123e4567-e89b-42d3-a456-426614174000';
    self::assertTrue(ActivityCaptureProposal::fromArray($uuidData)->containsInternalIdentity());
  }

  private static function validProposal(): array {
    return [
      'intent' => 'ONE_OFF',
      'label' => 'Synthetic activity',
      'location' => NULL,
      'time_mode' => 'TIMED',
      'relative_date_expression' => 'demain',
      'absolute_date' => NULL,
      'weekday' => NULL,
      'local_time' => '08:00',
      'source_timezone' => 'Europe/Brussels',
      'concerned_person_candidates' => ['Personne Alpha'],
      'responsibility' => 'SELF',
      'responsibility_text' => NULL,
      'preparation_instruction' => NULL,
      'preparation_lead' => NULL,
      'ambiguous' => FALSE,
      'unsupported' => FALSE,
    ];
  }

}
