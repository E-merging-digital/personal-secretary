<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use Drupal\Core\Database\Connection;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Throwable;

/**
 * Orchestrates the smallest coherent first-activity setup transaction.
 */
final class FirstActivitySetupService {

  private const WEEKLY_RRULE = 'FREQ=WEEKLY;INTERVAL=1';

  public function __construct(
    private readonly Connection $database,
    private readonly DomainMutationService $domainMutations,
    private readonly ResponsibilityMutationService $responsibilityMutations,
    private readonly PreparationRequirementMutationService $preparationMutations,
  ) {}

  public function createFirstActivity(
    string $householdName,
    string $responsiblePersonName,
    string $activityLabel,
    DateTimeImmutable $localStart,
    DateTimeImmutable $localEnd,
    string $preparationInstruction = '',
    int $preparationLeadMinutes = 0,
  ): ActivitySeries {
    $transaction = $this->database->startTransaction();

    try {
      $person = $this->domainMutations->createPerson($responsiblePersonName);
      $household = $this->domainMutations->createHousehold(
        $householdName,
        [(int) $person->id()],
      );
      $series = $this->domainMutations->createActivitySeries(
        $activityLabel,
        (int) $household->id(),
        $localStart,
        $localEnd,
        self::WEEKLY_RRULE,
      );
      $this->responsibilityMutations->createResponsibilityRule(
        $series,
        (int) $person->id(),
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
