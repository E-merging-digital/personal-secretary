<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\Person;
use Drupal\personal_secretary\Value\EffectiveResponsibility;
use InvalidArgumentException;
use RuntimeException;

/**
 * Builds read-only upcoming activity presentation data from domain truth.
 */
final class UpcomingActivityService {

  private const DEFAULT_WINDOW_DAYS = 7;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EffectiveOccurrenceProjectionService $effectiveOccurrences,
    private readonly EffectiveResponsibilityService $effectiveResponsibility,
    private readonly PreparationEligibilityService $preparationEligibility,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns the default upcoming presentation window.
   *
   * @return array<int, array{
   *   activity_label: string,
   *   effective_start: string,
   *   effective_end: string,
   *   effective_start_iso: string,
   *   effective_end_iso: string,
   *   source_timezone: string,
   *   responsibility_label: string,
   *   preparations: array<int, array{instruction: string, due_time: string, due_time_iso: string}>,
   *   schedule_target: array{series_id: int},
   *   responsibility_target: array{series_id: int, original_occurrence_key: string},
   *   cancel_target: ?array{series_id: int, original_occurrence_key: string}
   * }>
   */
  public function upcoming(): array {
    $windowStart = (new DateTimeImmutable('@' . $this->time->getCurrentTime()))
      ->setTimezone(new DateTimeZone('UTC'));

    return $this->aggregate(
      $windowStart,
      $windowStart->modify('+' . self::DEFAULT_WINDOW_DAYS . ' days'),
    );
  }

  /**
   * Builds presentation items for an explicit bounded UTC window.
   *
   * @return array<int, array{
   *   activity_label: string,
   *   effective_start: string,
   *   effective_end: string,
   *   effective_start_iso: string,
   *   effective_end_iso: string,
   *   source_timezone: string,
   *   responsibility_label: string,
   *   preparations: array<int, array{instruction: string, due_time: string, due_time_iso: string}>,
   *   schedule_target: array{series_id: int},
   *   responsibility_target: array{series_id: int, original_occurrence_key: string},
   *   cancel_target: ?array{series_id: int, original_occurrence_key: string}
   * }>
   */
  public function aggregate(
    DateTimeImmutable $windowStart,
    DateTimeImmutable $windowEnd,
  ): array {
    $windowStart = $this->utc($windowStart);
    $windowEnd = $this->utc($windowEnd);
    if ($windowEnd <= $windowStart) {
      throw new InvalidArgumentException('Upcoming activity aggregation requires a bounded window with end after start.');
    }

    $sortable = [];
    $seriesStorage = $this->entityTypeManager->getStorage('personal_sec_activity_series');
    $personStorage = $this->entityTypeManager->getStorage('personal_secretary_person');

    foreach ($seriesStorage->loadMultiple() as $series) {
      if (!$series instanceof ActivitySeries) {
        throw new RuntimeException('ActivitySeries storage returned an unexpected entity type.');
      }

      $activityLabel = trim((string) $series->label());
      if ($activityLabel === '') {
        throw new RuntimeException('Upcoming ActivitySeries has no presentation label.');
      }

      foreach ($this->effectiveOccurrences->project($series, $windowStart, $windowEnd) as $occurrence) {
        $responsibility = $this->effectiveResponsibility->resolve($series, $occurrence);
        $responsibilityLabel = '';
        $preparations = [];

        if ($responsibility->state === EffectiveResponsibility::STATE_ASSIGNED) {
          if ($responsibility->responsiblePersonId === NULL || $responsibility->responsiblePersonUuid === NULL) {
            throw new RuntimeException('Assigned EffectiveResponsibility has no responsible Person identity.');
          }
          $person = $personStorage->load($responsibility->responsiblePersonId);
          if (!$person instanceof Person || $person->uuid() !== $responsibility->responsiblePersonUuid) {
            throw new RuntimeException('Assigned EffectiveResponsibility references no current matching Person.');
          }
          $responsibilityLabel = trim((string) $person->label());
          if ($responsibilityLabel === '') {
            throw new RuntimeException('Responsible Person has no presentation label.');
          }

          $sourceTimezone = new DateTimeZone($occurrence->sourceTimezone);
          foreach ($this->preparationEligibility->derive($series, $occurrence) as $preparation) {
            $dueLocal = $this->utc(new DateTimeImmutable($preparation->dueAtUtc))
              ->setTimezone($sourceTimezone);
            $preparations[] = [
              'instruction' => $preparation->requirementLabel,
              'due_time' => $dueLocal->format('Y-m-d H:i'),
              'due_time_iso' => $dueLocal->format(DATE_ATOM),
            ];
          }
        }
        elseif ($responsibility->state !== EffectiveResponsibility::STATE_NONE) {
          throw new RuntimeException('Unknown EffectiveResponsibility state.');
        }

        $seriesId = $series->id();
        if ($seriesId === NULL) {
          throw new RuntimeException('Upcoming ActivitySeries has no persisted identity.');
        }
        $target = [
          'series_id' => (int) $seriesId,
          'original_occurrence_key' => $occurrence->originalOccurrenceKey,
        ];

        $sortable[] = [
          'sort_start' => $occurrence->effectiveUtcStart,
          'activity_label' => $activityLabel,
          'effective_start' => (new DateTimeImmutable($occurrence->effectiveSourceLocalStart))->format('Y-m-d H:i'),
          'effective_end' => (new DateTimeImmutable($occurrence->effectiveSourceLocalEnd))->format('Y-m-d H:i'),
          'effective_start_iso' => $occurrence->effectiveSourceLocalStart,
          'effective_end_iso' => $occurrence->effectiveSourceLocalEnd,
          'source_timezone' => $occurrence->sourceTimezone,
          'responsibility_label' => $responsibilityLabel,
          'preparations' => $preparations,
          'schedule_target' => ['series_id' => (int) $seriesId],
          'responsibility_target' => $target,
          'cancel_target' => $occurrence->exceptionUuid === NULL ? $target : NULL,
        ];
      }
    }

    usort(
      $sortable,
      static fn(array $left, array $right): int =>
        [$left['sort_start'], $left['activity_label']]
        <=>
        [$right['sort_start'], $right['activity_label']],
    );

    return array_map(
      static function (array $item): array {
        unset($item['sort_start']);
        return $item;
      },
      $sortable,
    );
  }

  private function utc(DateTimeImmutable $value): DateTimeImmutable {
    return $value->setTimezone(new DateTimeZone('UTC'));
  }

}
