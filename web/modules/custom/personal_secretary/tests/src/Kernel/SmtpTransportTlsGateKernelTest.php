<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\personal_secretary\Value\PreparationReminderCandidate;
use Drupal\personal_secretary\Service\PreparationReminderCandidateService;
use Drupal\personal_secretary\Entity\PreparationReminderDelivery;
use Drupal\personal_secretary\Service\PreparationReminderDeliveryService;
use Drupal\phpmailer_smtp\Plugin\Mail\PhpMailerSmtp;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\user\Entity\Role;
use Drupal\Core\Queue\DatabaseQueue;
use Drupal\Core\Database\Database;
use Drupal\personal_secretary\Entity\PreparationCompletion;
use Symfony\Component\DependencyInjection\Reference;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Config\FileStorage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\personal_secretary\Mail\PrivacySafePhpMailerSmtp;
use Drupal\personal_secretary\Plugin\QueueWorker\PreparationReminderQueueWorker;
use PHPMailer\PHPMailer\SMTP;

/**
 * Proves the exact #126 STARTTLS transport gates through Drupal MailManager.
 *
 * @group personal_secretary
 */
final class SmtpTransportTlsGateKernelTest extends KernelTestBase {

  private const MAILPIT = '/usr/local/bin/mailpit';
  private const MKCERT = '/usr/local/bin/mkcert';

  /**
   * Kernel modules required by the SMTP transport fixture.
   *
   * @var string[]
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'filter',
    'field',
    'datetime',
    'datetime_range',
    'date_recur',
    'key',
    'phpmailer_smtp',
    'personal_secretary',
  ];

  /**
   * Controlled child processes started by this test.
   *
   * @var resource[]
   */
  private array $processes = [];

  /**
   * Temporary directories owned and removed by this test.
   *
   * @var string[]
   */
  private array $temporaryDirectories = [];

  /**
   * Synthetic role used by the transport test fixture.
   */
  private string $roleId = 'personal_secretary_smtp_tls_gate';

  /**
   * Registers the database lock backend required by reminder delivery.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('lock', DatabaseLockBackend::class)
      ->addArgument(new Reference('database'));
  }

  /**
   * Builds the isolated reminder and SMTP transport test fixture.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('personal_secretary_person');
    $this->installEntitySchema('personal_secretary_household');
    $this->installEntitySchema('personal_sec_activity_series');
    $this->installEntitySchema('personal_sec_activity_exception');
    $this->installEntitySchema('personal_sec_resp_rule');
    $this->installEntitySchema('personal_sec_resp_override');
    $this->installEntitySchema('personal_sec_prep_req');
    $this->installEntitySchema(PreparationCompletion::ENTITY_TYPE_ID);
    $this->installEntitySchema('personal_sec_prep_rem_delivery');
    $this->installConfig(['system', 'user', 'phpmailer_smtp', 'personal_secretary']);
    $this->loadCanonicalConfig([
      'system.mail',
      'personal_secretary.settings',
      'phpmailer_smtp.settings',
      'phpmailer_smtp.format',
    ]);

    $connection = Database::getConnection();
    $schema = $connection->schema();
    $queue = new DatabaseQueue(self::class, $connection);
    $lock = new DatabaseLockBackend($connection);
    $schema->createTable(DatabaseQueue::TABLE_NAME, $queue->schemaDefinition());
    $schema->createTable(DatabaseLockBackend::TABLE_NAME, $lock->schemaDefinition());

    Role::create([
      'id' => $this->roleId,
      'label' => 'Personal Secretary SMTP TLS gate',
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
    $this->installReminderField();

    $this->config('system.site')
      ->set('name', 'Synthetic Personal Secretary')
      ->set('mail', 'sender@example.test')
      ->save();
    $this->config('personal_secretary.settings')
      ->set('external_smtp_egress', TRUE)
      ->save();
  }

  /**
   * Cleans resources created by each transport test.
   */
  protected function tearDown(): void {
    foreach (array_reverse($this->processes) as $process) {
      $status = proc_get_status($process);
      if ($status['running']) {
        proc_terminate($process);
      }
      proc_close($process);
    }
    foreach (array_reverse($this->temporaryDirectories) as $directory) {
      $this->removeDirectory($directory);
    }
    parent::tearDown();
  }

  /**
   * Proves strict STARTTLS acceptance maps the reminder to SUBMITTED.
   */
  public function testTrustedStartTlsSuccessIsSubmitted(): void {
    [$cert, $key] = $this->createCertificate(['localhost', '127.0.0.1']);
    [$smtpPort, $httpPort] = $this->startMailpit($cert, $key);
    $this->configureSmtp('127.0.0.1', $smtpPort, TRUE);
    [$candidate, $user] = $this->createReminderCandidate('a1@example.test');

    $manager = $this->container->get('plugin.manager.mail');
    $plugin = $manager->getInstance([
      'module' => 'personal_secretary',
      'key' => 'preparation_reminder',
    ]);
    $this->assertInstanceOf(PrivacySafePhpMailerSmtp::class, $plugin);
    $this->assertInstanceOf(PhpMailerSmtp::class, $plugin);
    $plugin->SMTPDebug = 0;

    $service = PreparationReminderDeliveryService::create($this->container);
    $service->processPayload($candidate->queuePayload());
    $delivery = $this->singleDelivery();
    $this->assertSame(PreparationReminderDelivery::STATE_SUBMITTED, (string) $delivery->get('state')->value);
    $this->assertNotEmpty($delivery->get('submitted_at')->value);
    $this->assertSame((int) $user->id(), $candidate->recipientUserId);

    $smtp = $plugin->getSMTPInstance();
    $socketProperty = new \ReflectionProperty(SMTP::class, 'smtp_conn');
    $socket = $socketProperty->getValue($smtp);
    $this->assertIsResource($socket);
    $metadata = stream_get_meta_data($socket);
    $crypto = $metadata['crypto'] ?? [];
    $this->assertNotEmpty($crypto['protocol'] ?? NULL);
    $this->assertNotEmpty($crypto['cipher_name'] ?? NULL);
    $this->assertSame(1, $this->mailpitMessageCount($httpPort));

    $smtpConfig = $this->config('phpmailer_smtp.settings');
    $this->assertSame('tls', $smtpConfig->get('smtp_protocol'));
    $this->assertSame(1, (int) $smtpConfig->get('smtp_ssl_verify_peer'));
    $this->assertSame(1, (int) $smtpConfig->get('smtp_ssl_verify_peer_name'));
    $this->assertSame(0, (int) $smtpConfig->get('smtp_ssl_allow_self_signed'));
    $plugin->smtpClose();
    $this->assertFalse($smtp->connected());
  }

  /**
   * Proves missing STARTTLS fails closed before any plaintext submission.
   */
  public function testNoStartTlsFailsClosedWithoutPlaintextSubmission(): void {
    $port = $this->freePort();
    $directory = $this->temporaryDirectory('a2');
    $transcript = $directory . '/smtp-transcript.txt';
    $process = $this->startProcess([
      PHP_BINARY,
      dirname(__DIR__, 2) . '/fixtures/smtp_no_starttls.php',
      (string) $port,
      $transcript,
    ]);
    usleep(100000);
    $this->configureSmtp('127.0.0.1', $port, FALSE);
    [$candidate] = $this->createReminderCandidate('a2@example.test');

    $manager = $this->container->get('plugin.manager.mail');
    $plugin = $manager->getInstance([
      'module' => 'personal_secretary',
      'key' => 'preparation_reminder',
    ]);
    $this->assertInstanceOf(PrivacySafePhpMailerSmtp::class, $plugin);
    $plugin->SMTPDebug = 0;

    $service = PreparationReminderDeliveryService::create($this->container);
    $service->processPayload($candidate->queuePayload());
    $this->waitForProcess($process);

    $commands = file($transcript, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $this->assertIsArray($commands);
    $this->assertNotEmpty(array_filter($commands, static fn(string $line): bool => str_starts_with($line, 'EHLO ')));
    $this->assertContains('STARTTLS', $commands);
    foreach (['AUTH', 'MAIL FROM', 'RCPT TO', 'DATA'] as $forbidden) {
      $this->assertEmpty(array_filter(
        $commands,
        static fn(string $line): bool => str_starts_with(strtoupper($line), $forbidden),
      ));
    }

    $delivery = $this->singleDelivery();
    $this->assertSame(PreparationReminderDelivery::STATE_UNKNOWN, (string) $delivery->get('state')->value);
    $this->assertSame('mail_exception_ambiguous', (string) $delivery->get('sanitized_error_category')->value);
  }

  /**
   * Proves hostname verification rejects a certificate for another host.
   */
  public function testHostnameMismatchFailsClosedWithoutAcceptance(): void {
    [$cert, $key] = $this->createCertificate(['mismatch.example.test']);
    [$smtpPort, $httpPort] = $this->startMailpit($cert, $key);
    $this->configureSmtp('127.0.0.1', $smtpPort, FALSE);
    [$candidate] = $this->createReminderCandidate('a3@example.test');

    $smtpConfig = $this->config('phpmailer_smtp.settings');
    $this->assertSame(1, (int) $smtpConfig->get('smtp_ssl_verify_peer'));
    $this->assertSame(1, (int) $smtpConfig->get('smtp_ssl_verify_peer_name'));
    $this->assertSame(0, (int) $smtpConfig->get('smtp_ssl_allow_self_signed'));

    $plugin = $this->container->get('plugin.manager.mail')->getInstance([
      'module' => 'personal_secretary',
      'key' => 'preparation_reminder',
    ]);
    $this->assertInstanceOf(PrivacySafePhpMailerSmtp::class, $plugin);
    $plugin->SMTPDebug = 0;

    $service = PreparationReminderDeliveryService::create($this->container);
    $service->processPayload($candidate->queuePayload());

    $delivery = $this->singleDelivery();
    $this->assertSame(PreparationReminderDelivery::STATE_UNKNOWN, (string) $delivery->get('state')->value);
    $this->assertSame('mail_exception_ambiguous', (string) $delivery->get('sanitized_error_category')->value);
    $this->assertSame(0, $this->mailpitMessageCount($httpPort));
  }

  /**
   * Proves a false MailManager result maps conservatively to UNKNOWN.
   */
  public function testFalseMailResultMapsToUnknown(): void {
    [$candidate] = $this->createReminderCandidate('gate-b-false@example.test');
    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->expects($this->once())
      ->method('mail')
      ->willReturn(['result' => FALSE]);

    $service = new PreparationReminderDeliveryService(
      $this->container->get('entity_type.manager'),
      PreparationReminderCandidateService::create($this->container),
      $this->container->get('queue'),
      $this->container->get('lock'),
      $mailManager,
      $this->container->get('datetime.time'),
      $this->container->get('config.factory'),
    );
    $service->processPayload($candidate->queuePayload());

    $delivery = $this->singleDelivery();
    $this->assertSame(
      PreparationReminderDelivery::STATE_UNKNOWN,
      (string) $delivery->get('state')->value,
    );
    $this->assertSame(1, (int) $delivery->get('attempt_count')->value);
    $this->assertSame(
      'mail_result_ambiguous',
      (string) $delivery->get('sanitized_error_category')->value,
    );
    $this->assertEmpty($delivery->get('submitted_at')->value);
  }

  /**
   * Proves the real queue worker submits once and suppresses replay.
   */
  public function testQueueWorkerSubmitsOnceWithoutReplay(): void {
    [$cert, $key] = $this->createCertificate(['localhost', '127.0.0.1']);
    [$smtpPort, $httpPort] = $this->startMailpit($cert, $key);
    $this->configureSmtp('127.0.0.1', $smtpPort, FALSE);
    [$candidate] = $this->createReminderCandidate('queue-success@example.test');

    $mailPlugin = $this->container->get('plugin.manager.mail')->getInstance([
      'module' => 'personal_secretary',
      'key' => 'preparation_reminder',
    ]);
    $this->assertInstanceOf(PrivacySafePhpMailerSmtp::class, $mailPlugin);
    $mailPlugin->SMTPDebug = 0;

    $worker = $this->container->get('plugin.manager.queue_worker')
      ->createInstance(PreparationReminderDeliveryService::QUEUE_ID);
    $this->assertInstanceOf(PreparationReminderQueueWorker::class, $worker);
    $payload = $candidate->queuePayload();
    $this->assertNull($worker->processItem($payload));

    $delivery = $this->singleDelivery();
    $this->assertSame(PreparationReminderDelivery::STATE_SUBMITTED, (string) $delivery->get('state')->value);
    $this->assertSame(1, (int) $delivery->get('attempt_count')->value);
    $this->assertSame(1, $this->mailpitMessageCount($httpPort));

    $this->assertNull($worker->processItem($payload));
    $delivery = $this->singleDelivery();
    $this->assertSame(PreparationReminderDelivery::STATE_SUBMITTED, (string) $delivery->get('state')->value);
    $this->assertSame(1, (int) $delivery->get('attempt_count')->value);
    $this->assertSame(1, $this->mailpitMessageCount($httpPort));
  }

  /**
   * Proves the real queue worker terminates normally on ambiguous SMTP failure.
   */
  public function testQueueWorkerUnknownDoesNotReplay(): void {
    $port = $this->freePort();
    $directory = $this->temporaryDirectory('queue-a2');
    $transcript = $directory . '/smtp-transcript.txt';
    $fixture = $this->startProcess([
      PHP_BINARY,
      dirname(__DIR__, 2) . '/fixtures/smtp_no_starttls.php',
      (string) $port,
      $transcript,
    ]);
    usleep(100000);
    $this->configureSmtp('127.0.0.1', $port, FALSE);
    [$candidate] = $this->createReminderCandidate('queue-unknown@example.test');

    $mailPlugin = $this->container->get('plugin.manager.mail')->getInstance([
      'module' => 'personal_secretary',
      'key' => 'preparation_reminder',
    ]);
    $this->assertInstanceOf(PrivacySafePhpMailerSmtp::class, $mailPlugin);
    $mailPlugin->SMTPDebug = 0;

    $worker = $this->container->get('plugin.manager.queue_worker')
      ->createInstance(PreparationReminderDeliveryService::QUEUE_ID);
    $this->assertInstanceOf(PreparationReminderQueueWorker::class, $worker);
    $payload = $candidate->queuePayload();
    $this->assertNull($worker->processItem($payload));
    $this->waitForProcess($fixture);
    $transcriptBefore = file_get_contents($transcript);
    $this->assertNotFalse($transcriptBefore);

    $delivery = $this->singleDelivery();
    $this->assertSame(PreparationReminderDelivery::STATE_UNKNOWN, (string) $delivery->get('state')->value);
    $this->assertSame(1, (int) $delivery->get('attempt_count')->value);

    $this->assertNull($worker->processItem($payload));
    $delivery = $this->singleDelivery();
    $this->assertSame(PreparationReminderDelivery::STATE_UNKNOWN, (string) $delivery->get('state')->value);
    $this->assertSame(1, (int) $delivery->get('attempt_count')->value);
    $this->assertSame($transcriptBefore, file_get_contents($transcript));
  }

  /**
   * Applies strict SMTP settings for the controlled endpoint.
   */
  private function configureSmtp(string $host, int $port, bool $keepAlive): void {
    $this->config('phpmailer_smtp.settings')
      ->set('smtp_host', $host)
      ->set('smtp_port', $port)
      ->set('smtp_protocol', 'tls')
      ->set('smtp_username', '')
      ->set('smtp_password', '')
      ->set('smtp_authentication_type', 'basic_auth')
      ->set('smtp_keepalive', $keepAlive ? 1 : 0)
      ->set('smtp_debug', 0)
      ->set('smtp_debug_log', 0)
      ->set('smtp_ssl_verify_peer', 1)
      ->set('smtp_ssl_verify_peer_name', 1)
      ->set('smtp_ssl_allow_self_signed', 0)
      ->set('smtp_timeout', 3)
      ->save();
  }

  /**
   * Creates a temporary trusted leaf certificate for the requested names.
   */
  private function createCertificate(array $names): array {
    $directory = $this->temporaryDirectory('tls');
    $cert = $directory . '/server.pem';
    $key = $directory . '/server-key.pem';
    $command = [self::MKCERT, '-cert-file', $cert, '-key-file', $key];
    array_push($command, ...$names);
    $this->runCommand($command, $directory);
    $this->assertFileExists($cert);
    $this->assertFileExists($key);
    return [$cert, $key];
  }

  /**
   * Starts a controlled Mailpit endpoint that requires STARTTLS.
   */
  private function startMailpit(string $cert, string $key): array {
    $smtpPort = $this->freePort();
    $httpPort = $this->freePort();
    $this->startProcess([
      self::MAILPIT,
      '--smtp', '127.0.0.1:' . $smtpPort,
      '--listen', '127.0.0.1:' . $httpPort,
      '--smtp-tls-cert', $cert,
      '--smtp-tls-key', $key,
      '--smtp-require-starttls',
      '--quiet',
    ], dirname($cert));
    $this->waitForHttp($httpPort);
    return [$smtpPort, $httpPort];
  }

  /**
   * Returns the number of messages accepted by the controlled Mailpit instance.
   */
  private function mailpitMessageCount(int $httpPort): int {
    $json = file_get_contents('http://127.0.0.1:' . $httpPort . '/api/v1/messages');
    $this->assertNotFalse($json);
    $data = json_decode((string) $json, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertIsArray($data);
    return (int) ($data['total'] ?? count($data['messages'] ?? []));
  }

  /**
   * Starts a controlled child process owned by the test fixture.
   */
  private function startProcess(array $command, ?string $cwd = NULL) {
    $descriptors = [
      0 => ['file', '/dev/null', 'r'],
      1 => ['file', '/dev/null', 'a'],
      2 => ['file', '/dev/null', 'a'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $cwd);
    $this->assertIsResource($process);
    $this->processes[] = $process;
    return $process;
  }

  /**
   * Runs a fixture command and asserts a successful exit status.
   */
  private function runCommand(array $command, string $cwd): void {
    $descriptors = [
      0 => ['file', '/dev/null', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $cwd);
    $this->assertIsResource($process);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $this->assertSame(
      0,
      $exitCode,
      sprintf("Command failed. stdout=%s stderr=%s", $stdout, $stderr),
    );
  }

  /**
   * Waits for a controlled fixture process to terminate.
   */
  private function waitForProcess($process): void {
    for ($i = 0; $i < 50; ++$i) {
      $status = proc_get_status($process);
      if (!$status['running']) {
        return;
      }
      usleep(100000);
    }
    $this->fail('Controlled SMTP fixture did not terminate.');
  }

  /**
   * Waits until the controlled Mailpit HTTP API is ready.
   */
  private function waitForHttp(int $port): void {
    $context = stream_context_create(['http' => ['timeout' => 0.2]]);
    for ($i = 0; $i < 50; ++$i) {
      $response = @file_get_contents(
        'http://127.0.0.1:' . $port . '/api/v1/messages',
        FALSE,
        $context,
      );
      if ($response !== FALSE) {
        return;
      }
      usleep(100000);
    }
    $this->fail('Mailpit HTTP API did not become ready.');
  }

  /**
   * Reserves and returns an available local TCP port.
   */
  private function freePort(): int {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $this->assertIsResource($server, sprintf('%d %s', $errno, $error));
    $name = stream_socket_get_name($server, FALSE);
    fclose($server);
    $this->assertIsString($name);
    $separator = strrpos($name, ':');
    $this->assertNotFalse($separator);
    return (int) substr($name, $separator + 1);
  }

  /**
   * Creates a private temporary directory tracked for cleanup.
   */
  private function temporaryDirectory(string $prefix): string {
    $directory = sys_get_temp_dir() . '/ps126-' . $prefix . '-' . bin2hex(random_bytes(6));
    $this->assertTrue(mkdir($directory, 0700));
    $this->temporaryDirectories[] = $directory;
    return $directory;
  }

  /**
   * Removes a test-owned temporary directory recursively.
   */
  private function removeDirectory(string $directory): void {
    if (!is_dir($directory)) {
      return;
    }
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
      if ($item->isDir()) {
        rmdir($item->getPathname());
      }
      else {
        unlink($item->getPathname());
      }
    }
    rmdir($directory);
  }

  /**
   * Creates one opted-in synthetic reminder candidate for transport tests.
   */
  private function createReminderCandidate(string $email): array {
    $now = (new \DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone(new \DateTimeZone('UTC'));
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $person = $domain->createPerson('SMTP TLS Person');
    $household = $domain->createHousehold('SMTP TLS Household', [(int) $person->id()]);

    $user = $this->container->get('entity_type.manager')->getStorage('user')->create([
      'name' => 'smtp-' . substr(hash('sha256', $email), 0, 12),
      'mail' => $email,
      'status' => 1,
    ]);
    $user->addRole($this->roleId);
    $user->set(
      CurrentPersonResolver::FIELD_NAME,
      [['target_id' => (int) $person->id()]],
    );
    $user->set(
      HouseholdAuthorizationService::FIELD_NAME,
      [['target_id' => (int) $household->id()]],
    );
    $user->set(PreparationReminderCandidateService::OPT_IN_FIELD, 1);
    $user->save();

    $start = $now->modify('+2 hours');
    $end = $start->modify('+1 hour');
    $series = $domain->createActivitySeries(
      'SMTP TLS Activity',
      (int) $household->id(),
      $start,
      $end,
      'FREQ=DAILY;COUNT=1',
    );
    $this->container->get('personal_secretary.responsibility_mutation')
      ->createResponsibilityRule($series, (int) $person->id(), $start, $end, 'FREQ=DAILY;COUNT=1');
    $requirement = $this->container->get('personal_secretary.preparation_requirement_mutation')
      ->createPreparationRequirement(
        $series,
        'SMTP TLS preparation',
        3 * 3600,
        $now->modify('-1 day'),
      );

    $candidateService = PreparationReminderCandidateService::create($this->container);
    $candidate = NULL;
    foreach ($candidateService->dueForUser($user, $now) as $possible) {
      if ($possible->requirementId === (int) $requirement->id()) {
        $candidate = $possible;
        break;
      }
    }
    $this->assertInstanceOf(
      PreparationReminderCandidate::class,
      $candidate,
    );
    return [$candidate, $user];
  }

  /**
   * Loads the single reminder delivery created by the current test case.
   */
  private function singleDelivery(): PreparationReminderDelivery {
    $deliveries = $this->container->get('entity_type.manager')
      ->getStorage(PreparationReminderDelivery::ENTITY_TYPE_ID)
      ->loadMultiple();
    $this->assertCount(1, $deliveries);
    $delivery = reset($deliveries);
    $this->assertInstanceOf(PreparationReminderDelivery::class, $delivery);
    return $delivery;
  }

  /**
   * Installs the user opt-in field required by reminder candidates.
   */
  private function installReminderField(): void {
    $fieldName = PreparationReminderCandidateService::OPT_IN_FIELD;
    FieldStorageConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'user',
      'type' => 'boolean',
      'cardinality' => 1,
      'translatable' => FALSE,
    ])->save();
    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Preparation reminders',
      'required' => FALSE,
      'translatable' => FALSE,
      'default_value' => [['value' => 0]],
      'settings' => ['on_label' => 'On', 'off_label' => 'Off'],
    ])->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Installs one user entity-reference field required by the test fixture.
   */
  private function installUserReferenceField(string $fieldName, string $targetType, int $cardinality, string $label): void {
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

  /**
   * Loads selected canonical configuration into the Kernel test container.
   */
  private function loadCanonicalConfig(array $names): void {
    $storage = new FileStorage(dirname(DRUPAL_ROOT) . '/config/sync');
    foreach ($names as $name) {
      $data = $storage->read($name);
      $this->assertIsArray($data, sprintf('Missing canonical config: %s', $name));
      $this->config($name)->setData($data)->save();
    }
  }

}
