<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\personal_secretary\Entity\Household;
use Drupal\personal_secretary\Entity\Person;
use Drupal\personal_secretary\Entity\ResponsibilityOverride;
use Drupal\personal_secretary\Value\EffectiveResponsibility;
use InvalidArgumentException;
use RuntimeException;

/**
 * Application orchestration for one occurrence-specific responsibility choice.
 */
final class OccurrenceResponsibilityService {

  public const CHOICE_USE_RECURRING = 'use_recurring';
  public const CHOICE_CLEAR = 'clear';
  public const CHOICE_PERSON_PREFIX = 'person:';

  public function __construct(
    private readonly CurrentEffectiveOccurrenceResolver $currentOccurrences,
    private readonly EffectiveResponsibilityService $effectiveResponsibility,
    private readonly ResponsibilityMutationService $responsibilityMutations,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * @return array{
   *   series: \Drupal\personal_secretary\Entity\ActivitySeries,
   *   occurrence: \Drupal\personal_secretary\Value\EffectiveOccurrence,
   *   responsibility: \Drupal\personal_secretary\Value\EffectiveResponsibility,
   *   members: array<int, \Drupal\personal_secretary\Entity\Person>
   * }
   */
  public function context(int $seriesId, string $originalOccurrenceKey): array {
    $resolved = $this->currentOccurrences->resolve($seriesId, $originalOccurrenceKey);
    $series = $resolved['series'];
    $occurrence = $resolved['occurrence'];
    $responsibility = $this->effectiveResponsibility->resolve($series, $occurrence);

    $household = $series->get('household')->entity;
    if (!$household instanceof Household) {
      throw new RuntimeException('ActivitySeries references no current Household.');
    }

    $members = [];
    foreach ($household->get('members')->referencedEntities() as $member) {
      if (!$member instanceof Person || $member->id() === NULL) {
        throw new RuntimeException('Household membership references an invalid Person.');
      }
      $members[(int) $member->id()] = $member;
    }
    if ($members === []) {
      throw new RuntimeException('ActivitySeries Household has no current members.');
    }
    uasort(
      $members,
      static fn(Person $left, Person $right): int =>
        [(string) $left->label(), (int) $left->id()]
        <=> [(string) $right->label(), (int) $right->id()],
    );

    return [
      'series' => $series,
      'occurrence' => $occurrence,
      'responsibility' => $responsibility,
      'members' => $members,
    ];
  }

  public function apply(
    int $seriesId,
    string $originalOccurrenceKey,
    string $choice,
  ): void {
    $context = $this->context($seriesId, $originalOccurrenceKey);
    $series = $context['series'];
    $occurrence = $context['occurrence'];
    $responsibility = $context['responsibility'];
    $activeOverride = $this->activeOverride($responsibility);

    if ($choice === self::CHOICE_USE_RECURRING) {
      if ($activeOverride !== NULL) {
        $this->responsibilityMutations->withdrawOverride($activeOverride);
      }
      return;
    }

    if ($choice === self::CHOICE_CLEAR) {
      if ($activeOverride === NULL) {
        $this->responsibilityMutations->createClearOverride($series, $occurrence);
      }
      else {
        $this->responsibilityMutations->supersedeOverride(
          $activeOverride,
          $series,
          $occurrence,
          ResponsibilityOverride::ACTION_CLEAR_RESPONSIBILITY,
        );
      }
      return;
    }

    $personId = $this->personIdFromChoice($choice);
    if ($activeOverride === NULL) {
      $this->responsibilityMutations->createAssignOverride($series, $occurrence, $personId);
      return;
    }

    $this->responsibilityMutations->supersedeOverride(
      $activeOverride,
      $series,
      $occurrence,
      ResponsibilityOverride::ACTION_ASSIGN_PERSON,
      $personId,
    );
  }

  public function suggestedChoice(EffectiveResponsibility $responsibility): string {
    if ($responsibility->source !== EffectiveResponsibility::SOURCE_OVERRIDE) {
      return self::CHOICE_USE_RECURRING;
    }
    if ($responsibility->state === EffectiveResponsibility::STATE_NONE) {
      return self::CHOICE_CLEAR;
    }
    if (
      $responsibility->state === EffectiveResponsibility::STATE_ASSIGNED
      && $responsibility->responsiblePersonId !== NULL
    ) {
      return self::CHOICE_PERSON_PREFIX . $responsibility->responsiblePersonId;
    }

    throw new RuntimeException('Effective override responsibility has an invalid state.');
  }

  private function activeOverride(EffectiveResponsibility $responsibility): ?ResponsibilityOverride {
    if ($responsibility->source !== EffectiveResponsibility::SOURCE_OVERRIDE) {
      return NULL;
    }
    if ($responsibility->overrideId === NULL) {
      throw new RuntimeException('Override-derived responsibility has no ResponsibilityOverride identity.');
    }

    $override = $this->entityTypeManager
      ->getStorage('personal_sec_resp_override')
      ->load($responsibility->overrideId);
    if (!$override instanceof ResponsibilityOverride) {
      throw new RuntimeException('Effective responsibility references no current ResponsibilityOverride.');
    }
    if ((string) $override->get('status')->value !== ResponsibilityOverride::STATUS_ACTIVE) {
      throw new RuntimeException('Effective responsibility references a non-active ResponsibilityOverride.');
    }
    return $override;
  }

  private function personIdFromChoice(string $choice): int {
    if (!preg_match('/^person:([1-9][0-9]*)$/', $choice, $matches)) {
      throw new InvalidArgumentException('Unknown occurrence responsibility choice.');
    }
    return (int) $matches[1];
  }

}
