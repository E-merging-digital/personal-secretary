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
 * BENCHMARK_V3 Phase A: frozen contract and deterministic oracle only.
 *
 * Provider inference is deliberately absent from this harness until a separate
 * Project Lead Phase B authority exists.
 */
#[RunTestsInSeparateProcesses]
#[Group('personal_secretary')]
final class ActivityCaptureBenchmarkV3KernelTest extends KernelTestBase {

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

  private const EXPECTED_FIELDS = [
    'label_text',
    'location_text',
    'concerned_person_mentions',
    'unclassified_person_mentions',
    'concerned_person_alternative',
    'responsibility_candidate',
    'date_expression',
    'date_day',
    'date_month',
    'date_year',
    'relative_day_offset',
    'start_time_expression',
    'end_time_expression',
    'recurrence_expression',
    'explicit_all_day_signal',
    'explicit_timed_signal',
  ];

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

  private string $roleId = 'activity_capture_benchmark_v3';

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
      'label' => 'Activity capture benchmark V3',
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
      'name' => 'activity-capture-benchmark-v3-inert',
      'status' => 0,
    ]);
    $inert->save();
  }

  /**
   * Proves the frozen 16-field V3 contract through the real current resolver.
   */
  public function testV3FixtureContractAndOracleResolver(): void {
    $fixture = $this->fixtureDocument();
    self::assertSame('V3', $fixture['benchmark_generation']);
    self::assertSame(16, $fixture['provider_schema_field_count']);
    self::assertSame(self::EXPECTED_FIELDS, $fixture['provider_schema_fields']);

    $schema = ActivityCaptureExtraction::structuredJsonSchema();
    self::assertSame(self::EXPECTED_FIELDS, array_keys($schema['properties']));
    self::assertSame(self::EXPECTED_FIELDS, $schema['required']);

    self::assertSame(1.0, $fixture['frozen_semantic_gates']['schema_valid_rate']);
    self::assertSame(0.95, $fixture['frozen_semantic_gates']['ai_extraction_accuracy_min']);
    self::assertSame(0, $fixture['frozen_semantic_gates']['internal_id_invention_cases_max']);
    self::assertSame(1.0, $fixture['frozen_semantic_gates']['final_product_outcome_correct_rate']);
    self::assertSame(0, $fixture['frozen_semantic_gates']['unsafe_confirmable_wrong_proposals_max']);
    self::assertSame(0, $fixture['frozen_semantic_gates']['avoidable_fallback_cases_max']);
    self::assertSame(10.0, $fixture['frozen_latency_gate']['p95_seconds_max']);
    self::assertSame(0, $fixture['frozen_latency_gate']['timeouts_max']);

    $cases = $fixture['cases'];
    self::assertCount(20, $cases);
    self::assertSame(self::EXPECTED_IDS, array_column($cases, 'id'));

    $topologyId = 'standard_authorized_household';
    self::assertArrayHasKey($topologyId, $fixture['topologies']);
    $this->prepareSyntheticResolverScope($fixture['topologies'][$topologyId]);

    $before = $this->activityCount();
    $oracleCorrect = 0;
    $expectedAiFieldsCorrect = 0;
    $clarificationCases = 0;
    $confirmableCases = 0;
    $results = [];

    foreach ($cases as $case) {
      self::assertSame($topologyId, $case['topology_id'], $case['id']);
      self::assertCount(16, $case['ai_expected'], $case['id'] . ' must score all 16 AI-owned fields.');
      self::assertCount(16, $case['resolver_input'], $case['id'] . ' must provide all 16 current provider fields.');
      self::assertSame(self::EXPECTED_FIELDS, array_keys($case['ai_expected']), $case['id']);
      self::assertSame(self::EXPECTED_FIELDS, array_keys($case['resolver_input']), $case['id']);
      self::assertNotSame('', trim((string) $case['safety_classification']), $case['id']);

      $selfScore = $this->compareFields($case['resolver_input'], $case['ai_expected']);
      self::assertTrue($this->allPass($selfScore), $case['id'] . ' frozen AI expectations do not match the oracle extraction.');
      $expectedAiFieldsCorrect += count($selfScore);

      $expectedClarifications = $case['expected_clarifications'];
      self::assertIsArray($expectedClarifications, $case['id']);
      $frozenFinalClarifications = $case['final_expected']['clarifications']['unordered'] ?? NULL;
      self::assertIsArray($frozenFinalClarifications, $case['id']);
      sort($expectedClarifications);
      sort($frozenFinalClarifications);
      self::assertSame($expectedClarifications, $frozenFinalClarifications, $case['id']);
      self::assertSame(
        $case['expected_ready_for_confirmation'],
        $case['final_expected']['ready_for_confirmation'],
        $case['id'],
      );

      $clarificationCases += $case['expected_clarifications'] === [] ? 0 : 1;
      $confirmableCases += $case['expected_ready_for_confirmation'] ? 1 : 0;

      $proposal = $this->resolver()->resolve(
        $this->input($case),
        ActivityCaptureExtraction::fromProviderArray($case['resolver_input']),
      );
      $actual = $this->finalView($proposal);
      $fieldResults = $this->compareFields($actual, $case['final_expected']);
      $pass = $this->allPass($fieldResults);
      $oracleCorrect += $pass ? 1 : 0;
      $results[$case['id']] = $pass;
    }

    self::assertSame($before, $this->activityCount(), 'V3 oracle resolution must not create ActivitySeries.');
    self::assertSame(320, $expectedAiFieldsCorrect);
    self::assertSame(9, $clarificationCases);
    self::assertSame(11, $confirmableCases);
    self::assertSame(20, $oracleCorrect, json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  }

  /**
   * @return array<string, mixed>
   */
  private function fixtureDocument(): array {
    $path = dirname(__DIR__, 2) . '/fixtures/activity_capture_benchmark_v3.json';
    $fixture = json_decode((string) file_get_contents($path), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertIsArray($fixture);
    self::assertIsArray($fixture['cases'] ?? NULL);
    self::assertIsArray($fixture['topologies'] ?? NULL);
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

  /**
   * @param array<string, mixed> $topology
   */
  private function prepareSyntheticResolverScope(array $topology): void {
    self::assertIsArray($topology['people'] ?? NULL);
    self::assertIsInt($topology['current_person_index'] ?? NULL);
    self::assertIsArray($topology['households'] ?? NULL);

    $people = [];
    foreach ($topology['people'] as $label) {
      $people[] = $this->person((string) $label);
    }

    $currentIndex = $topology['current_person_index'];
    self::assertArrayHasKey($currentIndex, $people);

    $households = [];
    foreach ($topology['households'] as $spec) {
      self::assertIsArray($spec['member_indexes'] ?? NULL);
      $members = [];
      foreach ($spec['member_indexes'] as $index) {
        self::assertArrayHasKey($index, $people);
        $members[] = $people[$index];
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
      'name' => 'activity-capture-benchmark-v3-user',
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

}
