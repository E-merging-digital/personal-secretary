<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\personal_secretary\Entity\Household;
use Drupal\personal_secretary\Entity\Person;
use Drupal\personal_secretary\Value\BaseOccurrence;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Coordinates one future recurring responsibility transition.
 */
final class EditRecurringResponsibilityService {

  private const WEEKLY_RRULE = CurrentRecurringResponsibilityResolver::WEEKLY_RRULE;
  private const PROJECTION_WINDOW_DAYS = 8;

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly CurrentRecurringResponsibilityResolver $currentResponsibility,
    private readonly ResponsibilityContextValidator $responsibilityContext,
    private readonly OccurrenceProjectionService $occurrenceProjection,
    private readonly ResponsibilityMutationService $responsibilityMutations,
  ) {}

  /**
   * @return array{
   *   series: \Drupal\personal_secretary\Entity\ActivitySeries,
   *   rule: \Drupal\personal_secretary\Entity\ResponsibilityRule,
   *   responsible_person: \Drupal\personal_secretary\Entity\Person,
   *   source_timezone: string,
   *   current_local_start: \DateTimeImmutable,
   *   current_local_end: \DateTimeImmutable,
   *   current_rule_utc_start: \DateTimeImmutable,
   *   member_options: array<string, string>,
   *   default_effective_date: string
   * }
   */
  public function context(int $seriesId): array {
    $resolved = $this->currentResponsibility->resolve($seriesId);
    $household = $this->responsibilityContext->household($resolved['series']);
    $memberOptions = $this->memberOptions($household);
    $currentPersonId = (string) $resolved['responsible_person']->id();
    if (!isset($memberOptions[$currentPersonId])) {
      throw new RuntimeException('Current recurring responsible Person is not selectable in the ActivitySeries Household.');
    }

    $timezone = new DateTimeZone($resolved['source_timezone']);
    $todayLocal = (new DateTimeImmutable('@' . $this->time->getCurrentTime()))
      ->setTimezone($timezone)
      ->setTime(0, 0);

    return $resolved + [
      'member_options' => $memberOptions,
      'default_effective_date' => $todayLocal->modify('+1 day')->format('Y-m-d'),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function prepare(
    int $seriesId,
    string $effectiveDate,
    int $responsiblePersonId,
  ): array {
    $context = $this->context($seriesId);
    if ($responsiblePersonId <= 0) {
      throw new InvalidArgumentException('A responsible Person must be selected.');
    }
    $selectedPerson = $this->responsibilityContext->requireMember(
      $context['series'],
      $responsiblePersonId,
    );

    $timezone = new DateTimeZone($context['source_timezone']);
    $requestedLocalBoundary = DateTimeImmutable::createFromFormat(
      '!Y-m-d H:i',
      $effectiveDate . ' 00:00',
      $timezone,
    );
    if (
      !$requestedLocalBoundary instanceof DateTimeImmutable
      || $requestedLocalBoundary->format('Y-m-d') !== $effectiveDate
    ) {
      throw new InvalidArgumentException('Effective-from date is invalid.');
    }

    $todayLocal = (new DateTimeImmutable('@' . $this->time->getCurrentTime()))
      ->setTimezone($timezone)
      ->setTime(0, 0);
    if ($requestedLocalBoundary <= $todayLocal) {
      throw new InvalidArgumentException('Effective-from date must be in the future.');
    }

    $requestedBoundaryUtc = $requestedLocalBoundary->setTimezone(new DateTimeZone('UTC'));
    $firstAffected = $this->firstAffectedBaseOccurrence(
      $context['series'],
      $requestedLocalBoundary,
      $requestedBoundaryUtc,
    );
    $ruleTransitionUtc = (new DateTimeImmutable($firstAffected->utcStart))
      ->setTimezone(new DateTimeZone('UTC'));
    $replacementLocalStart = (new DateTimeImmutable($firstAffected->sourceLocalStart))
      ->setTimezone($timezone);
    $replacementLocalEnd = (new DateTimeImmutable($firstAffected->sourceLocalEnd))
      ->setTimezone($timezone);
    if ($replacementLocalEnd <= $replacementLocalStart) {
      throw new RuntimeException('First affected ActivitySeries occurrence has an invalid local window.');
    }
    if (
      $replacementLocalStart->getTimestamp() !== $ruleTransitionUtc->getTimestamp()
      || $replacementLocalEnd->getTimestamp() !== (new DateTimeImmutable($firstAffected->utcEnd))->getTimestamp()
    ) {
      throw new RuntimeException('First affected ActivitySeries occurrence has inconsistent UTC/local audit context.');
    }

    $samePerson = (int) $context['responsible_person']->id() === (int) $selectedPerson->id();
    if (!$samePerson && $ruleTransitionUtc <= $context['current_rule_utc_start']) {
      throw new InvalidArgumentException('Recurring responsibility transition must occur after the current rule DTSTART.');
    }

    return $context + [
      'selected_person' => $selectedPerson,
      'requested_local_boundary' => $requestedLocalBoundary,
      'first_affected_base_occurrence' => $firstAffected,
      'rule_transition_utc' => $ruleTransitionUtc,
      'replacement_local_start' => $replacementLocalStart,
      'replacement_local_end' => $replacementLocalEnd,
      'same_person' => $samePerson,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function apply(
    int $seriesId,
    string $effectiveDate,
    int $responsiblePersonId,
  ): array {
    $plan = $this->prepare($seriesId, $effectiveDate, $responsiblePersonId);
    if ($plan['same_person']) {
      return $plan + [
        'noop' => TRUE,
        'retired_rule' => NULL,
        'replacement_rule' => NULL,
      ];
    }

    $transaction = $this->database->startTransaction();
    try {
      $retiredRule = $this->responsibilityMutations->retireResponsibilityRule(
        $plan['rule'],
        $plan['rule_transition_utc'],
      );
      $replacementRule = $this->responsibilityMutations->createResponsibilityRule(
        $plan['series'],
        (int) $plan['selected_person']->id(),
        $plan['replacement_local_start'],
        $plan['replacement_local_end'],
        self::WEEKLY_RRULE,
      );

      $transaction->commitOrRelease();
      return $plan + [
        'noop' => FALSE,
        'retired_rule' => $retiredRule,
        'replacement_rule' => $replacementRule,
      ];
    }
    catch (Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  private function firstAffectedBaseOccurrence(
    object $series,
    DateTimeImmutable $requestedLocalBoundary,
    DateTimeImmutable $requestedBoundaryUtc,
  ): BaseOccurrence {
    $windowEndUtc = $requestedLocalBoundary
      ->modify('+' . self::PROJECTION_WINDOW_DAYS . ' days')
      ->setTimezone(new DateTimeZone('UTC'));
    $candidates = array_values(array_filter(
      $this->occurrenceProjection->project($series, $requestedBoundaryUtc, $windowEndUtc),
      static fn(BaseOccurrence $occurrence): bool =>
        (new DateTimeImmutable($occurrence->utcStart))->getTimestamp() >= $requestedBoundaryUtc->getTimestamp(),
    ));
    if ($candidates === []) {
      throw new InvalidArgumentException('Unable to resolve the first future ActivitySeries occurrence from the requested date.');
    }

    usort(
      $candidates,
      static fn(BaseOccurrence $left, BaseOccurrence $right): int =>
        [$left->utcStart, $left->originalOccurrenceKey]
        <=> [$right->utcStart, $right->originalOccurrenceKey],
    );
    $firstTimestamp = (new DateTimeImmutable($candidates[0]->utcStart))->getTimestamp();
    $firstCandidates = array_values(array_filter(
      $candidates,
      static fn(BaseOccurrence $occurrence): bool =>
        (new DateTimeImmutable($occurrence->utcStart))->getTimestamp() === $firstTimestamp,
    ));
    if (count($firstCandidates) !== 1) {
      throw new InvalidArgumentException('First affected ActivitySeries occurrence is ambiguous.');
    }

    $first = $firstCandidates[0];
    if (
      $first->seriesUuid !== $series->uuid()
      || $first->seriesRevisionId !== (string) $series->getRevisionId()
      || $first->sourceTimezone !== $requestedLocalBoundary->getTimezone()->getName()
    ) {
      throw new RuntimeException('First affected ActivitySeries occurrence is not aligned with current series context.');
    }

    return $first;
  }

  /**
   * @return array<string, string>
   */
  private function memberOptions(Household $household): array {
    $options = [];
    foreach ($household->get('members')->referencedEntities() as $person) {
      if (!$person instanceof Person || $person->id() === NULL) {
        throw new RuntimeException('Household membership references an invalid Person.');
      }
      $label = trim((string) $person->label());
      if ($label === '') {
        throw new RuntimeException('Household member has no Person label.');
      }
      $options[(string) $person->id()] = $label;
    }
    if ($options === []) {
      throw new RuntimeException('ActivitySeries Household has no selectable members.');
    }
    natcasesort($options);
    return $options;
  }

}
