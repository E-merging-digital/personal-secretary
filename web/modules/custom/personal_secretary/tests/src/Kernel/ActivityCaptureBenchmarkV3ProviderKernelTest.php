<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

require_once __DIR__ . '/ActivityCaptureBenchmarkV3KernelTest.php';

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
use ReflectionClass;
use ReflectionMethod;

/**
 * BENCHMARK_V3 provider execution/scoring support.
 *
 * This is benchmark-only support. Product runtime remains unchanged.
 */
#[RunTestsInSeparateProcesses]
#[Group('personal_secretary')]
final class ActivityCaptureBenchmarkV3ProviderKernelTest extends KernelTestBase {

  private const FIXTURE_SHA256 = 'bb31e0ba98db688adcdfd530275bf3aff47013c79d4c6bb2c4896b1477cc8cbd';

  private const EXPECTED_IDS = [
    'one_off_timed',
    'weekly_timed',
    'explicit_location',
    'relative_near_midnight',
    'unsupported_complex_recurrence',
    'underspecified',
    'concerned_only',
    'responsible_only',
    'same_person_explicit_dual_role',
    'different_people_concerned_and_responsible',
    'unclassified_unique_person',
    'unclassified_unknown_person',
    'unclassified_ambiguous_person',
    'concerned_person_alternative',
    'explicit_toute_la_journee',
    'explicit_journee_entiere',
    'journee_administrative_ambiguous',
    'journee_de_formation_ambiguous',
    'timed_activity_containing_journee',
    'no_time_mode_evidence',
  ];

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

  private string $roleId = 'activity_capture_benchmark_v3_provider';

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
      'label' => 'Activity capture benchmark V3 provider',
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

    $inert = $this->container->get('entity_type.manager')->getStorage('user')->create([
      'name' => 'activity-capture-benchmark-v3-provider-inert',
      'status' => 0,
    ]);
    $inert->save();
  }

  /**
   * Proves prompt, schema and scorer parity before any provider inference.
   */
  public function testExecutionSupportParity(): void {
    $fixture = $this->fixtureDocument();
    $semantic = $this->semanticPayload($fixture);

    self::assertSame(self::FIXTURE_SHA256, $semantic['fixture_sha256']);
    self::assertSame(self::EXPECTED_IDS, array_column($semantic['cases'], 'id'));
    self::assertNotSame('', trim($semantic['system_prompt']));
    self::assertSame(ActivityCaptureExtraction::structuredJsonSchema(), $semantic['schema']);

    $adapterPath = dirname(__DIR__, 7) . '/scripts/activity-capture-openai-v3.py';
    $adapter = (string) file_get_contents($adapterPath);
    self::assertStringContainsString('semantic["system_prompt"]', $adapter);
    self::assertStringContainsString('semantic_case["user_prompt"]', $adapter);
    self::assertStringContainsString('semantic["schema"]', $adapter);
    self::assertStringNotContainsString('Tu extrais uniquement des candidats linguistiques', $adapter);
    self::assertStringNotContainsString('Contexte synthétique/local uniquement.', $adapter);

    $phaseA = (new ReflectionClass(ActivityCaptureBenchmarkV3KernelTest::class))
      ->newInstanceWithoutConstructor();
    $phaseAScorer = new ReflectionMethod(ActivityCaptureBenchmarkV3KernelTest::class, 'compareFields');

    $scorerParityCases = 0;
    $scorerParityFields = 0;
    foreach ($fixture['cases'] as $case) {
      $ours = $this->compareFields($case['resolver_input'], $case['ai_expected']);
      $frozen = $phaseAScorer->invoke($phaseA, $case['resolver_input'], $case['ai_expected']);
      self::assertSame($frozen, $ours, $case['id']);
      self::assertTrue($this->allPass($ours), $case['id']);
      $scorerParityFields += count($ours);
      ++$scorerParityCases;
    }
    self::assertSame(20, $scorerParityCases);
    self::assertSame(320, $scorerParityFields);

    $this->prepareSyntheticResolverScope($fixture['topologies']['standard_authorized_household']);
    $oraclePass = 0;
    foreach ($fixture['cases'] as $case) {
      $proposal = $this->resolver()->resolve(
        $this->input($case),
        ActivityCaptureExtraction::fromProviderArray($case['resolver_input']),
      );
      $actual = $this->finalView($proposal);
      $ours = $this->compareFields($actual, $case['final_expected']);
      $frozen = $phaseAScorer->invoke($phaseA, $actual, $case['final_expected']);
      self::assertSame($frozen, $ours, $case['id']);
      $oraclePass += $this->allPass($ours) ? 1 : 0;
    }
    self::assertSame(20, $oraclePass);

    $export = trim((string) getenv('PS_ACTIVITY_CAPTURE_V3_SEMANTIC_PAYLOAD'));
    if ($export !== '') {
      $this->writeBoundedJson($export, $semantic);
    }

    $parityResult = trim((string) getenv('PS_ACTIVITY_CAPTURE_V3_PARITY_RESULT'));
    if ($parityResult !== '') {
      $this->writeBoundedJson($parityResult, [
        'fixture_sha256' => self::FIXTURE_SHA256,
        'prompt_parity_cases' => 20,
        'system_prompt_parity' => 'PASS',
        'user_prompt_parity_cases' => 20,
        'schema_parity' => 'PASS',
        'scorer_parity' => 'PASS',
        'scorer_parity_fields' => 320,
        'oracle_pass_cases' => 20,
        'system_prompt_sha256' => hash('sha256', $semantic['system_prompt']),
        'schema_sha256' => hash('sha256', json_encode($semantic['schema'], JSON_THROW_ON_ERROR)),
        'user_prompt_sha256' => array_column($semantic['cases'], 'user_prompt_sha256', 'id'),
      ]);
    }
  }

  /**
   * Executes exactly one authorized LOCAL_ONLY Ministral V3 tranche.
   */
  public function testLiveMinistralV3Benchmark(): void {
    if (getenv('PS_ACTIVITY_CAPTURE_BENCHMARK_V3_LIVE') !== '1') {
      self::markTestSkipped('Real LOCAL_ONLY BENCHMARK_V3 inference is explicitly gated.');
    }

    $providerId = trim((string) getenv('PS_ACTIVITY_CAPTURE_PROVIDER'));
    $modelId = trim((string) getenv('PS_ACTIVITY_CAPTURE_MODEL'));
    $host = rtrim(trim((string) getenv('PS_ACTIVITY_CAPTURE_BENCHMARK_HOST')), '/');
    $port = (int) getenv('PS_ACTIVITY_CAPTURE_BENCHMARK_PORT');
    $resultPath = trim((string) getenv('PS_ACTIVITY_CAPTURE_BENCHMARK_RESULT'));

    $hostParts = parse_url($host);
    $runtimeHost = is_array($hostParts) ? (string) ($hostParts['host'] ?? '') : '';
    $privateIp = filter_var($runtimeHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== FALSE
      && filter_var($runtimeHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === FALSE;
    $boundedHost = $runtimeHost === 'host.docker.internal' || $privateIp;
    if (
      $providerId !== 'lmstudio'
      || $modelId !== 'ps-ministral-3-3b'
      || !is_array($hostParts)
      || ($hostParts['scheme'] ?? NULL) !== 'http'
      || !$boundedHost
      || $port !== 1234
    ) {
      self::fail('BENCHMARK_V3 live runtime variables do not match the authorized LOCAL_ONLY topology.');
    }
    $this->assertBoundedPath($resultPath);

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
    self::assertArrayHasKey($modelId, $models, 'Exact Ministral V3 runtime identifier must be discoverable before inference.');

    $fixture = $this->fixtureDocument();
    $interpreter = new ActivityCaptureInterpreter($manager, $providerId, $modelId);
    $stageABefore = $this->domainCounts();
    $stageA = [];

    foreach ($fixture['cases'] as $case) {
      $started = microtime(TRUE);
      $actualExtraction = NULL;
      $schemaValid = FALSE;
      $internalId = FALSE;
      $error = NULL;
      $timeout = FALSE;
      try {
        $extraction = $interpreter->interpret($this->input($case));
        $actualExtraction = $extraction->toArray();
        $schemaValid = TRUE;
        $internalId = $extraction->containsInternalIdentity();
      }
      catch (\Throwable $e) {
        $error = get_class($e) . ': ' . $this->sanitizeError($e->getMessage());
        $message = strtolower($e->getMessage());
        $internalId = str_contains($message, 'internal identity');
        $timeout = str_contains($message, 'timed out') || str_contains($message, 'curl error 28');
      }
      $stageA[$case['id']] = [
        'schema_valid' => $schemaValid,
        'extraction' => $actualExtraction,
        'internal_id_invention' => $internalId,
        'provider_latency_seconds' => microtime(TRUE) - $started,
        'timeout' => $timeout,
        'error' => $error,
        'response_id' => NULL,
        'response_status' => NULL,
        'input_tokens' => 0,
        'cached_input_tokens' => 0,
        'output_tokens' => 0,
      ];
    }

    $stageAAfter = $this->domainCounts();
    self::assertSame($stageABefore, $stageAAfter, 'Real AI Stage A must not mutate synthetic domain entities.');

    $payload = $this->scoreProviderEvidence(
      $fixture,
      $stageA,
      'lmstudio',
      $modelId,
      20,
      $stageABefore,
      $stageAAfter,
    );
    $this->writeBoundedJson($resultPath, $payload);
  }

  /**
   * Scores one already-executed OpenAI V3 tranche through the real resolver.
   */
  public function testScoreOpenAiV3Benchmark(): void {
    $rawPath = trim((string) getenv('PS_ACTIVITY_CAPTURE_OPENAI_RAW_RESULT'));
    if ($rawPath === '') {
      self::markTestSkipped('OpenAI BENCHMARK_V3 raw evidence is not supplied.');
    }
    $this->assertBoundedPath($rawPath);

    $resultPath = trim((string) getenv('PS_ACTIVITY_CAPTURE_BENCHMARK_RESULT'));
    $this->assertBoundedPath($resultPath);

    $modelId = trim((string) getenv('PS_ACTIVITY_CAPTURE_OPENAI_MODEL'));
    if (!in_array($modelId, ['gpt-5.6-sol', 'gpt-5.6-terra'], TRUE)) {
      self::fail('OpenAI BENCHMARK_V3 model must be explicitly authorized.');
    }

    $raw = json_decode((string) file_get_contents($rawPath), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertIsArray($raw);
    self::assertSame('openai', $raw['provider'] ?? NULL);
    self::assertSame($modelId, $raw['model_id'] ?? NULL);
    self::assertSame(self::FIXTURE_SHA256, $raw['fixture_sha256'] ?? NULL);
    self::assertSame(20, $raw['request_count'] ?? NULL);
    self::assertFalse((bool) ($raw['semantic_retry'] ?? TRUE));
    self::assertIsArray($raw['cases'] ?? NULL);

    $fixture = $this->fixtureDocument();
    self::assertSame(self::EXPECTED_IDS, array_column($raw['cases'], 'id'));

    $stageABefore = $this->domainCounts();
    $stageA = [];
    foreach ($fixture['cases'] as $index => $case) {
      $rawCase = $raw['cases'][$index];
      $rawExtraction = is_array($rawCase['extraction'] ?? NULL) ? $rawCase['extraction'] : NULL;
      $schemaValid = FALSE;
      $normalized = NULL;
      $internalId = $rawExtraction !== NULL && $this->containsInternalIdentityRaw($rawExtraction);
      $error = is_string($rawCase['error_class'] ?? NULL) ? $rawCase['error_class'] : NULL;

      if ($rawExtraction !== NULL) {
        try {
          $extraction = ActivityCaptureExtraction::fromProviderArray($rawExtraction);
          $normalized = $extraction->toArray();
          $schemaValid = TRUE;
          $internalId = $internalId || $extraction->containsInternalIdentity();
        }
        catch (\Throwable $e) {
          $error ??= get_class($e) . ': ' . $this->sanitizeError($e->getMessage());
        }
      }

      $errorClass = strtoupper((string) ($rawCase['error_class'] ?? ''));
      $stageA[$case['id']] = [
        'schema_valid' => $schemaValid,
        'extraction' => $normalized,
        'internal_id_invention' => $internalId,
        'provider_latency_seconds' => (float) ($rawCase['provider_latency_seconds'] ?? 0.0),
        'timeout' => str_contains($errorClass, 'TIMEOUT'),
        'error' => $error,
        'response_id' => is_string($rawCase['response_id'] ?? NULL) ? $rawCase['response_id'] : NULL,
        'response_status' => is_string($rawCase['response_status'] ?? NULL) ? $rawCase['response_status'] : NULL,
        'input_tokens' => (int) ($rawCase['input_tokens'] ?? 0),
        'cached_input_tokens' => (int) ($rawCase['cached_input_tokens'] ?? 0),
        'output_tokens' => (int) ($rawCase['output_tokens'] ?? 0),
      ];
    }
    $stageAAfter = $this->domainCounts();
    self::assertSame($stageABefore, $stageAAfter, 'OpenAI evidence consumption must not mutate domain entities.');

    $payload = $this->scoreProviderEvidence(
      $fixture,
      $stageA,
      'openai',
      $modelId,
      20,
      $stageABefore,
      $stageAAfter,
    );
    $this->writeBoundedJson($resultPath, $payload);
  }

  /**
   * @return array<string, mixed>
   */
  private function semanticPayload(array $fixture): array {
    $reflection = new ReflectionClass(ActivityCaptureInterpreter::class);
    $interpreter = $reflection->newInstanceWithoutConstructor();

    $systemMethod = new ReflectionMethod(ActivityCaptureInterpreter::class, 'systemPrompt');
    $userMethod = new ReflectionMethod(ActivityCaptureInterpreter::class, 'buildUserPrompt');

    $systemPrompt = $systemMethod->invoke($interpreter);
    self::assertIsString($systemPrompt);

    $cases = [];
    foreach ($fixture['cases'] as $case) {
      $userPrompt = $userMethod->invoke($interpreter, $this->input($case));
      self::assertIsString($userPrompt);
      $cases[] = [
        'id' => $case['id'],
        'user_prompt' => $userPrompt,
        'user_prompt_sha256' => hash('sha256', $userPrompt),
      ];
    }

    return [
      'fixture_sha256' => self::FIXTURE_SHA256,
      'system_prompt' => $systemPrompt,
      'system_prompt_sha256' => hash('sha256', $systemPrompt),
      'schema' => ActivityCaptureExtraction::structuredJsonSchema(),
      'cases' => $cases,
    ];
  }

  /**
   * @param array<string, array<string, mixed>> $stageA
   * @param array<string, int> $stageABefore
   * @param array<string, int> $stageAAfter
   *
   * @return array<string, mixed>
   */
  private function scoreProviderEvidence(
    array $fixture,
    array $stageA,
    string $provider,
    string $modelId,
    int $requestCount,
    array $stageABefore,
    array $stageAAfter,
  ): array {
    $aiCorrect = 0;
    $aiExpected = 0;
    $schemaValidCases = 0;
    $internalIdCases = 0;
    $timeouts = 0;
    $inputTokens = 0;
    $cachedInputTokens = 0;
    $outputTokens = 0;

    foreach ($fixture['cases'] as $case) {
      $row = $stageA[$case['id']];
      $fieldResults = $this->compareFields(
        is_array($row['extraction']) ? $row['extraction'] : [],
        $case['ai_expected'],
      );
      $row['field_results'] = $fieldResults;
      $row['correct_fields'] = count(array_filter($fieldResults, static fn(array $result): bool => $result['pass']));
      $stageA[$case['id']] = $row;
      $aiCorrect += $row['correct_fields'];
      $aiExpected += count($fieldResults);
      $schemaValidCases += $row['schema_valid'] ? 1 : 0;
      $internalIdCases += $row['internal_id_invention'] ? 1 : 0;
      $timeouts += $row['timeout'] ? 1 : 0;
      $inputTokens += $row['input_tokens'];
      $cachedInputTokens += $row['cached_input_tokens'];
      $outputTokens += $row['output_tokens'];
    }

    $this->prepareSyntheticResolverScope($fixture['topologies']['standard_authorized_household']);
    $activityBefore = $this->activityCount();
    $oracleCorrect = 0;
    $finalCorrect = 0;
    $unsafeConfirmableWrong = 0;
    $avoidableFallback = 0;
    $actualClarifications = 0;
    $providerLatencies = [];
    $resolverLatencies = [];
    $endToEndLatencies = [];
    $caseResults = [];

    foreach ($fixture['cases'] as $case) {
      $oracleProposal = $this->resolver()->resolve(
        $this->input($case),
        ActivityCaptureExtraction::fromProviderArray($case['resolver_input']),
      );
      $oracleFields = $this->compareFields($this->finalView($oracleProposal), $case['final_expected']);
      $oraclePass = $this->allPass($oracleFields);
      $oracleCorrect += $oraclePass ? 1 : 0;

      $row = $stageA[$case['id']];
      $finalActual = NULL;
      $resolverLatency = 0.0;
      if ($row['schema_valid'] && is_array($row['extraction'])) {
        $resolverStarted = microtime(TRUE);
        $proposal = $this->resolver()->resolve(
          $this->input($case),
          ActivityCaptureExtraction::fromProviderArray($row['extraction']),
        );
        $resolverLatency = microtime(TRUE) - $resolverStarted;
        $finalActual = $this->finalView($proposal);
      }
      $finalFields = $this->compareFields(
        is_array($finalActual) ? $finalActual : [],
        $case['final_expected'],
      );
      $finalPass = $this->allPass($finalFields);
      $finalCorrect += $finalPass ? 1 : 0;

      $actualReady = is_array($finalActual) && ($finalActual['ready_for_confirmation'] ?? FALSE) === TRUE;
      $expectedReady = (bool) $case['expected_ready_for_confirmation'];
      $unsafe = $actualReady && !$finalPass;
      $fallback = $expectedReady && !$actualReady;
      $unsafeConfirmableWrong += $unsafe ? 1 : 0;
      $avoidableFallback += $fallback ? 1 : 0;
      if (is_array($finalActual) && ($finalActual['outcome'] ?? NULL) === 'CLARIFICATION') {
        ++$actualClarifications;
      }

      $providerLatency = (float) $row['provider_latency_seconds'];
      $e2e = $providerLatency + $resolverLatency;
      $providerLatencies[] = $providerLatency;
      $resolverLatencies[] = $resolverLatency;
      $endToEndLatencies[] = $e2e;

      $caseResults[] = [
        'id' => $case['id'],
        'scenario_family' => $case['scenario_family'],
        'safety_classification' => $case['safety_classification'],
        'schema_valid' => $row['schema_valid'],
        'field_results' => $row['field_results'],
        'ai_fields_correct' => $row['correct_fields'],
        'ai_fields_total' => 16,
        'actual_extraction' => $row['extraction'],
        'final_expected' => $case['final_expected'],
        'final_actual' => $finalActual,
        'final_product_outcome_correct' => $finalPass,
        'ready_for_confirmation' => $actualReady,
        'clarification_codes' => is_array($finalActual) ? ($finalActual['clarifications'] ?? []) : [],
        'unsafe_confirmable_wrong' => $unsafe,
        'avoidable_fallback' => $fallback,
        'internal_id_invention' => $row['internal_id_invention'],
        'provider_latency_seconds' => round($providerLatency, 6),
        'resolver_latency_seconds' => round($resolverLatency, 6),
        'e2e_latency_seconds' => round($e2e, 6),
        'timeout' => $row['timeout'],
        'error' => $row['error'],
        'response_id' => $row['response_id'],
        'response_status' => $row['response_status'],
        'input_tokens' => $row['input_tokens'],
        'cached_input_tokens' => $row['cached_input_tokens'],
        'output_tokens' => $row['output_tokens'],
        'oracle_resolver_correct' => $oraclePass,
      ];
    }

    self::assertSame($activityBefore, $this->activityCount(), 'Provider scoring must not create ActivitySeries.');

    $aiAccuracy = $aiExpected === 0 ? 0.0 : $aiCorrect / $aiExpected;
    $latencyMin = min($endToEndLatencies);
    $latencyMean = array_sum($endToEndLatencies) / count($endToEndLatencies);
    $latencyP50 = $this->percentile($endToEndLatencies, 0.50);
    $latencyP95 = $this->percentile($endToEndLatencies, 0.95);
    $latencyMax = max($endToEndLatencies);
    $semanticPass = $schemaValidCases === 20
      && $aiCorrect >= 304
      && $internalIdCases === 0
      && $finalCorrect === 20
      && $unsafeConfirmableWrong === 0
      && $avoidableFallback === 0;
    $latencyPass = $latencyP95 <= 10.0 && $timeouts === 0;

    return [
      'cases' => $caseResults,
      'summary' => [
        'fixture_sha256' => self::FIXTURE_SHA256,
        'cases' => 20,
        'provider' => $provider,
        'model_id' => $modelId,
        'request_count' => $requestCount,
        'semantic_retry' => FALSE,
        'schema_valid_cases' => $schemaValidCases,
        'ai_fields_correct' => $aiCorrect,
        'ai_fields_total' => $aiExpected,
        'ai_extraction_accuracy' => round($aiAccuracy, 6),
        'internal_id_invention_cases' => $internalIdCases,
        'oracle_resolver_correct_cases' => $oracleCorrect,
        'final_product_outcome_correct_cases' => $finalCorrect,
        'expected_clarification_cases' => 9,
        'actual_clarification_cases' => $actualClarifications,
        'avoidable_fallback_cases' => $avoidableFallback,
        'unsafe_confirmable_wrong_proposals' => $unsafeConfirmableWrong,
        'stage_a_domain_counts_before' => $stageABefore,
        'stage_a_domain_counts_after' => $stageAAfter,
        'provider_latency_min_seconds' => round(min($providerLatencies), 6),
        'provider_latency_mean_seconds' => round(array_sum($providerLatencies) / count($providerLatencies), 6),
        'provider_latency_p50_seconds' => round($this->percentile($providerLatencies, 0.50), 6),
        'provider_latency_p95_seconds' => round($this->percentile($providerLatencies, 0.95), 6),
        'provider_latency_max_seconds' => round(max($providerLatencies), 6),
        'resolver_latency_min_seconds' => round(min($resolverLatencies), 6),
        'resolver_latency_mean_seconds' => round(array_sum($resolverLatencies) / count($resolverLatencies), 6),
        'resolver_latency_p50_seconds' => round($this->percentile($resolverLatencies, 0.50), 6),
        'resolver_latency_p95_seconds' => round($this->percentile($resolverLatencies, 0.95), 6),
        'resolver_latency_max_seconds' => round(max($resolverLatencies), 6),
        'latency_min_seconds' => round($latencyMin, 6),
        'latency_mean_seconds' => round($latencyMean, 6),
        'latency_p50_seconds' => round($latencyP50, 6),
        'latency_p95_seconds' => round($latencyP95, 6),
        'latency_max_seconds' => round($latencyMax, 6),
        'timeouts' => $timeouts,
        'input_tokens' => $inputTokens,
        'cached_input_tokens' => $cachedInputTokens,
        'output_tokens' => $outputTokens,
        'semantic_verdict' => $semanticPass ? 'PASS' : 'FAIL',
        'synchronous_latency' => $latencyPass ? 'PASS' : 'FAIL',
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function fixtureDocument(): array {
    $path = dirname(__DIR__, 2) . '/fixtures/activity_capture_benchmark_v3.json';
    self::assertSame(self::FIXTURE_SHA256, hash_file('sha256', $path));
    $fixture = json_decode((string) file_get_contents($path), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertIsArray($fixture);
    self::assertSame('V3', $fixture['benchmark_generation'] ?? NULL);
    self::assertSame(16, $fixture['provider_schema_field_count'] ?? NULL);
    self::assertCount(20, $fixture['cases'] ?? []);
    self::assertSame(self::EXPECTED_IDS, array_column($fixture['cases'], 'id'));
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
      $actualValues = array_map(
        fn($value) => is_string($value) ? $this->normalizeText($value) : $value,
        array_values($actual),
      );
      $expectedValues = array_map(
        fn($value) => is_string($value) ? $this->normalizeText($value) : $value,
        array_values($expected['unordered']),
      );
      sort($actualValues);
      sort($expectedValues);
      return $actualValues === $expectedValues;
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
    $message = preg_replace('/https?:\/\/[^\s]+/', '<provider-endpoint>', $message) ?? $message;
    return preg_replace('/[A-Za-z0-9_-]{32,}/', '<redacted-runtime-token>', $message) ?? $message;
  }

  /**
   * @param array<string, mixed> $topology
   */
  private function prepareSyntheticResolverScope(array $topology): void {
    $people = [];
    foreach ($topology['people'] as $label) {
      $people[] = $this->person((string) $label);
    }
    $currentIndex = (int) $topology['current_person_index'];

    $households = [];
    foreach ($topology['households'] as $spec) {
      $members = [];
      foreach ($spec['member_indexes'] as $index) {
        $members[] = $people[(int) $index];
      }
      $households[] = $this->household((string) $spec['name'], $members);
    }
    $this->setCurrentUser($this->productUser($people[$currentIndex], $households));
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
      'name' => 'activity-capture-benchmark-v3-provider-user',
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

  /**
   * @return array<string, int>
   */
  private function domainCounts(): array {
    $manager = $this->container->get('entity_type.manager');
    $counts = [];
    foreach ([
      'personal_secretary_person' => 'person',
      'personal_secretary_household' => 'household',
      'personal_sec_activity_series' => 'activity_series',
    ] as $entityType => $key) {
      $counts[$key] = (int) $manager->getStorage($entityType)
        ->getQuery()
        ->accessCheck(FALSE)
        ->count()
        ->execute();
    }
    return $counts;
  }

  private function activityCount(): int {
    return $this->domainCounts()['activity_series'];
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
      'cardinality' => $cardinality,
      'settings' => ['target_type' => $targetType],
    ])->save();

    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => $label,
    ])->save();
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function writeBoundedJson(string $path, array $payload): void {
    $this->assertBoundedPath($path);
    $encoded = json_encode(
      $payload,
      JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
    file_put_contents($path, $encoded . PHP_EOL, LOCK_EX);
    self::assertFileExists($path);
  }

  private function assertBoundedPath(string $path): void {
    if (!str_starts_with($path, '/tmp/') || str_contains($path, '..')) {
      self::fail('BENCHMARK_V3 evidence paths must be bounded /tmp paths outside Git.');
    }
  }

}
