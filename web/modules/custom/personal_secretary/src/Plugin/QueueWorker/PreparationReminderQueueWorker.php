<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\personal_secretary\Service\PreparationReminderDeliveryService;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[QueueWorker(
  id: PreparationReminderDeliveryService::QUEUE_ID,
  title: new TranslatableMarkup('Preparation reminder delivery'),
  cron: ['time' => 30],
)]
final class PreparationReminderQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, private readonly PreparationReminderDeliveryService $deliveries) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, PreparationReminderDeliveryService::create($container));
  }

  public function processItem($data): void {
    if (is_array($data)) {
      $this->deliveries->processPayload($data);
    }
  }
}
