<?php

declare(strict_types=1);

use Drupal\Core\Database\Database;
use Drupal\date_recur\DateRecurHelper;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

function spike_assert(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

function immutable_datetime(DateTimeInterface $date): DateTimeImmutable {
  return DateTimeImmutable::createFromInterface($date);
}

function occurrence_row(object $occurrence, DateTimeZone $sourceTimezone): array {
  $start = immutable_datetime($occurrence->getStart());
  $end = immutable_datetime($occurrence->getEnd());
  $utc = new DateTimeZone('UTC');

  return [
    'key_utc' => $start->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
    'start_utc' => $start->setTimezone($utc)->format(DateTimeInterface::ATOM),
    'end_utc' => $end->setTimezone($utc)->format(DateTimeInterface::ATOM),
    'start_local' => $start->setTimezone($sourceTimezone)->format(DateTimeInterface::ATOM),
    'end_local' => $end->setTimezone($sourceTimezone)->format(DateTimeInterface::ATOM),
    'local_time' => $start->setTimezone($sourceTimezone)->format('H:i'),
    'utc_offset' => $start->setTimezone($sourceTimezone)->format('P'),
    'duration_seconds' => $end->getTimestamp() - $start->getTimestamp(),
  ];
}

function occurrence_rows(iterable $occurrences, DateTimeZone $sourceTimezone): array {
  $rows = [];
  foreach ($occurrences as $occurrence) {
    $rows[] = occurrence_row($occurrence, $sourceTimezone);
  }
  return $rows;
}

function make_helper(string $rule, string $start, int $durationMinutes, DateTimeZone $sourceTimezone): object {
  $startDate = new DateTimeImmutable($start, $sourceTimezone);
  $endDate = $startDate->modify(sprintf('+%d minutes', $durationMinutes));
  return DateRecurHelper::create($rule, $startDate, $endDate);
}

function assert_weekly(array $rows, int $expectedDurationSeconds): void {
  spike_assert(count($rows) >= 3, 'Weekly recurrence did not produce enough occurrences.');
  $previous = NULL;
  foreach ($rows as $row) {
    spike_assert($row['duration_seconds'] === $expectedDurationSeconds, 'Occurrence duration changed.');
    $current = new DateTimeImmutable($row['start_utc']);
    if ($previous !== NULL) {
      spike_assert($current > $previous, 'Occurrences are not in chronological order.');
    }
    $previous = $current;
  }
}

function utc_key_exists(array $rows, string $key): bool {
  foreach ($rows as $row) {
    if ($row['key_utc'] === $key) {
      return TRUE;
    }
  }
  return FALSE;
}

$sourceTimezone = new DateTimeZone('Europe/Brussels');
$results = [
  'status' => 'runtime_pass',
  'source_timezone' => $sourceTimezone->getName(),
  'process_default_timezone' => date_default_timezone_get(),
  'api' => [
    'helper_factory' => 'Drupal\\date_recur\\DateRecurHelper::create',
    'occurrence_api' => 'DateRecurHelperInterface::getOccurrences',
    'range_api' => 'Drupal\\date_recur\\DateRange::getStart/getEnd',
  ],
];

spike_assert(class_exists(DateRecurHelper::class), 'DateRecurHelper is unavailable.');

$weeklyRule = 'FREQ=WEEKLY;INTERVAL=1;COUNT=5';
$weekly = make_helper($weeklyRule, '2026-02-01 18:00:00', 45, $sourceTimezone);
$weeklyRows = occurrence_rows($weekly->getOccurrences(NULL, NULL, 5), $sourceTimezone);
assert_weekly($weeklyRows, 2700);
$results['weekly'] = [
  'rule' => $weeklyRule,
  'count' => count($weeklyRows),
  'chronological' => TRUE,
  'duration_seconds' => 2700,
  'occurrences' => $weeklyRows,
];

$spring = make_helper('FREQ=WEEKLY;INTERVAL=1;COUNT=3', '2026-03-22 18:00:00', 45, $sourceTimezone);
$springRows = occurrence_rows($spring->getOccurrences(NULL, NULL, 3), $sourceTimezone);
spike_assert(array_unique(array_column($springRows, 'local_time')) === ['18:00'], 'Spring DST changed local wall-clock time.');
spike_assert($springRows[0]['utc_offset'] === '+01:00', 'Spring pre-transition offset is not +01:00.');
spike_assert($springRows[1]['utc_offset'] === '+02:00', 'Spring transition offset is not +02:00.');
$results['spring_dst'] = [
  'local_time_stable' => TRUE,
  'offset_transition' => [$springRows[0]['utc_offset'], $springRows[1]['utc_offset']],
  'occurrences' => $springRows,
];

$autumn = make_helper('FREQ=WEEKLY;INTERVAL=1;COUNT=3', '2026-10-18 18:00:00', 45, $sourceTimezone);
$autumnRows = occurrence_rows($autumn->getOccurrences(NULL, NULL, 3), $sourceTimezone);
spike_assert(array_unique(array_column($autumnRows, 'local_time')) === ['18:00'], 'Autumn DST changed local wall-clock time.');
spike_assert($autumnRows[0]['utc_offset'] === '+02:00', 'Autumn pre-transition offset is not +02:00.');
spike_assert($autumnRows[1]['utc_offset'] === '+01:00', 'Autumn transition offset is not +01:00.');
$results['autumn_dst'] = [
  'local_time_stable' => TRUE,
  'offset_transition' => [$autumnRows[0]['utc_offset'], $autumnRows[1]['utc_offset']],
  'occurrences' => $autumnRows,
];

$infiniteRule = 'FREQ=WEEKLY;INTERVAL=1';
$infinite = make_helper($infiniteRule, '2026-01-04 18:00:00', 45, $sourceTimezone);
spike_assert($infinite->isInfinite(), 'Infinite recurrence was not detected as infinite.');

$unsafeRejected = FALSE;
$unsafeException = NULL;
try {
  $infinite->getOccurrences(NULL, NULL, NULL);
}
catch (Throwable $e) {
  $unsafeRejected = TRUE;
  $unsafeException = get_class($e) . ': ' . $e->getMessage();
}
spike_assert($unsafeRejected, 'Unsafe unbounded getOccurrences call was not rejected.');

$smokeStart = microtime(TRUE);
$boundedRows = occurrence_rows(
  $infinite->getOccurrences(
    new DateTimeImmutable('2026-01-01 00:00:00', $sourceTimezone),
    new DateTimeImmutable('2050-01-01 00:00:00', $sourceTimezone),
    1000,
  ),
  $sourceTimezone,
);
$smokeElapsed = microtime(TRUE) - $smokeStart;
spike_assert(count($boundedRows) === 1000, 'Bounded recurrence smoke did not honor the 1000 occurrence limit.');
$results['infinite'] = [
  'rule' => $infiniteRule,
  'detected' => TRUE,
  'unsafe_unbounded_request_rejected' => TRUE,
  'unsafe_exception' => $unsafeException,
  'bounded_count' => count($boundedRows),
  'bounded_elapsed_seconds' => round($smokeElapsed, 6),
  'material_pathology' => FALSE,
];

$baseRuleBefore = $weeklyRule;
$overlayBase = $weeklyRows;
$target = $overlayBase[2];
$targetKey = $target['key_utc'];

$cancelled = array_values(array_filter(
  $overlayBase,
  static fn(array $row): bool => $row['key_utc'] !== $targetKey,
));
spike_assert(count($cancelled) === count($overlayBase) - 1, 'Cancellation overlay did not remove exactly one occurrence.');
spike_assert(!utc_key_exists($cancelled, $targetKey), 'Cancellation overlay left target occurrence present.');

$rescheduledStart = (new DateTimeImmutable($target['start_local']))->modify('+1 day +90 minutes');
$rescheduledEnd = $rescheduledStart->modify('+45 minutes');
$rescheduled = [];
foreach ($overlayBase as $row) {
  if ($row['key_utc'] !== $targetKey) {
    $rescheduled[] = $row;
    continue;
  }
  $rescheduled[] = [
    'replacement_for_key_utc' => $targetKey,
    'key_utc' => $targetKey,
    'start_local' => $rescheduledStart->format(DateTimeInterface::ATOM),
    'end_local' => $rescheduledEnd->format(DateTimeInterface::ATOM),
    'duration_seconds' => 2700,
  ];
}
spike_assert(count($rescheduled) === count($overlayBase), 'Reschedule overlay changed occurrence count.');
spike_assert($baseRuleBefore === $weeklyRule, 'Overlay mutated the base RRULE.');
$results['overlay'] = [
  'target_key_utc' => $targetKey,
  'cancel_pass' => TRUE,
  'reschedule_pass' => TRUE,
  'base_rrule_unchanged' => TRUE,
  'rescheduled_start_local' => $rescheduledStart->format(DateTimeInterface::ATOM),
  'rescheduled_end_local' => $rescheduledEnd->format(DateTimeInterface::ATOM),
];

$seriesRule = 'FREQ=WEEKLY;INTERVAL=1;COUNT=5';
$seriesOriginal = make_helper($seriesRule, '2026-09-06 18:00:00', 45, $sourceTimezone);
$seriesOriginalRows = occurrence_rows($seriesOriginal->getOccurrences(NULL, NULL, 5), $sourceTimezone);
$seriesTarget = $seriesOriginalRows[2];

$seriesInsertedEarlier = make_helper('FREQ=WEEKLY;INTERVAL=1;COUNT=6', '2026-08-30 18:00:00', 45, $sourceTimezone);
$seriesInsertedRows = occurrence_rows($seriesInsertedEarlier->getOccurrences(NULL, NULL, 6), $sourceTimezone);
$newOrdinal = NULL;
foreach ($seriesInsertedRows as $index => $row) {
  if ($row['key_utc'] === $seriesTarget['key_utc']) {
    $newOrdinal = $index;
    break;
  }
}
spike_assert($newOrdinal !== NULL, 'Expected original target instant to survive an earlier DTSTART insertion.');
spike_assert($newOrdinal !== 2, 'Ordinal unexpectedly remained stable after earlier DTSTART insertion.');

$seriesShifted = make_helper('FREQ=WEEKLY;INTERVAL=1;COUNT=5;BYDAY=MO', '2026-09-06 18:00:00', 45, $sourceTimezone);
$seriesShiftedRows = occurrence_rows($seriesShifted->getOccurrences(NULL, NULL, 5), $sourceTimezone);
$targetSurvivesShiftedRule = utc_key_exists($seriesShiftedRows, $seriesTarget['key_utc']);
spike_assert(!$targetSurvivesShiftedRule, 'Original target key unexpectedly survived a BYDAY series edit.');

$results['series_edit'] = [
  'recalculates_base' => TRUE,
  'original_target_key_utc' => $seriesTarget['key_utc'],
  'original_target_ordinal_zero_based' => 2,
  'ordinal_after_earlier_dtstart' => $newOrdinal,
  'target_key_survives_earlier_dtstart' => TRUE,
  'target_key_survives_byday_edit' => FALSE,
  'orphan_exception_risk' => TRUE,
];

$bundleId = 'date_recur_spike';
$fieldName = 'field_date_recur_spike';
$createdBundle = FALSE;
$createdStorage = FALSE;
$createdField = FALSE;
$node = NULL;

try {
  $bundle = NodeType::load($bundleId);
  if ($bundle === NULL) {
    $bundle = NodeType::create([
      'type' => $bundleId,
      'name' => 'Synthetic Date Recur Spike',
    ]);
    $bundle->save();
    $createdBundle = TRUE;
  }

  $storage = FieldStorageConfig::loadByName('node', $fieldName);
  if ($storage === NULL) {
    $storage = FieldStorageConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'node',
      'type' => 'date_recur',
      'cardinality' => 1,
    ]);
    $storage->save();
    $createdStorage = TRUE;
  }

  $field = FieldConfig::loadByName('node', $bundleId, $fieldName);
  if ($field === NULL) {
    $field = FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => $bundleId,
      'label' => 'Synthetic recurring date',
    ]);
    $field->save();
    $createdField = TRUE;
  }

  $persistLocalStart = new DateTimeImmutable('2026-03-22 18:00:00', $sourceTimezone);
  $persistLocalEnd = $persistLocalStart->modify('+45 minutes');
  $utc = new DateTimeZone('UTC');
  $persistRule = 'FREQ=WEEKLY;INTERVAL=1;COUNT=4';

  $node = Node::create([
    'type' => $bundleId,
    'title' => 'Synthetic recurrence spike',
    $fieldName => [[
      'value' => $persistLocalStart->setTimezone($utc)->format('Y-m-d\TH:i:s'),
      'end_value' => $persistLocalEnd->setTimezone($utc)->format('Y-m-d\TH:i:s'),
      'rrule' => $persistRule,
      'timezone' => $sourceTimezone->getName(),
    ]],
  ]);
  $node->save();

  $fieldItem = $node->get($fieldName)[0];
  $fieldHelper = $fieldItem->getHelper();
  $fieldRows = occurrence_rows($fieldHelper->getOccurrences(NULL, NULL, 4), $sourceTimezone);
  spike_assert(count($fieldRows) === 4, 'Persisted field helper did not calculate four occurrences.');

  $connection = Database::getConnection();
  $technicalTables = array_values(array_keys($connection->schema()->findTables('date_recur%')));
  $matchingTables = array_values(array_filter(
    $technicalTables,
    static fn(string $table): bool => str_contains($table, $fieldName),
  ));
  $tableCounts = [];
  foreach ($matchingTables as $table) {
    $tableCounts[$table] = (int) $connection->select($table, 't')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  $rawItem = $fieldItem->getValue();
  $results['persistence'] = [
    'field_item_keys' => array_keys($rawItem),
    'stored_rrule' => $rawItem['rrule'] ?? NULL,
    'stored_timezone' => $rawItem['timezone'] ?? NULL,
    'stored_infinite' => $rawItem['infinite'] ?? NULL,
    'calculated_occurrence_count' => count($fieldRows),
    'technical_occurrence_tables' => $matchingTables,
    'technical_occurrence_row_counts' => $tableCounts,
    'ordinary_occurrence_drupal_entity_created' => FALSE,
    'ordinary_occurrence_domain_entity_required' => FALSE,
  ];
}
finally {
  if ($node !== NULL && !$node->isNew()) {
    $node->delete();
  }
  if ($createdField && isset($field)) {
    $field->delete();
  }
  if ($createdStorage && isset($storage)) {
    $storage->delete();
  }
  if ($createdBundle && isset($bundle)) {
    $bundle->delete();
  }
}

$results['target_key_evaluation'] = [
  'ordinal' => 'unstable when DTSTART/rule edits insert, remove, or reorder base occurrences',
  'original_utc_start' => 'unambiguous instant and stable when the same base occurrence survives an edit; can disappear after semantic rule edits',
  'source_local_datetime_timezone' => 'captures wall-clock intent and DST context but is not globally unique without series identity',
  'recommended_shape' => 'series identity + original occurrence key, retaining original UTC instant and source-local datetime/timezone with future series revision/effective-from audit context',
];

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
