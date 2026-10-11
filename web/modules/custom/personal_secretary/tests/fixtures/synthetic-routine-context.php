<?php

declare(strict_types=1);

/**
 * Entirely invented scenario. Never adapt or copy a real user's private data.
 * An isolated deterministic interpreter double maps the fictional statement.
 */
return static function (): array {
  $proposal = new \Drupal\personal_secretary\Value\SyntheticRoutineProposal(
    key: 'SYNTHETIC_RULE_A',
    version: 1,
    fictionalSentence: 'Durant la période fictive et si les centres sont ouverts, suggérer le kit et les étapes.',
    householdKey: 'SYNTHETIC_HOUSEHOLD_A',
    startDate: '2027-03-22',
  );
  $dates = [];
  $start = new \DateTimeImmutable($proposal->startDate, new \DateTimeZone($proposal->timezone));
  for ($i = 0; $i < 14; $i++) {
    $date = $start->modify('+' . $i . ' days')->format('Y-m-d');
    $phase = $i === 0 ? 'START' : ($i === 7 ? 'END' : ($i < 7 ? 'MIDDLE' : 'OUTSIDE'));
    $dates[$date] = [
      'phase' => $phase,
      'present_am' => $i !== 0 && $i < 8,
      'present_pm' => $i < 7,
      'school_day' => $i < 8 && !in_array($i, [5, 6, 7], TRUE),
      'schools' => ['FICTIVE_CENTRE_ALPHA' => 'OPEN', 'FICTIVE_CENTRE_BETA' => 'OPEN'],
      'external' => ['freshness' => 'CURRENT', 'events' => []],
      'task_postponed' => FALSE,
    ];
  }
  $dates['2027-03-23']['external']['events'][] = ['visibility' => 'OPAQUE'];
  $dates['2027-03-24']['external']['events'][] = ['visibility' => 'TRANSPARENT'];
  $dates['2027-03-25']['schools']['FICTIVE_CENTRE_ALPHA'] = 'CLOSED';
  $dates['2027-03-26']['schools']['FICTIVE_CENTRE_BETA'] = 'UNKNOWN';
  $dates['2027-03-26']['external']['freshness'] = 'STALE';
  $dates['2027-03-26']['task_postponed'] = TRUE;
  $dates['2027-03-28']['exchange'] = ['window' => ['10:40', '12:20'], 'exact' => NULL];
  $dates['2027-03-29']['exchange'] = ['window' => ['10:40', '12:20'], 'exact' => NULL];
  return [$proposal, $dates];
};
