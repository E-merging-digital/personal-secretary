<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Database\Connection;
use Drupal\personal_secretary\Entity\ActivitySeries;
use RuntimeException;
use Throwable;

/**
 * Orchestrates atomic creation of an activity in an existing context.
 */
final class AddActivityService {

  private const ONE_OFF_RRULE = 'FREQ=DAILY;COUNT=1';

  private const WEEKLY_RRULE = 'FREQ=WEEKLY;INTERVAL=1';

  public function __construct(
    private readonly Connection $database,
    private readonly DomainMutationService $domainMutations,
    private readonly ResponsibilityMutationService $responsibilityMutations,
    private readonly PreparationRequirementMutationService $preparationMutations,
    private readonly EffectiveOccurrenceProjectionService $effectiveOccurrences,
  ) {}

  public function addWeeklyActivity(
    int $householdId,
    int $responsiblePersonId,
    string $activityLabel,
    DateTimeImmutable $localStart,
    DateTimeImmutable $localEnd,
    string $preparationInstruction = '',
    int $preparationLeadMinutes = 0,
    string $location = '',
    array $concernedPersonIds = [],
  ): ActivitySeries {
    $transaction = $this->database->startTransaction();

    try {
      $series = $this->domainMutations->createActivitySeries(
        $activityLabel,
        $householdId,
        $localStart,
        $localEnd,
        self::WEEKLY_RRULE,
        $location,
        $concernedPersonIds,
      );
      $this->responsibilityMutations->createResponsibilityRule(
        $series,
        $responsiblePersonId,
        $localStart,
        $localEnd,
        self::WEEKLY_RRULE,
      );

      $preparationInstruction = trim($preparationInstruction);
      if ($preparationInstruction !== '') {
        $this->preparationMutations->createPreparationRequirement(
          $series,
          $preparationInstruction,
          $preparationLeadMinutes * 60,
          $localStart,
        );
      }

      $transaction->commitOrRelease();
      return $series;
    }
    catch (Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  public function addOneOffActivity(
    int $householdId,
    ?int $responsiblePersonId,
    string $activityLabel,
    DateTimeImmutable $localStart,
    DateTimeImmutable $localEnd,
    string $preparationInstruction = '',
    int $preparationLeadMinutes = 0,
    string $location = '',
    array $concernedPersonIds = [],
  ): ActivitySeries {
    $transaction = $this->database->startTransaction();

    try {
      $series = $this->domainMutations->createActivitySeries(
        $activityLabel,
        $householdId,
        $localStart,
        $localEnd,
        self::ONE_OFF_RRULE,
        $location,
        $concernedPersonIds,
      );

      if ($responsiblePersonId !== NULL) {
        $utc = new DateTimeZone('UTC');
        $occurrences = $this->effectiveOccurrences->project(
          $series,
          $localStart->setTimezone($utc)->modify('-1 second'),
          $localEnd->setTimezone($utc)->modify('+1 second'),
          2,
        );
        if (count($occurrences) !== 1) {
          throw new RuntimeException('One-off ActivitySeries must project exactly one EffectiveOccurrence.');
        }
        $this->responsibilityMutations->createAssignOverride(
          $series,
          $occurrences[0],
          $responsiblePersonId,
        );
      }

      $preparationInstruction = trim($preparationInstruction);
      if ($preparationInstruction !== '') {
        $this->preparationMutations->createPreparationRequirement(
          $series,
          $preparationInstruction,
          $preparationLeadMinutes * 60,
          $localStart,
        );
      }

      $transaction->commitOrRelease();
      return $series;
    }
    catch (Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

}
