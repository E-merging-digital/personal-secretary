<?php

declare(strict_types=1);

use Drupal\personal_secretary\Service\ActivityCaptureInterpreter;
use Drupal\personal_secretary\Value\ActivityCaptureInput;

$providerId = trim((string) getenv('PS_ACTIVITY_CAPTURE_PROVIDER'));
$modelId = trim((string) getenv('PS_ACTIVITY_CAPTURE_MODEL'));
if ($providerId === '' || $modelId === '') {
  throw new RuntimeException('PS_ACTIVITY_CAPTURE_PROVIDER and PS_ACTIVITY_CAPTURE_MODEL are required.');
}

$fixturePath = dirname(__DIR__) . '/web/modules/custom/personal_secretary/tests/fixtures/activity_capture_benchmark.json';
$fixture = json_decode((string) file_get_contents($fixturePath), TRUE, 512, JSON_THROW_ON_ERROR);
if (!is_array($fixture) || count($fixture) < 11) {
  throw new RuntimeException('Activity capture benchmark fixture is incomplete.');
}

$manager = \Drupal::service('ai.provider');
$interpreter = new ActivityCaptureInterpreter($manager, $providerId, $modelId);
$entityTypeManager = \Drupal::entityTypeManager();
$countEntities = static function () use ($entityTypeManager): array {
  $result = [];
  foreach ([
    'person' => 'personal_secretary_person',
    'household' => 'personal_secretary_household',
    'activity_series' => 'personal_sec_activity_series',
  ] as $key => $entityTypeId) {
    $result[$key] = (int) $entityTypeManager->getStorage($entityTypeId)->getQuery()->accessCheck(FALSE)->count()->execute();
  }
  return $result;
};

$normalize = static function (mixed $value) use (&$normalize): mixed {
  if (is_string($value)) {
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
  }
  if (is_array($value)) {
    return array_map($normalize, $value);
  }
  return $value;
};

$beforeCounts = $countEntities();
$totalExpectedFields = 0;
$totalCorrectFields = 0;
$schemaValidCases = 0;
$internalIdCases = 0;
$manualFallbackCases = 0;
$ambiguityCasesExpected = 0;
$ambiguityCasesCorrect = 0;
$unsupportedCasesExpected = 0;
$unsupportedCasesCorrect = 0;
$latencies = [];
$results = [];

foreach ($fixture as $case) {
  $caseId = (string) ($case['id'] ?? 'unknown');
  $expected = is_array($case['expected'] ?? NULL) ? $case['expected'] : [];
  $started = microtime(TRUE);
  $attemptCount = ActivityCaptureInterpreter::PRIMARY_AI_ATTEMPTS;
  $schemaValid = FALSE;
  $internalId = FALSE;
  $actual = NULL;
  $error = NULL;

  try {
    $input = new ActivityCaptureInput(
      text: (string) $case['text'],
      contextInstantUtc: new DateTimeImmutable((string) $case['context_utc']),
      sourceTimezone: (string) $case['timezone'],
    );
    $proposal = $interpreter->interpret($input);
    $actual = $proposal->toArray();
    $schemaValid = TRUE;
    $internalId = $proposal->containsInternalIdentity();
  }
  catch (Throwable $e) {
    $error = get_class($e) . ': ' . preg_replace('/https?:\/\/[^\s]+/', '<local-endpoint>', $e->getMessage());
  }

  $latency = microtime(TRUE) - $started;
  $latencies[] = $latency;
  $fieldResults = [];
  $caseCorrect = 0;
  foreach ($expected as $field => $expectedValue) {
    ++$totalExpectedFields;
    $actualValue = is_array($actual) && array_key_exists($field, $actual) ? $actual[$field] : '__MISSING__';
    $correct = $normalize($actualValue) === $normalize($expectedValue);
    if ($correct) {
      ++$totalCorrectFields;
      ++$caseCorrect;
    }
    $fieldResults[$field] = [
      'expected' => $expectedValue,
      'actual' => $actualValue,
      'pass' => $correct,
    ];
  }

  if ($schemaValid) {
    ++$schemaValidCases;
  }
  if ($internalId) {
    ++$internalIdCases;
  }
  if (($expected['ambiguous'] ?? FALSE) === TRUE) {
    ++$ambiguityCasesExpected;
    if (($actual['ambiguous'] ?? NULL) === TRUE) {
      ++$ambiguityCasesCorrect;
    }
  }
  if (($expected['unsupported'] ?? FALSE) === TRUE) {
    ++$unsupportedCasesExpected;
    if (($actual['unsupported'] ?? NULL) === TRUE) {
      ++$unsupportedCasesCorrect;
    }
  }

  $expectedCount = count($expected);
  $semanticMismatch = $caseCorrect !== $expectedCount;
  $manualFallback = !$schemaValid
    || $internalId
    || (($actual['ambiguous'] ?? FALSE) === TRUE)
    || (($actual['unsupported'] ?? FALSE) === TRUE)
    || $semanticMismatch;
  if ($manualFallback) {
    ++$manualFallbackCases;
  }

  $result = [
    'id' => $caseId,
    'category' => (string) ($case['category'] ?? ''),
    'schema_valid' => $schemaValid,
    'field_results' => $fieldResults,
    'field_accuracy' => $expectedCount === 0 ? 1.0 : $caseCorrect / $expectedCount,
    'ambiguity_detected' => $actual['ambiguous'] ?? NULL,
    'unsupported_detected' => $actual['unsupported'] ?? NULL,
    'internal_id_invention' => $internalId,
    'latency_seconds' => round($latency, 3),
    'attempt_count' => $attemptCount,
    'manual_fallback' => $manualFallback,
    'actual' => $actual,
    'error' => $error,
  ];
  $results[] = $result;
  print 'BENCHMARK_CASE=' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

$afterCounts = $countEntities();
$domainMutation = $beforeCounts !== $afterCounts;
$caseCount = count($fixture);
$summary = [
  'cases' => $caseCount,
  'schema_valid_cases' => $schemaValidCases,
  'schema_validity_rate' => $caseCount === 0 ? 0 : $schemaValidCases / $caseCount,
  'field_accuracy' => $totalExpectedFields === 0 ? 0 : $totalCorrectFields / $totalExpectedFields,
  'ambiguity_expected' => $ambiguityCasesExpected,
  'ambiguity_correct' => $ambiguityCasesCorrect,
  'unsupported_expected' => $unsupportedCasesExpected,
  'unsupported_correct' => $unsupportedCasesCorrect,
  'internal_id_invention_cases' => $internalIdCases,
  'manual_fallback_cases' => $manualFallbackCases,
  'manual_fallback_rate' => $caseCount === 0 ? 0 : $manualFallbackCases / $caseCount,
  'latency_min_seconds' => $latencies === [] ? NULL : round(min($latencies), 3),
  'latency_max_seconds' => $latencies === [] ? NULL : round(max($latencies), 3),
  'latency_mean_seconds' => $latencies === [] ? NULL : round(array_sum($latencies) / count($latencies), 3),
  'domain_counts_before' => $beforeCounts,
  'domain_counts_after' => $afterCounts,
  'domain_mutation' => $domainMutation,
  'primary_ai_attempts' => ActivityCaptureInterpreter::PRIMARY_AI_ATTEMPTS,
  'max_total_attempts' => ActivityCaptureInterpreter::MAX_TOTAL_ATTEMPTS,
];

print 'BENCHMARK_SUMMARY=' . json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
if ($domainMutation) {
  throw new RuntimeException('Activity capture benchmark mutated domain entities.');
}
