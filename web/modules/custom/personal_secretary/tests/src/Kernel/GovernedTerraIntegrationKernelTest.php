<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Http\ClientFactory;
use Drupal\KernelTests\KernelTestBase;
use Drupal\ai\Event\AiExceptionEvent;
use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\key\Entity\Key;
use Drupal\personal_secretary\Exception\ActivityCaptureProviderException;
use Drupal\personal_secretary\Service\ActivityCaptureInterpreter;
use Drupal\personal_secretary\Value\ActivityCaptureExtraction;
use Drupal\personal_secretary\Value\ActivityCaptureInput;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves governed Terra integration without any OpenAI network traffic.
 */
#[RunTestsInSeparateProcesses]
#[Group('personal_secretary')]
final class GovernedTerraIntegrationKernelTest extends KernelTestBase {

  private const KEY_ID = 'personal_secretary_openai_api_key';

  private const SYNTHETIC_KEY_ENV = 'PERSONAL_SECRETARY_OPENAI_API_KEY';

  /**
   * Captured Guzzle request/response transactions.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $history = [];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'key',
    'ai',
    'ai_provider_openai',
    'datetime',
    'datetime_range',
    'date_recur',
    'phpmailer_smtp',
    'personal_secretary',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['ai', 'ai_provider_openai']);
    $this->installCanonicalKeyConfiguration();

    // This is deliberately not an OpenAI key. Every HTTP request is handled by
    // Guzzle MockHandler before a socket can be opened.
    putenv(self::SYNTHETIC_KEY_ENV . '=synthetic-placeholder-not-a-real-key');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv(self::SYNTHETIC_KEY_ENV);
    parent::tearDown();
  }

  /**
   * Proves the exact governed Terra Responses payload on mocked transport.
   */
  public function testGovernedTerraEmitsExactResponsesPayloadWithoutModerationCall(): void {
    $factory = $this->installMockTransport([
      $this->cannedResponse(json_encode($this->validExtraction(), JSON_THROW_ON_ERROR)),
    ]);

    $interpreter = new ActivityCaptureInterpreter(
      $this->container->get('ai.provider'),
      ActivityCaptureInterpreter::GOVERNED_TERRA_PROVIDER_ID,
      ActivityCaptureInterpreter::GOVERNED_TERRA_MODEL_ID,
    );

    $extraction = $interpreter->interpret($this->syntheticInput());
    self::assertSame($this->validExtraction(), $extraction->toArray());

    // Exactly one mocked HTTP transaction proves both no hidden moderation call
    // and no automatic retry on the success path.
    self::assertCount(1, $this->history);
    $request = $this->history[0]['request'];
    self::assertSame('POST', $request->getMethod());
    self::assertSame('/v1/responses', $request->getUri()->getPath());

    $payload = json_decode((string) $request->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertSame('gpt-5.6-terra', $payload['model']);
    self::assertFalse($payload['store']);
    self::assertSame('none', $payload['reasoning']['effort']);
    self::assertFalse($payload['background']);

    self::assertSame('json_schema', $payload['text']['format']['type']);
    self::assertSame('activity_capture_extraction', $payload['text']['format']['name']);
    self::assertTrue($payload['text']['format']['strict']);
    self::assertSame(
      ActivityCaptureExtraction::structuredJsonSchema(),
      $payload['text']['format']['schema'],
    );

    foreach ([
      'tools',
      'web',
      'web_search',
      'file_search',
      'mcp',
      'previous_response_id',
      'conversation',
    ] as $prohibited) {
      self::assertArrayNotHasKey($prohibited, $payload);
    }
    self::assertStringNotContainsString('input_file', json_encode($payload, JSON_THROW_ON_ERROR));
    self::assertStringNotContainsString('file_id', json_encode($payload, JSON_THROW_ON_ERROR));

    $this->assertStaticStructuralSchema($payload['text']['format']['schema']);

    // Drupal AI injects its configured request timeout into the actual provider
    // HTTP client. A positive finite value proves the bounded timeout.
    self::assertSame(60, $factory->lastOptions['timeout'] ?? NULL);
  }

  /**
   * Proves provider failure cannot retry or escape through forced fallback.
   */
  public function testProviderFailureCannotBeReplacedByForcedFallbackOutputOrRetry(): void {
    $this->installMockTransport([
      new Response(
        500,
        ['Content-Type' => 'application/json'],
        json_encode([
          'error' => [
            'message' => 'Synthetic intercepted provider failure.',
            'type' => 'server_error',
            'code' => 'server_error',
          ],
        ], JSON_THROW_ON_ERROR),
      ),
    ]);

    $forcedOutputAttempted = FALSE;
    $this->container->get('event_dispatcher')->addListener(
      AiExceptionEvent::class,
      static function (AiExceptionEvent $event) use (&$forcedOutputAttempted): void {
        if (!in_array(ActivityCaptureInterpreter::GOVERNED_TERRA_REQUEST_TAG, $event->getTags(), TRUE)) {
          return;
        }
        $forcedOutputAttempted = TRUE;
        $event->setForcedOutputObject(new ChatOutput(
          new ChatMessage(
            'assistant',
            json_encode([
              'label_text' => 'This fallback must never escape',
              'location_text' => NULL,
              'concerned_person_mentions' => [],
              'unclassified_person_mentions' => [],
              'concerned_person_alternative' => FALSE,
              'responsibility_candidate' => NULL,
              'date_expression' => NULL,
              'date_day' => NULL,
              'date_month' => NULL,
              'date_year' => NULL,
              'relative_day_offset' => NULL,
              'start_time_expression' => NULL,
              'end_time_expression' => NULL,
              'recurrence_expression' => NULL,
              'explicit_all_day_signal' => FALSE,
              'explicit_timed_signal' => FALSE,
            ], JSON_THROW_ON_ERROR),
          ),
          [],
          [],
        ));
      },
      0,
    );

    $interpreter = new ActivityCaptureInterpreter(
      $this->container->get('ai.provider'),
      ActivityCaptureInterpreter::GOVERNED_TERRA_PROVIDER_ID,
      ActivityCaptureInterpreter::GOVERNED_TERRA_MODEL_ID,
    );

    try {
      $interpreter->interpret($this->syntheticInput());
      self::fail('Governed Terra failure must fail closed.');
    }
    catch (ActivityCaptureProviderException $e) {
      self::assertSame(ActivityCaptureInterpreter::GOVERNED_TERRA_FAILURE_MESSAGE, $e->getMessage());
    }

    self::assertTrue($forcedOutputAttempted);
    self::assertCount(
      1,
      $this->history,
      'A provider failure must not cause a moderation request, retry, or fallback provider request.',
    );
    self::assertSame('/v1/responses', $this->history[0]['request']->getUri()->getPath());
  }

  /**
   * Proves a pre-generate forced output cannot silently bypass the contract.
   */
  public function testPreGenerateForcedOutputIsRejectedBeforeTransport(): void {
    $this->installMockTransport([
      $this->cannedResponse(json_encode($this->validExtraction(), JSON_THROW_ON_ERROR)),
    ]);

    $forcedOutputAttempted = FALSE;
    $this->container->get('event_dispatcher')->addListener(
      PreGenerateResponseEvent::EVENT_NAME,
      static function (PreGenerateResponseEvent $event) use (&$forcedOutputAttempted): void {
        if (!in_array(ActivityCaptureInterpreter::GOVERNED_TERRA_REQUEST_TAG, $event->getTags(), TRUE)) {
          return;
        }
        $forcedOutputAttempted = TRUE;
        $event->setForcedOutputObject(new ChatOutput(
          new ChatMessage('assistant', '{}'),
          [],
          [],
        ));
      },
      0,
    );

    $interpreter = new ActivityCaptureInterpreter(
      $this->container->get('ai.provider'),
      ActivityCaptureInterpreter::GOVERNED_TERRA_PROVIDER_ID,
      ActivityCaptureInterpreter::GOVERNED_TERRA_MODEL_ID,
    );

    try {
      $interpreter->interpret($this->syntheticInput());
      self::fail('Governed Terra pre-generate substitution must fail closed.');
    }
    catch (ActivityCaptureProviderException $e) {
      self::assertSame(ActivityCaptureInterpreter::GOVERNED_TERRA_FAILURE_MESSAGE, $e->getMessage());
    }

    self::assertTrue($forcedOutputAttempted);
    self::assertCount(0, $this->history, 'Forced pre-generate output must be rejected before transport.');
  }

  /**
   * Proves event subscribers cannot weaken the governed request configuration.
   */
  public function testPreGenerateContractMutationIsRejectedBeforeTransport(): void {
    $this->installMockTransport([
      $this->cannedResponse(json_encode($this->validExtraction(), JSON_THROW_ON_ERROR)),
    ]);

    $this->container->get('event_dispatcher')->addListener(
      PreGenerateResponseEvent::EVENT_NAME,
      static function (PreGenerateResponseEvent $event): void {
        if (!in_array(ActivityCaptureInterpreter::GOVERNED_TERRA_REQUEST_TAG, $event->getTags(), TRUE)) {
          return;
        }
        $configuration = $event->getConfiguration();
        $configuration['store'] = TRUE;
        $event->setConfiguration($configuration);
      },
      0,
    );

    $interpreter = new ActivityCaptureInterpreter(
      $this->container->get('ai.provider'),
      ActivityCaptureInterpreter::GOVERNED_TERRA_PROVIDER_ID,
      ActivityCaptureInterpreter::GOVERNED_TERRA_MODEL_ID,
    );

    try {
      $interpreter->interpret($this->syntheticInput());
      self::fail('Governed Terra contract mutation must fail closed.');
    }
    catch (ActivityCaptureProviderException $e) {
      self::assertSame(ActivityCaptureInterpreter::GOVERNED_TERRA_FAILURE_MESSAGE, $e->getMessage());
    }

    self::assertCount(0, $this->history, 'Mutated governed configuration must be rejected before transport.');
  }

  /**
   * Proves canonical provider configuration contains only secret indirection.
   */
  public function testCanonicalKeyAndProviderConfigContainOnlySecretIndirection(): void {
    $canonical = new FileStorage(dirname(DRUPAL_ROOT) . '/config/sync');
    $keyConfig = $canonical->read('key.key.' . self::KEY_ID);
    $providerConfig = $canonical->read('ai_provider_openai.settings');
    $aiConfig = $canonical->read('ai.settings');

    self::assertIsArray($keyConfig);
    self::assertIsArray($providerConfig);
    self::assertIsArray($aiConfig);
    self::assertSame(self::KEY_ID, $providerConfig['api_key']);
    self::assertSame('env', $keyConfig['key_provider']);
    self::assertSame(
      self::SYNTHETIC_KEY_ENV,
      $keyConfig['key_provider_settings']['env_variable'],
    );
    self::assertSame('none', $keyConfig['key_input']);
    self::assertSame([], $aiConfig['default_providers']);
    self::assertFalse($aiConfig['prompt_logging']);
    self::assertSame(60, $aiConfig['request_timeout']);
    self::assertArrayNotHasKey('key_value', $keyConfig);
    self::assertArrayNotHasKey('value', $keyConfig);

    $serialized = Yaml::encode([
      'key' => $keyConfig,
      'provider' => $providerConfig,
    ]);
    self::assertStringNotContainsString('synthetic-placeholder-not-a-real-key', $serialized);
    self::assertStringNotContainsString('sk-', $serialized);
  }

  /**
   * Installs canonical key/provider settings without adding any real secret.
   */
  private function installCanonicalKeyConfiguration(): void {
    $canonical = new FileStorage(dirname(DRUPAL_ROOT) . '/config/sync');
    $keyConfig = $canonical->read('key.key.' . self::KEY_ID);
    $providerConfig = $canonical->read('ai_provider_openai.settings');
    self::assertIsArray($keyConfig);
    self::assertIsArray($providerConfig);

    Key::create($keyConfig)->save();
    $this->config('ai_provider_openai.settings')
      ->setData($providerConfig)
      ->save();
  }

  /**
   * Replaces Drupal's HTTP client factory with a fully mocked transport.
   */
  private function installMockTransport(array $responses): ClientFactory {
    $this->history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));

    $factory = new class($stack) extends ClientFactory {

      /**
       * Last client options requested by Drupal AI.
       *
       * @var array<string, mixed>
       */
      public array $lastOptions = [];

      /**
       * {@inheritdoc}
       */
      public function fromOptions(array $config = []) {
        $this->lastOptions = $config;
        return parent::fromOptions($config);
      }

    };

    $this->container->set('http_client_factory', $factory);
    return $factory;
  }

  /**
   * Builds one synthetic successful Responses API result.
   */
  private function cannedResponse(string $text): Response {
    return new Response(
      200,
      ['Content-Type' => 'application/json'],
      json_encode([
        'id' => 'resp_synthetic_181',
        'object' => 'response',
        'created_at' => 1789822800,
        'status' => 'completed',
        'model' => 'gpt-5.6-terra',
        'output' => [[
          'type' => 'message',
          'id' => 'msg_synthetic_181',
          'status' => 'completed',
          'role' => 'assistant',
          'content' => [[
            'type' => 'output_text',
            'text' => $text,
            'annotations' => [],
          ],
          ],
        ],
        ],
        'parallel_tool_calls' => FALSE,
        'tool_choice' => 'auto',
        'tools' => [],
        'text' => ['format' => ['type' => 'text']],
        'reasoning' => ['effort' => 'none', 'generate_summary' => NULL],
        'metadata' => [],
        'usage' => [
          'input_tokens' => 10,
          'input_tokens_details' => ['cached_tokens' => 0],
          'output_tokens' => 5,
          'output_tokens_details' => ['reasoning_tokens' => 0],
          'total_tokens' => 15,
        ],
      ], JSON_THROW_ON_ERROR),
    );
  }

  /**
   * Proves the schema contains structure only, with no personal examples.
   */
  private function assertStaticStructuralSchema(array $schema): void {
    $encoded = json_encode($schema, JSON_THROW_ON_ERROR);
    foreach ([
      'Alice Example',
      'Rue Exemple 1',
      '30 septembre 2026',
      'activité synthétique',
      'Europe/Brussels',
    ] as $personalOrFixtureValue) {
      self::assertStringNotContainsString($personalOrFixtureValue, $encoded);
    }

    $walk = static function (array $fragment) use (&$walk): void {
      foreach (['example', 'examples', 'default', 'const'] as $forbiddenKey) {
        self::assertArrayNotHasKey($forbiddenKey, $fragment);
      }
      foreach ($fragment as $value) {
        if (is_array($value)) {
          $walk($value);
        }
      }
    };
    $walk($schema);
  }

  /**
   * Returns one wholly synthetic activity-capture input.
   */
  private function syntheticInput(): ActivityCaptureInput {
    return new ActivityCaptureInput(
      'Le 30 septembre 2026 à 08h, activité synthétique.',
      new \DateTimeImmutable('2026-09-16T12:00:00Z'),
      'Europe/Brussels',
    );
  }

  /**
   * Returns the wholly synthetic extraction used by the mocked response.
   */
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