<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\personal_secretary\Value\SyntheticRoutineProposal;
use InvalidArgumentException;

/**
 * Pure, test-only synthetic projection: no routes, I/O, entities or providers.
 *
 * This does not substitute for ActivitySeries/ResponsibilityRule calculations:
 * those require real governed entities, which this prototype must not create.
 */
final class SyntheticRoutinePreviewService {

  public const APPLICABLE = 'APPLICABLE';
  public const NOT_APPLICABLE = 'NOT_APPLICABLE';
  public const NEEDS_CONFIRMATION = 'NEEDS_CONFIRMATION';

  /**
   * @param array<string, array<string, mixed>> $syntheticDays
   *   Exactly fourteen fictional day contexts, keyed by local civil date.
   * @param array<string, array<string, mixed>> $singleDayCorrections
   *   Fictional one-date overrides, never saved to any domain entity.
   *
   * @return array<int, array<string, mixed>>
   *   Preview-only local days, explanations, conflicts and virtual actions.
   */
  public function preview(
    SyntheticRoutineProposal $proposal,
    string $authorizedSyntheticHousehold,
    array $syntheticDays,
    array $singleDayCorrections = [],
  ): array {
    if ($authorizedSyntheticHousehold !== $proposal->householdKey) {
      throw new InvalidArgumentException('Synthetic Household boundary denied.');
    }
    $zone = new DateTimeZone($proposal->timezone);
    $date = new DateTimeImmutable($proposal->startDate . ' 00:00:00', $zone);
    $expected = [];
    for ($offset = 0; $offset < 14; $offset++) {
      $expected[] = $date->modify('+' . $offset . ' days')->format('Y-m-d');
    }
    $actual = array_keys($syntheticDays);
    sort($actual);
    if ($actual !== $expected) {
      throw new InvalidArgumentException('Expected exactly fourteen consecutive synthetic civil days.');
    }
    foreach (array_keys($singleDayCorrections) as $correctedDate) {
      if (!in_array($correctedDate, $expected, TRUE)) {
        throw new InvalidArgumentException('One-day correction is outside preview horizon.');
      }
    }

    $preview = [];
    foreach ($expected as $day) {
      $context = $syntheticDays[$day];
      if (isset($singleDayCorrections[$day])) {
        $context = array_replace($context, $singleDayCorrections[$day]);
      }
      $phase = $context['phase'] ?? 'UNKNOWN';
      if (!in_array($phase, ['START', 'MIDDLE', 'END', 'OUTSIDE', 'UNKNOWN'], TRUE)) {
        throw new InvalidArgumentException('Unrecognized synthetic responsibility phase.');
      }
      $presentAm = $context['present_am'] ?? NULL;
      $presentPm = $context['present_pm'] ?? NULL;
      $schoolDay = $context['school_day'] ?? NULL;
      foreach ([$presentAm, $presentPm, $schoolDay] as $flag) {
        if ($flag !== NULL && !is_bool($flag)) {
          throw new InvalidArgumentException('Synthetic context flag must be boolean or UNKNOWN.');
        }
      }
      $schools = $context['schools'] ?? [];
      if (!is_array($schools) || count($schools) !== 2) {
        throw new InvalidArgumentException('Two fictional school stop states required.');
      }
      foreach ($schools as $school => $state) {
        if (!str_starts_with((string) $school, 'FICTIVE_')
          || !in_array($state, ['OPEN', 'CLOSED', 'UNKNOWN'], TRUE)) {
          throw new InvalidArgumentException('Only fictional school stops with explicit states permitted.');
        }
      }

      $suppressedKeys = $context['suppressed_virtual_keys'] ?? [];
      if (!is_array($suppressedKeys)) {
        throw new InvalidArgumentException('Synthetic suppression keys must be an array.');
      }
      foreach ($suppressedKeys as $key) {
        if (!is_string($key) || preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
          throw new InvalidArgumentException('Invalid synthetic virtual action identity.');
        }
      }

      $why = [];
      $morning = self::NOT_APPLICABLE;
      $afternoon = self::NOT_APPLICABLE;
      $actions = [];
      $add = function (string $kind, string $detail) use (&$actions, $proposal, $day, $suppressedKeys): void {
        $key = hash('sha256', implode('|', [$proposal->key, $proposal->version, $day, $kind, $detail]));
        if (!in_array($key, $suppressedKeys, TRUE)) {
          $actions[$key] = ['key' => $key, 'kind' => $kind, 'detail' => $detail, 'effect' => 'VIRTUAL_ONLY'];
        }
      };

      if ($phase === 'UNKNOWN') {
        $morning = $afternoon = self::NEEDS_CONFIRMATION;
        $why[] = 'Période de responsabilité non vérifiée.';
      }
      elseif ($phase === 'OUTSIDE') {
        $why[] = 'Aucune responsabilité applicable ce jour.';
      }
      else {
        // Transitions differ from simple event/calendar boundary timestamps.
        $morningPresence = $phase === 'START' ? FALSE : $presentAm;
        $afternoonPresence = $phase === 'END' ? FALSE : $presentPm;
        $morning = $phase === 'START'
          ? self::NOT_APPLICABLE : $this->schoolRoutineState($morningPresence, $schoolDay, $schools, $proposal);
        $afternoon = $phase === 'END'
          ? self::NOT_APPLICABLE : $this->schoolRoutineState($afternoonPresence, $schoolDay, $schools, $proposal);
        if ($phase === 'START') {
          $why[] = 'Début de période : pas de présence le matin.';
        }
        if ($phase === 'END') {
          $why[] = 'Fin de période : pas de collecte scolaire l’après-midi.';
        }
        if ($schoolDay === FALSE) {
          $why[] = 'Pas de trajet scolaire pendant fermeture ou congé.';
        }
        if ($morning === self::APPLICABLE) {
          if ($proposal->suggestPreparation) {
            $add('PREPARATION_SUGGESTION', 'Préparer le kit fictif');
          }
          if ($proposal->suggestMorningStops && $schoolDay === TRUE) {
            foreach ($schools as $school => $state) {
              if ($state === 'OPEN') {
                $add('TRAVEL_STOP_SUGGESTION', $school . ' (horaire de départ non estimé)');
              }
            }
          }
        }
        if ($afternoon === self::APPLICABLE && $proposal->suggestAfternoonCollection && $schoolDay === TRUE) {
          foreach ($schools as $school => $state) {
            if ($state === 'OPEN') {
              $add('COLLECTION_STOP_SUGGESTION', $school . ' (horaire non estimé)');
            }
          }
        }
        if ($morning === self::NEEDS_CONFIRMATION || $afternoon === self::NEEDS_CONFIRMATION) {
          $why[] = 'Présence, ouverture ou contexte scolaire à confirmer.';
        }
        elseif ($morning === self::APPLICABLE || $afternoon === self::APPLICABLE) {
          $why[] = 'Présence et ouverture des centres confirmées dans le scénario fictif.';
        }
      }

      $timeState = 'NONE';
      $exchange = $context['exchange'] ?? NULL;
      if ($exchange !== NULL && $phase !== 'OUTSIDE') {
        if (!is_array($exchange) || !isset($exchange['window'])
          || !is_array($exchange['window']) || count($exchange['window']) !== 2) {
          throw new InvalidArgumentException('A fictional planned exchange window is required.');
        }
        [$from, $until] = array_values($exchange['window']);
        $exact = $exchange['exact'] ?? NULL;
        foreach ([$from, $until] as $clock) {
          if (!$this->validClock($clock)) {
            throw new InvalidArgumentException('Invalid synthetic local window.');
          }
        }
        if ($from >= $until || ($exact !== NULL && (!$this->validClock($exact) || $exact < $from || $exact > $until))) {
          throw new InvalidArgumentException('Synthetic exact time must be confirmed inside its planned window.');
        }
        if ($exact === NULL) {
          $timeState = 'EXACT_TIME_PENDING';
          $add('TIME_CONFIRMATION_SUGGESTION', 'Convenir de l’heure dans la plage indicative');
          $why[] = 'Plage indicative, aucune heure exacte confirmée.';
        }
        else {
          $timeState = 'EXACT_TIME_CONFIRMED';
          $why[] = 'Heure fictive confirmée uniquement pour cette date.';
        }
      }
      $external = $context['external'] ?? ['freshness' => 'CURRENT', 'events' => []];
      if (!is_array($external) || !in_array($external['freshness'] ?? NULL, ['CURRENT', 'STALE'], TRUE)
        || !is_array($external['events'] ?? NULL)) {
        throw new InvalidArgumentException('Invalid synthetic external signal state.');
      }
      $conflicts = [];
      if ($external['freshness'] === 'STALE') {
        $conflicts[] = 'SOURCE_STALE_AVAILABILITY_UNKNOWN';
        $why[] = 'Agenda externe périmé, disponibilité non garantie.';
      }
      foreach ($external['events'] as $event) {
        if (!is_array($event) || !in_array($event['visibility'] ?? NULL, ['OPAQUE', 'TRANSPARENT'], TRUE)) {
          throw new InvalidArgumentException('Invalid synthetic external event transparency.');
        }
        $conflicts[] = $event['visibility'] === 'OPAQUE' ? 'POTENTIAL_BUSY_CONFLICT' : 'INFORMATION_ONLY';
      }
      $status = in_array(self::NEEDS_CONFIRMATION, [$morning, $afternoon], TRUE)
        || $timeState === 'EXACT_TIME_PENDING' || $external['freshness'] === 'STALE'
        ? self::NEEDS_CONFIRMATION
        : (in_array(self::APPLICABLE, [$morning, $afternoon], TRUE) || $timeState === 'EXACT_TIME_CONFIRMED'
          ? self::APPLICABLE : self::NOT_APPLICABLE);
      $preview[] = [
        'date' => $day,
        'timezone' => $proposal->timezone,
        'phase' => $phase,
        'status' => $status,
        'morning' => $morning,
        'afternoon' => $afternoon,
        'exchange' => $timeState,
        'conflicts' => array_values(array_unique($conflicts)),
        'actions' => array_values($actions),
        'why' => array_values(array_unique($why)),
        'provenance' => 'SYNTHETIC_CONTEXT_ONLY',
        'saved' => FALSE,
      ];
    }
    return $preview;
  }

  /** @param array<string, string> $schools */
  private function schoolRoutineState(
    ?bool $present,
    ?bool $schoolDay,
    array $schools,
    SyntheticRoutineProposal $proposal,
  ): string {
    if ($proposal->requirePhysicalPresence && $present === FALSE) {
      return self::NOT_APPLICABLE;
    }
    if ($proposal->requireSchoolDay && $schoolDay === FALSE) {
      return self::NOT_APPLICABLE;
    }
    if (($proposal->requirePhysicalPresence && $present === NULL)
      || ($proposal->requireSchoolDay && $schoolDay === NULL)) {
      return self::NEEDS_CONFIRMATION;
    }
    // Unknown school closure must never become a positive travel suggestion.
    if ($proposal->requireSchoolDay && in_array('UNKNOWN', $schools, TRUE)) {
      return self::NEEDS_CONFIRMATION;
    }
    if ($proposal->requireSchoolDay && !in_array('OPEN', $schools, TRUE)) {
      return self::NOT_APPLICABLE;
    }
    return self::APPLICABLE;
  }

  private function validClock(mixed $clock): bool {
    return is_string($clock) && preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $clock) === 1;
  }

  /** @param array<int, array<string, mixed>> $preview */
  public function markdown(array $preview): string {
    $labels = [
      self::APPLICABLE => 'Applicable',
      self::NOT_APPLICABLE => 'Sans effet',
      self::NEEDS_CONFIRMATION => 'À confirmer',
      'NONE' => '—',
      'EXACT_TIME_PENDING' => 'Heure à confirmer',
      'EXACT_TIME_CONFIRMED' => 'Heure fictive confirmée',
    ];
    $lines = ['# Aperçu fictif sur 14 jours — PREVIEW_ONLY / NO_SAVED', '',
      '| Date | Statut | Matin | Après-midi | Transfert | Conflits | Actions proposées |',
      '| --- | --- | --- | --- | --- | --- | --- |'];
    $explanations = [];
    foreach ($preview as $day) {
      $lines[] = implode(' | ', [
        '| ' . $day['date'], $labels[$day['status']], $labels[$day['morning']], $labels[$day['afternoon']],
        $labels[$day['exchange']] ?? $day['exchange'], implode(', ', $day['conflicts']) ?: '—',
        implode(', ', array_map(static fn(array $a): string => str_replace('|', '/', $a['kind'] . ': ' . $a['detail']), $day['actions'])) ?: '—',
      ]) . ' |';
      $explanations[] = '- **' . $day['date'] . '** : ' . (implode(' ', $day['why']) ?: 'Aucune obligation dérivée.') . ' Provenance : scénario entièrement fictif.';
    }
    return implode("\n", array_merge($lines, ['', '## Explications et provenance', ''], $explanations)) . "\n";
  }

}
