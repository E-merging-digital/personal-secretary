<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use Drupal\Core\Database\Connection;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Throwable;

/**
 * Orchestrates atomic creation of an activity in an existing context.
 */
final class AddActivityService {

  private const WEEKLY_RRULE = 'FREQ=WEEKLY;INTERVAL=1';

  public function __construct(
    private readonly Connection $database,
    private readonly DomainMutationService $domainMutations,
    private readonly ResponsibilityMutationService $responsibilityMutations,
    private readonly PreparationRequirementMutationService $preparationMutations,
  ) {}

  public function addWeeklyActivity(
    int $householdId,
    int $responsiblePersonId,
    string $activityLabel,
    DateTimeImmutable $localStart,
    DateTimeImmutable $localEnd,
    string $preparationInstruction = '',
    int $preparationLeadMinutes = 0,
  ): ActivitySeries {
    $transaction = $this->database->startTransaction();

    try {
      $series = $this->domainMutations->createActivitySeries(
        $activityLabel,
        $householdId,
        $localStart,
        $localEnd,
        self::WEEKLY_RRULE,
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

}
