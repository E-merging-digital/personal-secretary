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
use Drupal\personal_secretary\Value\ActivityCaptureInput;
use Drupal\personal_secretary\Value\ActivityCaptureProposal;
use InvalidArgumentException;
use JsonException;
use ReflectionClass;
use ReflectionMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the bounded Drupal AI activity-capture interpreter seam.
 *
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
      ActivityCaptureProposal::structuredJsonSchema(),
      $chatInput->getChatStructuredJsonSchema()['schema'],
    );
    self::assertSame('activity_capture_proposal', $chatInput->getChatStructuredJsonSchema()['name']);

    $valid = $this->validProposal();
    $this->registerMockResponse($chatInput, json_encode($valid, JSON_THROW_ON_ERROR));
    $proposal = $interpreter->interpret($input);
    self::assertSame($valid, $proposal->toArray());
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
    $invalid = $this->validProposal();
    unset($invalid['time_mode']);
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
      'label' => 'Activity capture deterministic response',
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
      'name' => 'activity_capture_proposal',
      'strict' => FALSE,
      'schema' => ActivityCaptureProposal::structuredJsonSchema(),
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

  private function validProposal(): array {
    return [
      'intent' => 'ONE_OFF',
      'label' => 'Activité synthétique',
      'location' => NULL,
      'time_mode' => 'TIMED',
      'relative_date_expression' => NULL,
      'absolute_date' => '2026-09-30',
      'weekday' => NULL,
      'local_time' => '08:00',
      'source_timezone' => 'Europe/Brussels',
      'concerned_person_candidates' => [],
      'responsibility' => 'NONE',
      'responsibility_text' => NULL,
      'preparation_instruction' => NULL,
      'preparation_lead' => NULL,
      'ambiguous' => FALSE,
      'unsupported' => FALSE,
    ];
  }

}
