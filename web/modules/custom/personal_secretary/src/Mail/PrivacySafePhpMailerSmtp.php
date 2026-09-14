<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Mail;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\phpmailer_smtp\Plugin\Mail\PhpMailerSmtp;
use Drupal\phpmailer_smtp\PluginManager\PhpmailerOauth2PluginManagerInterface;
use Drupal\personal_secretary\Logger\ScopedPhpMailerLoggerFactory;
use RuntimeException;

final class PrivacySafePhpMailerSmtp extends PhpMailerSmtp {

  public function mail(array $message) {
    if (parent::mail($message) === TRUE) {
      return TRUE;
    }

    throw new RuntimeException('SMTP delivery outcome is unknown.');
  }

  public function __construct(
    ConfigFactoryInterface $config,
    LoggerChannelFactoryInterface $logger_factory,
    MessengerInterface $messenger,
    PhpmailerOauth2PluginManagerInterface $plugin_manager,
    RendererInterface $renderer,
    FileSystemInterface $file_system,
    AccountProxyInterface $current_user,
  ) {
    parent::__construct(
      $config,
      new ScopedPhpMailerLoggerFactory($logger_factory),
      $messenger,
      $plugin_manager,
      $renderer,
      $file_system,
      $current_user,
    );
  }

}
