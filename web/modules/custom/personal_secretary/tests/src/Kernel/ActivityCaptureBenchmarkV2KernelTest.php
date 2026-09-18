<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use DateTimeImmutable;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\personal_secretary\Entity\Household;
use Drupal\personal_secretary\Entity\Person;
use Drupal\personal_secretary\Service\ActivityCaptureInterpreter;
use Drupal\personal_secretary\Service\ActivityCaptureResolver;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\personal_secretary\Value\ActivityCaptureExtraction;
use Drupal\personal_secretary\Value\ActivityCaptureInput;
use Drupal\personal_secretary\Value\ActivityCaptureProposal;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * BENCHMARK_V2 harness for the materialized extraction/resolver boundary.
 *
 * The normal CI path validates the frozen fixture and deterministic resolver
 * oracle. Real LOCAL_ONLY inference runs only when explicitly enabled.
 */
#[RunTestsInSeparateProcesses]
#[Group('personal_secretary')]
final class ActivityCaptureBenchmarkV2KernelTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'key',
    'ai',
    'ai_provider_lmstudio',
    'datetime',
    'datetime_range',
    'date_recur',
    'phpmailer_smtp',
    'personal_secretary',
  ];

  private const EXPECTED_IDS = [
    'one_off_timed',
    'weekly_timed',
    'all_day_one_off',
    'explicit_location',
    'concerned_person_text',
    'self_responsibility',
    'text_responsibility',
    'relative_near_midnight',
    'ambiguous_person',
    'unsupported_complex_recurrence',
    'underspecified',
  ];

  private string $roleId = 'activity_capture_benchmark_v2';

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('personal_secretary_person');
    $this->installEntitySchema('personal_secretary_household');
    $this->installEntitySchema('personal_sec_activity_series');
    $this->installConfig(['system', 'user', 'ai', 'ai_provider_lmstudio']);

    Role::create([
      'id' => $this->roleId,
      'label' => 'Activity capture benchmark V2',
    ])->grantPermission(HouseholdAuthorizationService::PRODUCT_USE_PERMISSION)->save();

    $this->installUserReferenceField(
      CurrentPersonResolver::FIELD_NAME,
      'personal_secretary_person',
      1,
      'Personal Secretary person',
    );
    $this->installUserReferenceField(
      HouseholdAuthorizationService::FIELD_NAME,
      'personal_secretary_household',
      FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
      'Personal Secretary households',
    );

    // Prevent uid=1 bypass semantics in the synthetic resolver account.
    $inert = $this->container->get('entity_type.manager')->getStorage('user')->create([
      'name' => 'activity-capture-benchmark-v2-inert',
      'status' => 0,
    ]);
    $inert->save();
  }

  /**
   * Proves frozen V2 expectations against the real deterministic resolver.
   */
  public function testFixtureContractAndOracleResolver(): void {
    $fixture = $this->fixture();
    self::assertSame(self::EXPECTED_IDS, array_column($fixture, 'id'));

    foreach ($fixture as $case) {
      self::assertCount(15, $case['ai_expected'], $case['id'] . ' must assert every AI-owned extraction field.');
      self::assertCount(15, $case['resolver_input'], $case['id'] . ' must provide a complete oracle extraction.');
    }

    $this->prepareSyntheticResolverScope();
    $before = $this->activityCount();
    $correct = 0;
    $results = [];

    foreach ($fixture as $case) {
      $proposal = $this->resolver()->resolve(
        $this->input($case),
        ActivityCaptureExtraction::fromArray($case['resolver_input']),
      );
      $actual = $this->finalView($proposal);
      $fieldResults = $this->compareFields($actual, $case['final_expected']);
      $pass = $this->allPass($fieldResults);
      $correct += $pass ? 1 : 0;
      $results[$case['id']] = $pass;
    }

    self::assertSame($before, $this->activityCount(), 'Oracle resolution must not create ActivitySeries.');
    self::assertSame(11, $correct, json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

  }

  /**
   * Executes the one authorized LOCAL_ONLY Ministral V2 tranche.
   */
  public function testLiveV2Benchmark(): void {
    if (getenv('PS_ACTIVITY_CAPTURE_BENCHMARK_V2_LIVE') !== '1') {
      self::markTestSkipped('Real LOCAL_ONLY BENCHMARK_V2 inference is explicitly gated.');
    }

    $providerId = trim((string) getenv('PS_ACTIVITY_CAPTURE_PROVIDER'));
    $modelId = trim((string) getenv('PS_ACTIVITY_CAPTURE_MODEL'));
    $host = rtrim(trim((string) getenv('PS_ACTIVITY_CAPTURE_BENCHMARK_HOST')), '/');
    $port = (int) getenv('PS_ACTIVITY_CAPTURE_BENCHMARK_PORT');
    $modelLoadSeconds = (float) getenv('PS_ACTIVITY_CAPTURE_MODEL_LOAD_SECONDS');
    $hostParts = parse_url($host);
    $runtimeHost = is_array($hostParts) ? (string) ($hostParts['host'] ?? '') : '';
    $privateIp = filter_var($runtimeHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== FALSE
      && filter_var($runtimeHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === FALSE;
    $boundedHost = $runtimeHost === 'host.docker.internal' || $privateIp;
    if (
      $providerId !== 'lmstudio'
      || $modelId === ''
      || !is_array($hostParts)
      || ($hostParts['scheme'] ?? NULL) !== 'http'
      || !$boundedHost
      || $port !== 1234
    ) {
      self::fail('BENCHMARK_V2 live runtime variables do not match the bounded LOCAL_ONLY topology.');
    }

    $this->container->get('config.factory')->getEditable('ai.settings')
      ->set('request_timeout', 120)
      ->save();
    $this->container->get('config.factory')->getEditable('ai_provider_lmstudio.settings')
      ->set('host_name', $host)
      ->set('port', $port)
      ->save();

    $manager = $this->container->get('ai.provider');
    $provider = $manager->createInstance($providerId);
    $models = $provider->getConfiguredModels('chat');
    self::assertArrayHasKey($modelId, $models, 'Exact Ministral runtime identifier must be discoverable before inference.');

    $fixture = $this->fixture();
    $interpreter = new ActivityCaptureInterpreter($manager, $providerId, $modelId);

    // STAGE A: real local AI only. No Person/Household/ActivitySeries exist yet.
    $stageABefore = $this->domainCounts();
    $stageA = [];
    $aiCorrect = 0;
    $aiExpected = 0;
    $schemaValidCases = 0;
    $internalIdCases = 0;
    $timeouts = 0;

    foreach ($fixture as $case) {
      $started = microtime(TRUE);
      $schemaValid = FALSE;
      $internalId = FALSE;
      $actualExtraction = NULL;
      $error = NULL;

      try {
        $extraction = $interpreter->interpret($this->input($case));
        $actualExtraction = $extraction->toArray();
        $schemaValid = TRUE;
        $internalId = $extraction->containsInternalIdentity();
      }
      catch (\Throwable $e) {
        $error = get_class($e) . ': ' . $this->sanitizeError($e->getMessage());
        if (str_contains(strtolower($e->getMessage()), 'internal identity')) {
          $internalId = TRUE;
        }
        if (str_contains(strtolower($e->getMessage()), 'timed out') || str_contains(strtolower($e->getMessage()), 'curl error 28')) {
          ++$timeouts;
        }
      }

      $latency = microtime(TRUE) - $started;
      $fieldResults = $this->compareFields(
        is_array($actualExtraction) ? $actualExtraction : [],
        $case['ai_expected'],
      );
      $caseCorrect = count(array_filter($fieldResults, static fn(array $r): bool => $r['pass']));
      $aiCorrect += $caseCorrect;
      $aiExpected += count($fieldResults);
      $schemaValidCases += $schemaValid ? 1 : 0;
      $internalIdCases += $internalId ? 1 : 0;

      $stageA[$case['id']] = [
        'schema_valid' => $schemaValid,
        'field_results' => $fieldResults,
        'field_accuracy' => count($fieldResults) === 0 ? 0.0 : $caseCorrect / count($fieldResults),
        'internal_id_invention' => $internalId,
        'attempt_count' => ActivityCaptureInterpreter::PRIMARY_AI_ATTEMPTS,
        'latency_seconds' => $latency,
        'extraction' => $actualExtraction,
        'error' => $error,
      ];
    }

    $stageAAfter = $this->domainCounts();
    self::assertSame($stageABefore, $stageAAfter, 'Real AI Stage A must not mutate synthetic domain entities.');

    // STAGE B: isolated deterministic resolution with synthetic domain state.
    $this->prepareSyntheticResolverScope();
    $activityBefore = $this->activityCount();
    $oracleCorrect = 0;
    $finalCorrect = 0;
    $unsafeConfirmableWrong = 0;
    $actualClarifications = 0;
    $expectedClarifications = 0;
    $avoidableFallback = 0;
    $endToEndLatencies = [];
    $caseResults = [];

    foreach ($fixture as $case) {
      $oracleProposal = $this->resolver()->resolve(
        $this->input($case),
        ActivityCaptureExtraction::fromArray($case['resolver_input']),
      );
      $oracleFields = $this->compareFields($this->finalView($oracleProposal), $case['final_expected']);
      $oraclePass = $this->allPass($oracleFields);
      $oracleCorrect += $oraclePass ? 1 : 0;

      $finalActual = NULL;
      $finalFields = [];
      $resolverLatency = 0.0;
      if ($stageA[$case['id']]['schema_valid'] && is_array($stageA[$case['id']]['extraction'])) {
        $resolverStarted = microtime(TRUE);
        $proposal = $this->resolver()->resolve(
          $this->input($case),
          ActivityCaptureExtraction::fromArray($stageA[$case['id']]['extraction']),
        );
        $resolverLatency = microtime(TRUE) - $resolverStarted;
        $finalActual = $this->finalView($proposal);
        $finalFields = $this->compareFields($finalActual, $case['final_expected']);
      }
      else {
        $finalFields = $this->compareFields([], $case['final_expected']);
      }

      $finalPass = $this->allPass($finalFields);
      $finalCorrect += $finalPass ? 1 : 0;
      $expectedOutcome = (string) $case['final_expected']['outcome'];
      $actualOutcome = is_array($finalActual) ? (string) ($finalActual['outcome'] ?? 'ERROR') : 'ERROR';
      if ($expectedOutcome === 'CLARIFICATION') {
        ++$expectedClarifications;
      }
      if ($actualOutcome === 'CLARIFICATION') {
        ++$actualClarifications;
      }
      if ($expectedOutcome === 'PROPOSAL' && $actualOutcome !== 'PROPOSAL') {
        ++$avoidableFallback;
      }
      if (is_array($finalActual) && ($finalActual['ready_for_confirmation'] ?? FALSE) === TRUE && !$finalPass) {
        ++$unsafeConfirmableWrong;
      }

      $endToEnd = $stageA[$case['id']]['latency_seconds'] + $resolverLatency;
      $endToEndLatencies[] = $endToEnd;

      $caseResult = [
        'id' => $case['id'],
        'category' => $case['category'],
        'ai_extraction_schema_valid' => $stageA[$case['id']]['schema_valid'],
        'ai_extraction_field_results' => $stageA[$case['id']]['field_results'],
        'ai_extraction_accuracy' => round($stageA[$case['id']]['field_accuracy'], 6),
        'internal_id_invention' => $stageA[$case['id']]['internal_id_invention'],
        'attempt_count' => $stageA[$case['id']]['attempt_count'],
        'ai_latency_seconds' => round($stageA[$case['id']]['latency_seconds'], 3),
        'resolver_latency_seconds' => round($resolverLatency, 6),
        'end_to_end_latency_seconds' => round($endToEnd, 3),
        'error' => $stageA[$case['id']]['error'],
        'temporal_resolver_correctness' => $this->fieldGroupPass($finalFields, [
          'time_mode', 'absolute_date', 'local_start_time', 'local_end_time', 'source_timezone',
        ]),
        'person_resolution_outcome' => $this->fieldGroupPass($finalFields, [
          'household_selected', 'concerned_person_labels',
        ]),
        'recurrence_normalization_outcome' => $this->fieldGroupPass($finalFields, [
          'intent', 'weekday',
        ]),
        'unsupported_fail_closed_outcome' => $case['id'] === 'unsupported_complex_recurrence'
          ? $this->requiredClarificationPresent($finalActual, ActivityCaptureResolver::CLARIFICATION_UNSUPPORTED_RECURRENCE)
          : TRUE,
        'responsibility_mapping_outcome' => $this->fieldGroupPass($finalFields, [
          'responsible_person_label',
        ]),
        'expected_final_outcome' => $expectedOutcome,
        'actual_final_outcome' => $actualOutcome,
        'final_review_proposal_correctness' => $finalPass,
        'ready_for_confirmation' => is_array($finalActual) ? (bool) ($finalActual['ready_for_confirmation'] ?? FALSE) : FALSE,
        'clarification_codes' => is_array($finalActual) ? ($finalActual['clarifications'] ?? []) : [],
        'unsafe_confirmable_wrong_proposal' => is_array($finalActual)
          && ($finalActual['ready_for_confirmation'] ?? FALSE) === TRUE
          && !$finalPass,
        'oracle_resolver_correct' => $oraclePass,
      ];
      $caseResults[] = $caseResult;
    }

    self::assertSame($activityBefore, $this->activityCount(), 'Stage B resolution must not create ActivitySeries.');

    $latencyMin = min($endToEndLatencies);
    $latencyMax = max($endToEndLatencies);
    $latencyMean = array_sum($endToEndLatencies) / count($endToEndLatencies);
    $latencyP50 = $this->percentile($endToEndLatencies, 0.50);
    $latencyP95 = $this->percentile($endToEndLatencies, 0.95);
    $aiAccuracy = $aiExpected === 0 ? 0.0 : $aiCorrect / $aiExpected;

    $caseById = [];
    foreach ($caseResults as $result) {
      $caseById[$result['id']] = $result;
    }
    $mandatory = [
      'ambiguous_person_final_outcome' =>
        ($caseById['ambiguous_person']['actual_final_outcome'] ?? '') === 'CLARIFICATION'
        && in_array(ActivityCaptureResolver::CLARIFICATION_PERSON_ALTERNATIVE, $caseById['ambiguous_person']['clarification_codes'] ?? [], TRUE),
      'unsupported_complex_recurrence_final_outcome' =>
        ($caseById['unsupported_complex_recurrence']['actual_final_outcome'] ?? '') === 'CLARIFICATION'
        && in_array(ActivityCaptureResolver::CLARIFICATION_UNSUPPORTED_RECURRENCE, $caseById['unsupported_complex_recurrence']['clarification_codes'] ?? [], TRUE),
      'underspecified_final_outcome' =>
        ($caseById['underspecified']['actual_final_outcome'] ?? '') === 'CLARIFICATION'
        && ($caseById['underspecified']['final_review_proposal_correctness'] ?? FALSE),
      'relative_near_midnight' =>
        ($caseById['relative_near_midnight']['final_review_proposal_correctness'] ?? FALSE),
      'weekly_timed' =>
        ($caseById['weekly_timed']['final_review_proposal_correctness'] ?? FALSE),
      'self_responsibility' =>
        ($caseById['self_responsibility']['final_review_proposal_correctness'] ?? FALSE),
      'text_responsibility' =>
        ($caseById['text_responsibility']['final_review_proposal_correctness'] ?? FALSE),
    ];

    $architecturePass = $oracleCorrect === 11;
    $semanticPass = $architecturePass
      && $schemaValidCases === 11
      && $aiAccuracy >= 0.90
      && $internalIdCases === 0
      && $finalCorrect === 11
      && $unsafeConfirmableWrong === 0
      && $avoidableFallback === 0
      && !in_array(FALSE, $mandatory, TRUE);
    $latencyPass = $latencyP95 <= 10.0 && $timeouts === 0;

    $summary = [
      'cases' => 11,
      'model_id' => $modelId,
      'model_load_time_seconds' => round($modelLoadSeconds, 3),
      'ai_extraction_schema_valid_cases' => $schemaValidCases,
      'ai_extraction_schema_validity_rate' => $schemaValidCases / 11,
      'ai_extraction_correct_fields' => $aiCorrect,
      'ai_extraction_asserted_fields' => $aiExpected,
      'ai_extraction_accuracy' => round($aiAccuracy, 6),
      'internal_id_invention_cases' => $internalIdCases,
      'oracle_resolver_correct_cases' => $oracleCorrect,
      'final_product_outcome_correct_cases' => $finalCorrect,
      'expected_clarification_cases' => $expectedClarifications,
      'actual_clarification_cases' => $actualClarifications,
      'avoidable_fallback_cases' => $avoidableFallback,
      'unsafe_confirmable_wrong_proposals' => $unsafeConfirmableWrong,
      'isolated_stage_a_domain_counts_before' => $stageABefore,
      'isolated_stage_a_domain_counts_after' => $stageAAfter,
      'isolated_resolver_activity_mutation' => FALSE,
      'latency_min_seconds' => round($latencyMin, 3),
      'latency_mean_seconds' => round($latencyMean, 3),
      'latency_p50_seconds' => round($latencyP50, 3),
      'latency_p95_seconds' => round($latencyP95, 3),
      'latency_max_seconds' => round($latencyMax, 3),
      'timeouts' => $timeouts,
      'mandatory_safety_cases' => $mandatory,
      'architecture_b_product_semantics' => $architecturePass ? 'PASS' : 'FAIL',
      'ministral_v2_semantics' => $semanticPass ? 'PASS' : 'FAIL',
      'synchronous_default_latency' => $latencyPass ? 'PASS' : 'FAIL',
      'product_posture' => $semanticPass
        ? ($latencyPass
          ? 'SYNCHRONOUS_DEFAULT_PREFILL_ELIGIBLE'
          : 'OPTIONAL_NONBLOCKING_AI_PREFILL_WITH_IMMEDIATE_STRUCTURED_MANUAL_CAPTURE')
        : 'MANUAL_STRUCTURED_CAPTURE_PRIMARY_MINISTRAL_NOT_QUALIFIED',
      'primary_ai_attempts' => ActivityCaptureInterpreter::PRIMARY_AI_ATTEMPTS,
      'max_total_attempts' => ActivityCaptureInterpreter::MAX_TOTAL_ATTEMPTS,
    ];
    $resultPath = trim((string) getenv('PS_ACTIVITY_CAPTURE_BENCHMARK_RESULT'));
    if (!str_starts_with($resultPath, '/tmp/') || str_contains($resultPath, '..')) {
      self::fail('BENCHMARK_V2 result path must be a bounded /tmp path outside Git.');
    }
    $payload = [
      'cases' => $caseResults,
      'summary' => $summary,
    ];
    $encoded = json_encode(
      $payload,
      JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
    file_put_contents($resultPath, $encoded . PHP_EOL, LOCK_EX);
    self::assertFileExists($resultPath);
  }

  /**
   * Scores one already-executed OpenAI V2 tranche through the real resolver.
   */
  public function testOpenAiV2Benchmark(): void {
    $rawPath = trim((string) getenv('PS_ACTIVITY_CAPTURE_OPENAI_RAW_RESULT'));
    if ($rawPath === '') {
      self::markTestSkipped('OpenAI BENCHMARK_V2 raw evidence is not supplied.');
    }
    if (!str_starts_with($rawPath, '/tmp/') || str_contains($rawPath, '..')) {
      self::fail('OpenAI BENCHMARK_V2 raw result path must be a bounded /tmp path.');
    }

    $resultPath = trim((string) getenv('PS_ACTIVITY_CAPTURE_BENCHMARK_RESULT'));
    if (!str_starts_with($resultPath, '/tmp/') || str_contains($resultPath, '..')) {
      self::fail('BENCHMARK_V2 result path must be a bounded /tmp path outside Git.');
    }

    $modelId = trim((string) getenv('PS_ACTIVITY_CAPTURE_OPENAI_MODEL'));
    if (!in_array($modelId, ['gpt-5.6-sol', 'gpt-5.6-terra'], TRUE)) {
      self::fail('OpenAI BENCHMARK_V2 model must be one of the explicitly authorized model identifiers.');
    }

    $raw = json_decode((string) file_get_contents($rawPath), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertIsArray($raw);
    self::assertSame('openai', $raw['provider'] ?? NULL);
    self::assertSame($modelId, $raw['model_id'] ?? NULL);
    self::assertSame(11, $raw['request_count'] ?? NULL);
    self::assertFalse((bool) ($raw['semantic_retry'] ?? TRUE));
    self::assertIsArray($raw['cases'] ?? NULL);

    $fixture = $this->fixture();
    self::assertSame(self::EXPECTED_IDS, array_column($fixture, 'id'));
    self::assertSame(self::EXPECTED_IDS, array_column($raw['cases'], 'id'));

    $stageABefore = $this->domainCounts();
    $stageA = [];
    $aiCorrect = 0;
    $aiExpected = 0;
    $schemaValidCases = 0;
    $internalIdCases = 0;
    $timeouts = 0;
    $inputTokens = 0;
    $cachedInputTokens = 0;
    $outputTokens = 0;

    foreach ($fixture as $index => $case) {
      $rawCase = $raw['cases'][$index];
      $rawExtraction = is_array($rawCase['extraction'] ?? NULL) ? $rawCase['extraction'] : NULL;
      $internalId = $rawExtraction !== NULL && $this->containsInternalIdentityRaw($rawExtraction);
      $schemaValid = FALSE;
      $normalizedExtraction = NULL;
      $error = is_string($rawCase['error_class'] ?? NULL) ? $rawCase['error_class'] : NULL;

      if ($rawExtraction !== NULL) {
        try {
          $extraction = ActivityCaptureExtraction::fromArray($rawExtraction);
          $normalizedExtraction = $extraction->toArray();
          $schemaValid = TRUE;
          $internalId = $internalId || $extraction->containsInternalIdentity();
        }
        catch (\Throwable $e) {
          $error ??= get_class($e) . ': ' . $this->sanitizeError($e->getMessage());
        }
      }

      $fieldResults = $this->compareFields(
        is_array($normalizedExtraction) ? $normalizedExtraction : [],
        $case['ai_expected'],
      );
      $caseCorrect = count(array_filter($fieldResults, static fn(array $r): bool => $r['pass']));
      $aiCorrect += $caseCorrect;
      $aiExpected += count($fieldResults);
      $schemaValidCases += $schemaValid ? 1 : 0;
      $internalIdCases += $internalId ? 1 : 0;

      $errorClass = (string) ($rawCase['error_class'] ?? '');
      if (str_contains(strtoupper($errorClass), 'TIMEOUT')) {
        ++$timeouts;
      }

      $caseInputTokens = (int) ($rawCase['input_tokens'] ?? 0);
      $caseCachedInputTokens = (int) ($rawCase['cached_input_tokens'] ?? 0);
      $caseOutputTokens = (int) ($rawCase['output_tokens'] ?? 0);
      $inputTokens += $caseInputTokens;
      $cachedInputTokens += $caseCachedInputTokens;
      $outputTokens += $caseOutputTokens;

      $stageA[$case['id']] = [
        'schema_valid' => $schemaValid,
        'field_results' => $fieldResults,
        'field_accuracy' => count($fieldResults) === 0 ? 0.0 : $caseCorrect / count($fieldResults),
        'internal_id_invention' => $internalId,
        'attempt_count' => 1,
        'latency_seconds' => (float) ($rawCase['ai_latency_seconds'] ?? 0.0),
        'extraction' => $normalizedExtraction,
        'error' => $error,
        'response_id' => is_string($rawCase['response_id'] ?? NULL) ? $rawCase['response_id'] : NULL,
        'response_status' => is_string($rawCase['response_status'] ?? NULL) ? $rawCase['response_status'] : NULL,
        'input_tokens' => $caseInputTokens,
        'cached_input_tokens' => $caseCachedInputTokens,
        'output_tokens' => $caseOutputTokens,
      ];
    }

    $stageAAfter = $this->domainCounts();
    self::assertSame($stageABefore, $stageAAfter, 'OpenAI Stage A evidence consumption must not mutate synthetic domain entities.');

    $this->prepareSyntheticResolverScope();
    $activityBefore = $this->activityCount();
    $oracleCorrect = 0;
    $finalCorrect = 0;
    $unsafeConfirmableWrong = 0;
    $actualClarifications = 0;
    $expectedClarifications = 0;
    $avoidableFallback = 0;
    $endToEndLatencies = [];
    $caseResults = [];

    foreach ($fixture as $case) {
      $oracleProposal = $this->resolver()->resolve(
        $this->input($case),
        ActivityCaptureExtraction::fromArray($case['resolver_input']),
      );
      $oracleFields = $this->compareFields($this->finalView($oracleProposal), $case['final_expected']);
      $oraclePass = $this->allPass($oracleFields);
      $oracleCorrect += $oraclePass ? 1 : 0;

      $finalActual = NULL;
      $finalFields = [];
      $resolverLatency = 0.0;
      if ($stageA[$case['id']]['schema_valid'] && is_array($stageA[$case['id']]['extraction'])) {
        $resolverStarted = microtime(TRUE);
        $proposal = $this->resolver()->resolve(
          $this->input($case),
          ActivityCaptureExtraction::fromArray($stageA[$case['id']]['extraction']),
        );
        $resolverLatency = microtime(TRUE) - $resolverStarted;
        $finalActual = $this->finalView($proposal);
        $finalFields = $this->compareFields($finalActual, $case['final_expected']);
      }
      else {
        $finalFields = $this->compareFields([], $case['final_expected']);
      }

      $finalPass = $this->allPass($finalFields);
      $finalCorrect += $finalPass ? 1 : 0;
      $expectedOutcome = (string) $case['final_expected']['outcome'];
      $actualOutcome = is_array($finalActual) ? (string) ($finalActual['outcome'] ?? 'ERROR') : 'ERROR';
      if ($expectedOutcome === 'CLARIFICATION') {
        ++$expectedClarifications;
      }
      if ($actualOutcome === 'CLARIFICATION') {
        ++$actualClarifications;
      }
      if ($expectedOutcome === 'PROPOSAL' && $actualOutcome !== 'PROPOSAL') {
        ++$avoidableFallback;
      }
      if (is_array($finalActual) && ($finalActual['ready_for_confirmation'] ?? FALSE) === TRUE && !$finalPass) {
        ++$unsafeConfirmableWrong;
      }

      $endToEnd = $stageA[$case['id']]['latency_seconds'] + $resolverLatency;
      $endToEndLatencies[] = $endToEnd;

      $caseResults[] = [
        'id' => $case['id'],
        'category' => $case['category'],
        'ai_extraction_schema_valid' => $stageA[$case['id']]['schema_valid'],
        'ai_extraction_field_results' => $stageA[$case['id']]['field_results'],
        'ai_extraction_accuracy' => round($stageA[$case['id']]['field_accuracy'], 6),
        'internal_id_invention' => $stageA[$case['id']]['internal_id_invention'],
        'attempt_count' => 1,
        'response_id' => $stageA[$case['id']]['response_id'],
        'response_status' => $stageA[$case['id']]['response_status'],
        'input_tokens' => $stageA[$case['id']]['input_tokens'],
        'cached_input_tokens' => $stageA[$case['id']]['cached_input_tokens'],
        'output_tokens' => $stageA[$case['id']]['output_tokens'],
        'ai_latency_seconds' => round($stageA[$case['id']]['latency_seconds'], 3),
        'resolver_latency_seconds' => round($resolverLatency, 6),
        'end_to_end_latency_seconds' => round($endToEnd, 3),
        'error' => $stageA[$case['id']]['error'],
        'temporal_resolver_correctness' => $this->fieldGroupPass($finalFields, [
          'time_mode', 'absolute_date', 'local_start_time', 'local_end_time', 'source_timezone',
        ]),
        'person_resolution_outcome' => $this->fieldGroupPass($finalFields, [
          'household_selected', 'concerned_person_labels',
        ]),
        'recurrence_normalization_outcome' => $this->fieldGroupPass($finalFields, [
          'intent', 'weekday',
        ]),
        'unsupported_fail_closed_outcome' => $case['id'] === 'unsupported_complex_recurrence'
          ? $this->requiredClarificationPresent($finalActual, ActivityCaptureResolver::CLARIFICATION_UNSUPPORTED_RECURRENCE)
          : TRUE,
        'responsibility_mapping_outcome' => $this->fieldGroupPass($finalFields, [
          'responsible_person_label',
        ]),
        'expected_final_outcome' => $expectedOutcome,
        'actual_final_outcome' => $actualOutcome,
        'final_review_proposal_correctness' => $finalPass,
        'ready_for_confirmation' => is_array($finalActual) ? (bool) ($finalActual['ready_for_confirmation'] ?? FALSE) : FALSE,
        'clarification_codes' => is_array($finalActual) ? ($finalActual['clarifications'] ?? []) : [],
        'unsafe_confirmable_wrong_proposal' => is_array($finalActual)
          && ($finalActual['ready_for_confirmation'] ?? FALSE) === TRUE
          && !$finalPass,
        'oracle_resolver_correct' => $oraclePass,
      ];
    }

    self::assertSame($activityBefore, $this->activityCount(), 'OpenAI Stage B resolution must not create ActivitySeries.');

    $latencyMin = min($endToEndLatencies);
    $latencyMax = max($endToEndLatencies);
    $latencyMean = array_sum($endToEndLatencies) / count($endToEndLatencies);
    $latencyP50 = $this->percentile($endToEndLatencies, 0.50);
    $latencyP95 = $this->percentile($endToEndLatencies, 0.95);
    $aiAccuracy = $aiExpected === 0 ? 0.0 : $aiCorrect / $aiExpected;

    $caseById = [];
    foreach ($caseResults as $result) {
      $caseById[$result['id']] = $result;
    }
    $mandatory = [
      'ambiguous_person_final_outcome' =>
        ($caseById['ambiguous_person']['actual_final_outcome'] ?? '') === 'CLARIFICATION'
        && in_array(ActivityCaptureResolver::CLARIFICATION_PERSON_ALTERNATIVE, $caseById['ambiguous_person']['clarification_codes'] ?? [], TRUE),
      'unsupported_complex_recurrence_final_outcome' =>
        ($caseById['unsupported_complex_recurrence']['actual_final_outcome'] ?? '') === 'CLARIFICATION'
        && in_array(ActivityCaptureResolver::CLARIFICATION_UNSUPPORTED_RECURRENCE, $caseById['unsupported_complex_recurrence']['clarification_codes'] ?? [], TRUE),
      'underspecified_final_outcome' =>
        ($caseById['underspecified']['actual_final_outcome'] ?? '') === 'CLARIFICATION'
        && ($caseById['underspecified']['final_review_proposal_correctness'] ?? FALSE),
      'relative_near_midnight' =>
        ($caseById['relative_near_midnight']['final_review_proposal_correctness'] ?? FALSE),
      'weekly_timed' =>
        ($caseById['weekly_timed']['final_review_proposal_correctness'] ?? FALSE),
      'self_responsibility' =>
        ($caseById['self_responsibility']['final_review_proposal_correctness'] ?? FALSE),
      'text_responsibility' =>
        ($caseById['text_responsibility']['final_review_proposal_correctness'] ?? FALSE),
    ];

    $architecturePass = $oracleCorrect === 11;
    $semanticPass = $architecturePass
      && $schemaValidCases === 11
      && $aiAccuracy >= 0.90
      && $internalIdCases === 0
      && $finalCorrect === 11
      && $unsafeConfirmableWrong === 0
      && $avoidableFallback === 0
      && !in_array(FALSE, $mandatory, TRUE);
    $latencyPass = $latencyP95 <= 10.0 && $timeouts === 0;

    $summary = [
      'cases' => 11,
      'provider' => 'openai',
      'model_id' => $modelId,
      'request_count' => 11,
      'semantic_retry' => FALSE,
      'ai_extraction_schema_valid_cases' => $schemaValidCases,
      'ai_extraction_schema_validity_rate' => $schemaValidCases / 11,
      'ai_extraction_correct_fields' => $aiCorrect,
      'ai_extraction_asserted_fields' => $aiExpected,
      'ai_extraction_accuracy' => round($aiAccuracy, 6),
      'internal_id_invention_cases' => $internalIdCases,
      'oracle_resolver_correct_cases' => $oracleCorrect,
      'final_product_outcome_correct_cases' => $finalCorrect,
      'expected_clarification_cases' => $expectedClarifications,
      'actual_clarification_cases' => $actualClarifications,
      'avoidable_fallback_cases' => $avoidableFallback,
      'unsafe_confirmable_wrong_proposals' => $unsafeConfirmableWrong,
      'isolated_stage_a_domain_counts_before' => $stageABefore,
      'isolated_stage_a_domain_counts_after' => $stageAAfter,
      'isolated_resolver_activity_mutation' => FALSE,
      'latency_min_seconds' => round($latencyMin, 3),
      'latency_mean_seconds' => round($latencyMean, 3),
      'latency_p50_seconds' => round($latencyP50, 3),
      'latency_p95_seconds' => round($latencyP95, 3),
      'latency_max_seconds' => round($latencyMax, 3),
      'timeouts' => $timeouts,
      'input_tokens' => $inputTokens,
      'cached_input_tokens' => $cachedInputTokens,
      'output_tokens' => $outputTokens,
      'mandatory_safety_cases' => $mandatory,
      'architecture_b_product_semantics' => $architecturePass ? 'PASS' : 'FAIL',
      'semantic_verdict' => $semanticPass ? 'PASS' : 'FAIL',
      'synchronous_latency' => $latencyPass ? 'PASS' : 'FAIL',
      'product_posture' => $semanticPass
        ? ($latencyPass
          ? 'SYNCHRONOUS_DEFAULT_PREFILL_ELIGIBLE'
          : 'OPTIONAL_NONBLOCKING_AI_PREFILL_WITH_IMMEDIATE_STRUCTURED_MANUAL_CAPTURE')
        : 'MANUAL_STRUCTURED_CAPTURE_PRIMARY_PROVIDER_NOT_QUALIFIED',
    ];

    $payload = [
      'cases' => $caseResults,
      'summary' => $summary,
    ];
    $encoded = json_encode(
      $payload,
      JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
    file_put_contents($resultPath, $encoded . PHP_EOL, LOCK_EX);
    self::assertFileExists($resultPath);
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  private function fixture(): array {
    $path = dirname(__DIR__, 2) . '/fixtures/activity_capture_benchmark_v2.json';
    $fixture = json_decode((string) file_get_contents($path), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertIsArray($fixture);
    self::assertCount(11, $fixture);
    return $fixture;
  }

  private function input(array $case): ActivityCaptureInput {
    return new ActivityCaptureInput(
      (string) $case['text'],
      new DateTimeImmutable((string) $case['context_utc']),
      (string) $case['timezone'],
    );
  }

  private function resolver(): ActivityCaptureResolver {
    $resolver = $this->container->get('personal_secretary.activity_capture_resolver');
    self::assertInstanceOf(ActivityCaptureResolver::class, $resolver);
    return $resolver;
  }

  /**
   * @return array<string, mixed>
   */
  private function finalView(ActivityCaptureProposal $proposal): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('personal_secretary_person');
    $concernedLabels = [];
    foreach ($proposal->concernedPersonIds as $id) {
      $person = $storage->load($id);
      if ($person instanceof Person) {
        $concernedLabels[] = (string) $person->label();
      }
    }
    sort($concernedLabels, SORT_NATURAL | SORT_FLAG_CASE);

    $responsibleLabel = NULL;
    if ($proposal->responsiblePersonId !== NULL) {
      $person = $storage->load($proposal->responsiblePersonId);
      if ($person instanceof Person) {
        $responsibleLabel = (string) $person->label();
      }
    }

    return [
      'outcome' => $proposal->readyForConfirmation() ? 'PROPOSAL' : 'CLARIFICATION',
      'household_selected' => $proposal->householdId !== NULL,
      'intent' => $proposal->intent,
      'label' => $proposal->label,
      'location' => $proposal->location,
      'time_mode' => $proposal->timeMode,
      'absolute_date' => $proposal->absoluteDate,
      'local_start_time' => $proposal->localStartTime,
      'local_end_time' => $proposal->localEndTime,
      'source_timezone' => $proposal->sourceTimezone,
      'weekday' => $proposal->weekday,
      'concerned_person_labels' => $concernedLabels,
      'responsible_person_label' => $responsibleLabel,
      'clarifications' => $proposal->clarifications,
      'ready_for_confirmation' => $proposal->readyForConfirmation(),
    ];
  }

  /**
   * @return array<string, array{expected:mixed,actual:mixed,pass:bool}>
   */
  private function compareFields(array $actual, array $expected): array {
    $results = [];
    foreach ($expected as $field => $expectedValue) {
      $actualValue = array_key_exists($field, $actual) ? $actual[$field] : '__MISSING__';
      $results[$field] = [
        'expected' => $expectedValue,
        'actual' => $actualValue,
        'pass' => $this->valueMatches($actualValue, $expectedValue),
      ];
    }
    return $results;
  }

  private function valueMatches(mixed $actual, mixed $expected): bool {
    if (is_array($expected) && array_key_exists('contains', $expected)) {
      return is_string($actual)
        && str_contains($this->normalizeText($actual), $this->normalizeText((string) $expected['contains']));
    }
    if (is_array($expected) && array_key_exists('one_of', $expected)) {
      foreach ($expected['one_of'] as $option) {
        if ($this->valueMatches($actual, $option)) {
          return TRUE;
        }
      }
      return FALSE;
    }
    if (is_array($expected) && array_key_exists('unordered', $expected)) {
      if (!is_array($actual)) {
        return FALSE;
      }
      $a = array_map(fn($v) => is_string($v) ? $this->normalizeText($v) : $v, array_values($actual));
      $e = array_map(fn($v) => is_string($v) ? $this->normalizeText($v) : $v, array_values($expected['unordered']));
      sort($a);
      sort($e);
      return $a === $e;
    }
    if (is_array($expected) && array_key_exists('contains_all', $expected)) {
      if (!is_array($actual)) {
        return FALSE;
      }
      $a = array_map(fn($v) => is_string($v) ? $this->normalizeText($v) : $v, array_values($actual));
      foreach ($expected['contains_all'] as $required) {
        $needle = is_string($required) ? $this->normalizeText($required) : $required;
        if (!in_array($needle, $a, TRUE)) {
          return FALSE;
        }
      }
      return TRUE;
    }
    if (is_string($actual) && is_string($expected)) {
      return $this->normalizeText($actual) === $this->normalizeText($expected);
    }
    return $actual === $expected;
  }

  private function normalizeText(string $value): string {
    $value = mb_strtolower(trim($value));
    return preg_replace('/\s+/u', ' ', $value) ?? $value;
  }

  private function allPass(array $results): bool {
    foreach ($results as $result) {
      if (!$result['pass']) {
        return FALSE;
      }
    }
    return TRUE;
  }

  private function fieldGroupPass(array $results, array $fields): bool {
    foreach ($fields as $field) {
      if (isset($results[$field]) && !$results[$field]['pass']) {
        return FALSE;
      }
    }
    return TRUE;
  }

  private function requiredClarificationPresent(?array $finalActual, string $code): bool {
    return is_array($finalActual)
      && ($finalActual['outcome'] ?? NULL) === 'CLARIFICATION'
      && in_array($code, $finalActual['clarifications'] ?? [], TRUE);
  }

  private function percentile(array $values, float $fraction): float {
    sort($values, SORT_NUMERIC);
    $rank = max(1, (int) ceil($fraction * count($values)));
    return (float) $values[$rank - 1];
  }

  private function containsInternalIdentityRaw(array $data): bool {
    $serialized = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($serialized)) {
      return TRUE;
    }
    return preg_match(
      '/\b(?:person_id|household_id|person_uuid|household_uuid|uuid)\b|[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i',
      $serialized,
    ) === 1;
  }

  private function sanitizeError(string $message): string {
    $message = preg_replace('/https?:\/\/[^\s]+/', '<local-endpoint>', $message) ?? $message;
    return preg_replace('/[A-Za-z0-9_-]{32,}/', '<redacted-runtime-token>', $message) ?? $message;
  }

  private function prepareSyntheticResolverScope(): void {
    $current = $this->person('Current Person');
    $alpha = $this->person('Personne Alpha');
    $beta = $this->person('Personne Bêta');
    $household = $this->household('Synthetic Benchmark Household', [$current, $alpha, $beta]);
    $this->setCurrentUser($this->productUser($current, [$household]));
  }

  private function person(string $name): Person {
    $person = $this->container->get('entity_type.manager')
      ->getStorage('personal_secretary_person')
      ->create(['name' => $name]);
    self::assertInstanceOf(Person::class, $person);
    $person->save();
    return $person;
  }

  /**
   * @param Person[] $people
   */
  private function household(string $name, array $people): Household {
    $household = $this->container->get('entity_type.manager')
      ->getStorage('personal_secretary_household')
      ->create([
        'name' => $name,
        'members' => array_map(
          static fn(Person $person): array => ['target_id' => (int) $person->id()],
          $people,
        ),
      ]);
    self::assertInstanceOf(Household::class, $household);
    $household->save();
    return $household;
  }

  /**
   * @param Household[] $households
   */
  private function productUser(Person $currentPerson, array $households): UserInterface {
    $user = $this->container->get('entity_type.manager')->getStorage('user')->create([
      'name' => 'activity-capture-benchmark-v2-user',
      'status' => 1,
      'roles' => [$this->roleId],
      CurrentPersonResolver::FIELD_NAME => ['target_id' => (int) $currentPerson->id()],
      HouseholdAuthorizationService::FIELD_NAME => array_map(
        static fn(Household $household): array => ['target_id' => (int) $household->id()],
        $households,
      ),
    ]);
    self::assertInstanceOf(UserInterface::class, $user);
    $user->save();
    return $user;
  }

  private function setCurrentUser(UserInterface $user): void {
    $this->container->get('current_user')->setAccount($user);
  }

  private function activityCount(): int {
    return (int) $this->container->get('entity_type.manager')
      ->getStorage('personal_sec_activity_series')
      ->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  /**
   * @return array{person:int,household:int,activity_series:int}
   */
  private function domainCounts(): array {
    $manager = $this->container->get('entity_type.manager');
    return [
      'person' => (int) $manager->getStorage('personal_secretary_person')->getQuery()->accessCheck(FALSE)->count()->execute(),
      'household' => (int) $manager->getStorage('personal_secretary_household')->getQuery()->accessCheck(FALSE)->count()->execute(),
      'activity_series' => (int) $manager->getStorage('personal_sec_activity_series')->getQuery()->accessCheck(FALSE)->count()->execute(),
    ];
  }

  private function installUserReferenceField(
    string $fieldName,
    string $targetType,
    int $cardinality,
    string $label,
  ): void {
    FieldStorageConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'user',
      'type' => 'entity_reference',
      'settings' => ['target_type' => $targetType],
      'cardinality' => $cardinality,
      'translatable' => FALSE,
    ])->save();
    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => $label,
      'required' => FALSE,
      'translatable' => FALSE,
      'settings' => [
        'handler' => 'default:' . $targetType,
        'handler_settings' => [],
      ],
    ])->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

}
