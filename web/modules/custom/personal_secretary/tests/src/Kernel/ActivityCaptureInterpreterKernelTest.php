<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use DateTimeImmutable;
use Drupal\Component\Serialization\Yaml;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\personal_secretary\Service\ActivityCaptureInterpreter;
use Drupal\personal_secretary\Value\ActivityCaptureExtraction;
use Drupal\personal_secretary\Value\ActivityCaptureInput;
use InvalidArgumentException;
use JsonException;
use ReflectionClass;
use ReflectionMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the narrow Drupal AI linguistic-extraction seam.
 */
#[RunTestsInSeparateProcesses]
#[Group('personal_secretary')]
final class ActivityCaptureInterpreterKernelTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'key',
    'ai',
    'ai_test',
    'datetime',
    'datetime_range',
    'date_recur',
    'phpmailer_smtp',
    'personal_secretary',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['ai', 'ai_test']);
    $this->installEntitySchema('ai_mock_provider_result');
  }

  public function testBoundaryUsesDrupalAiStructuredRuntimeSuppliedProvider(): void {
    $manager = $this->container->get('ai.provider');
    self::assertInstanceOf(AiProviderPluginManager::class, $manager);
    $interpreter = new ActivityCaptureInterpreter($manager, 'echoai', 'gpt-test');
    $input = $this->syntheticInput();
    $chatInput = $this->expectedChatInput($interpreter, $input);

    self::assertSame(
      ActivityCaptureExtraction::structuredJsonSchema(),
      $chatInput->getChatStructuredJsonSchema()['schema'],
    );
    self::assertSame('activity_capture_extraction', $chatInput->getChatStructuredJsonSchema()['name']);
    self::assertCount(16, $chatInput->getChatStructuredJsonSchema()['schema']['required']);

    $valid = $this->validExtraction();
    $this->registerMockResponse($chatInput, json_encode($valid, JSON_THROW_ON_ERROR));
    $extraction = $interpreter->interpret($input);
    self::assertSame($valid, $extraction->toArray());
    self::assertFalse($extraction->containsInternalIdentity());
  }

  /**
   * Proves governed Terra uses the current strict static schema.
   */
  public function testGovernedTerraUsesStrictStaticSchema(): void {
    $interpreter = new ActivityCaptureInterpreter(
      $this->container->get('ai.provider'),
      ActivityCaptureInterpreter::GOVERNED_TERRA_PROVIDER_ID,
      ActivityCaptureInterpreter::GOVERNED_TERRA_MODEL_ID,
    );
    $input = $this->syntheticInput();
    $method = new ReflectionMethod($interpreter, 'buildChatInput');
    $chatInput = $method->invoke($interpreter, $input);

    self::assertTrue($chatInput->getChatStructuredJsonSchema()['strict']);
    self::assertSame(
      ActivityCaptureExtraction::structuredJsonSchema(),
      $chatInput->getChatStructuredJsonSchema()['schema'],
    );
  }

  public function testCurrentProviderPayloadMissingUnclassifiedPersonMentionsFailsClosed(): void {
    $interpreter = new ActivityCaptureInterpreter($this->container->get('ai.provider'), 'echoai', 'gpt-test');
    $input = $this->syntheticInput();
    $invalid = $this->validExtraction();
    unset($invalid['unclassified_person_mentions']);
    $this->registerMockResponse($this->expectedChatInput($interpreter, $input), json_encode($invalid, JSON_THROW_ON_ERROR));

    $this->expectException(InvalidArgumentException::class);
    $interpreter->interpret($input);
  }

  public function testSystemPromptMaterializesRoleAwareAndExplicitAllDayContract(): void {
    $interpreter = new ActivityCaptureInterpreter($this->container->get('ai.provider'), 'echoai', 'gpt-test');
    $method = new ReflectionMethod($interpreter, 'systemPrompt');
    $prompt = (string) $method->invoke($interpreter);

    self::assertStringContainsString('Une Personne exprimée uniquement comme responsable', $prompt);
    self::assertStringContainsString('les deux rôles', $prompt);
    self::assertStringContainsString('unclassified_person_mentions', $prompt);
    self::assertStringContainsString('toute la journée', $prompt);
    self::assertStringContainsString('journée administrative', $prompt);
  }

  public function testMalformedNormalizedJsonFailsClosed(): void {
    $interpreter = new ActivityCaptureInterpreter($this->container->get('ai.provider'), 'echoai', 'gpt-test');
    $input = $this->syntheticInput();
    $this->registerMockResponse($this->expectedChatInput($interpreter, $input), 'not-json');

    $this->expectException(JsonException::class);
    $interpreter->interpret($input);
  }

  public function testSchemaInvalidNormalizedJsonFailsClosed(): void {
    $interpreter = new ActivityCaptureInterpreter($this->container->get('ai.provider'), 'echoai', 'gpt-test');
    $input = $this->syntheticInput();
    $invalid = $this->validExtraction();
    unset($invalid['explicit_timed_signal']);
    $this->registerMockResponse($this->expectedChatInput($interpreter, $input), json_encode($invalid, JSON_THROW_ON_ERROR));

    $this->expectException(InvalidArgumentException::class);
    $interpreter->interpret($input);
  }

  public function testConstructorHasNoDomainMutationDependency(): void {
    $constructor = (new ReflectionClass(ActivityCaptureInterpreter::class))->getConstructor();
    self::assertNotNull($constructor);
    $types = array_map(
      static fn($parameter): string => (string) $parameter->getType(),
      $constructor->getParameters(),
    );
    self::assertSame([AiProviderPluginManager::class, 'string', 'string'], $types);
  }

  private function registerMockResponse(ChatInput $request, string $responseText): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_mock_provider_result');
    $storage->create([
      'label' => 'Activity capture deterministic extraction',
      'request' => Yaml::encode($request->toArray()),
      'response' => Yaml::encode([
        'normalized' => [
          'role' => 'assistant',
          'text' => $responseText,
        ],
        'rawOutput' => [],
        'metadata' => [],
      ]),
      'sleep_time' => 0,
      'operation_type' => 'chat',
      'mock_enabled' => TRUE,
    ])->save();
    $this->container->get('cache.ai')->deleteAll();
  }

  private function expectedChatInput(ActivityCaptureInterpreter $interpreter, ActivityCaptureInput $input): ChatInput {
    $userPrompt = new ReflectionMethod($interpreter, 'buildUserPrompt');
    $systemPrompt = new ReflectionMethod($interpreter, 'systemPrompt');
    $chatInput = new ChatInput([
      new ChatMessage('user', $userPrompt->invoke($interpreter, $input)),
    ]);
    $chatInput->setSystemPrompt($systemPrompt->invoke($interpreter));
    $chatInput->setChatStructuredJsonSchema([
      'name' => 'activity_capture_extraction',
      'strict' => FALSE,
      'schema' => ActivityCaptureExtraction::structuredJsonSchema(),
    ]);
    return $chatInput;
  }

  private function syntheticInput(): ActivityCaptureInput {
    return new ActivityCaptureInput(
      'Le 30 septembre 2026 à 08h, activité synthétique.',
      new DateTimeImmutable('2026-09-16T12:00:00Z'),
      'Europe/Brussels',
    );
  }

  private function validExtraction(): array {
    return [
      'label_text' => 'Activité synthétique',
      'location_text' => NULL,
      'concerned_person_mentions' => [],
      'unclassified_person_mentions' => [],
      'concerned_person_alternative' => FALSE,
      'responsibility_candidate' => NULL,
      'date_expression' => '30 septembre 2026',
      'date_day' => 30,
      'date_month' => 9,
      'date_year' => 2026,
      'relative_day_offset' => NULL,
      'start_time_expression' => '08h',
      'end_time_expression' => NULL,
      'recurrence_expression' => NULL,
      'explicit_all_day_signal' => FALSE,
      'explicit_timed_signal' => TRUE,
    ];
  }

}