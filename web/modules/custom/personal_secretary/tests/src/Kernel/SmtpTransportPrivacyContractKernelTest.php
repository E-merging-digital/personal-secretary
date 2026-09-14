<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Logger\LoggerChannel;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\personal_secretary\Logger\ScopedPhpMailerLoggerFactory;
use Drupal\personal_secretary\Mail\PrivacySafePhpMailerSmtp;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Stringable;

/**
 * Proves the bounded #126 SMTP privacy/configuration contract.
 *
 * @group personal_secretary
 */
final class SmtpTransportPrivacyContractKernelTest extends KernelTestBase {

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

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'phpmailer_smtp', 'personal_secretary']);
    $this->loadCanonicalConfig([
      'system.mail',
      'personal_secretary.settings',
      'phpmailer_smtp.settings',
      'phpmailer_smtp.format',
      'key.key.personal_secretary_smtp_token',
      'key.config_override.personal_secretary_smtp_password',
    ]);
    $this->config('system.site')
      ->set('name', 'Synthetic Personal Secretary')
      ->set('mail', 'sender-marker@example.test')
      ->save();
  }

  public function testCanonicalConfigurationAndExactRouting(): void {
    $mailConfig = $this->config('system.mail');
    $this->assertSame('php_mail', $mailConfig->get('interface.default'));
    $this->assertSame('phpmailer_smtp', $mailConfig->get('interface.personal_secretary_preparation_reminder'));
    $this->assertFalse((bool) $this->config('personal_secretary.settings')->get('external_smtp_egress'));

    $key = $this->config('key.key.personal_secretary_smtp_token');
    $this->assertSame('env', $key->get('key_provider'));
    $this->assertSame('PERSONAL_SECRETARY_SMTP_TOKEN', $key->get('key_provider_settings.env_variable'));

    $override = $this->config('key.config_override.personal_secretary_smtp_password');
    $this->assertSame('phpmailer_smtp.settings', $override->get('config_name'));
    $this->assertSame('smtp_password', $override->get('config_item'));
    $this->assertSame('personal_secretary_smtp_token', $override->get('key_id'));

    $canonical = new FileStorage(dirname(DRUPAL_ROOT) . '/config/sync');
    $smtp = $canonical->read('phpmailer_smtp.settings');
    $this->assertIsArray($smtp);
    $this->assertSame('', $smtp['smtp_password']);

    $manager = $this->container->get('plugin.manager.mail');
    $definition = $manager->getDefinition('phpmailer_smtp');
    $this->assertSame(PrivacySafePhpMailerSmtp::class, $definition['class']);
    $this->assertInstanceOf(
      PrivacySafePhpMailerSmtp::class,
      $manager->getInstance(['module' => 'personal_secretary', 'key' => 'preparation_reminder']),
    );
  }

  public function testScopedLoggerSuppressesOnlyPhpMailerChannel(): void {
    $innerChannel = new LoggerChannel('inner');
    $inner = new class($innerChannel) implements LoggerChannelFactoryInterface {
      public bool $loggerAdded = FALSE;
      public function __construct(public LoggerChannel $channel) {}

      public function get($channel) {
        return $this->channel;
      }

      public function addLogger(LoggerInterface $logger, $priority = 0) {
        $this->loggerAdded = TRUE;
      }
    };

    $factory = new ScopedPhpMailerLoggerFactory($inner);
    $suppressed = $factory->get('phpmailer_smtp');
    $this->assertInstanceOf(LoggerChannel::class, $suppressed);
    $this->assertNotSame($innerChannel, $suppressed);
    $this->assertSame($innerChannel, $factory->get('unrelated'));

    $factory->addLogger(new NullLogger());
    $this->assertTrue($inner->loggerAdded);
  }

  public function testParentFalseBecomesSanitizedThrowableAndBypassesCoreFallback(): void {
    $this->config('phpmailer_smtp.settings')
      ->set('smtp_host', '127.0.0.1')
      ->set('smtp_port', 1)
      ->set('smtp_protocol', 'tls')
      ->set('smtp_timeout', 1)
      ->set('smtp_username', 'user-marker')
      ->set('smtp_password', 'secret-marker')
      ->save();
    $this->config('phpmailer_smtp.settings')
      ->set('smtp_ehlo_host', 'transcript-marker.example.test')
      ->save();

    $capture = new SmtpPrivacyCapturingLogger();
    $this->container->get('logger.factory')->addLogger($capture, 1000);
    ob_start();

    try {
      $this->container->get('plugin.manager.mail')->mail(
        'personal_secretary',
        'preparation_reminder',
        'recipient-marker@example.test',
        'en',
        ['preparations_url' => '/personal-secretary/preparations/mine'],
        'message-marker@example.test',
        TRUE,
      );
      $this->fail('Controlled SMTP failure must throw a sanitized exception.');
    }
    catch (RuntimeException $exception) {
      $this->assertSame('SMTP delivery outcome is unknown.', $exception->getMessage());
    }
    finally {
      $stdout = (string) ob_get_clean();
    }

    $encoded = json_encode($capture->records, JSON_THROW_ON_ERROR) . $stdout;
    foreach ([
      'recipient-marker',
      'sender-marker',
      'message-marker',
      'secret-marker',
      'transcript-marker',
      'Personal Secretary — préparation à effectuer',
      'Vous avez une préparation à effectuer dans Personal Secretary.',
    ] as $marker) {
      $this->assertStringNotContainsString($marker, $encoded);
    }
    $channels = array_column(array_column($capture->records, 'context'), 'channel');
    $this->assertNotContains('phpmailer_smtp', $channels);
    $this->assertNotContains('mail', $channels);
  }

  public function testParentTrueIsPreservedAgainstLocalMailpit(): void {
    $this->config('phpmailer_smtp.settings')
      ->set('smtp_host', '127.0.0.1')
      ->set('smtp_port', 1025)
      ->set('smtp_protocol', '')
      ->set('smtp_username', '')
      ->set('smtp_password', '')
      ->set('smtp_timeout', 2)
      ->save();

    $message = $this->container->get('plugin.manager.mail')->mail(
      'personal_secretary',
      'preparation_reminder',
      'success-marker@example.test',
      'en',
      ['preparations_url' => '/personal-secretary/preparations/mine'],
      NULL,
      TRUE,
    );

    $this->assertTrue($message['result']);
    $this->assertSame('Personal Secretary — préparation à effectuer', $message['subject']);
    $this->assertStringContainsString(
      'Vous avez une préparation à effectuer dans Personal Secretary.',
      (string) $message['body'],
    );
  }

  /**
   * Loads selected committed configuration into the Kernel test container.
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

final class SmtpPrivacyCapturingLogger extends AbstractLogger {

  public array $records = [];

  public function log($level, string|Stringable $message, array $context = []): void {
    $this->records[] = [
      'level' => $level,
      'message' => (string) $message,
      'context' => $context,
    ];
  }

}
