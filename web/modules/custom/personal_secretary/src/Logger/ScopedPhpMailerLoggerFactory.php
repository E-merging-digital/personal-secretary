<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Logger;

use Drupal\Core\Logger\LoggerChannel;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

final class ScopedPhpMailerLoggerFactory implements LoggerChannelFactoryInterface {

  public function __construct(
    private readonly LoggerChannelFactoryInterface $inner,
  ) {}

  public function get($channel) {
    if ($channel === 'phpmailer_smtp') {
      return new LoggerChannel('phpmailer_smtp');
    }
    return $this->inner->get($channel);
  }

  public function addLogger(LoggerInterface $logger, $priority = 0) {
    $this->inner->addLogger($logger, $priority);
  }

}
