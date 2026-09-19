<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\EventSubscriber;

use Drupal\ai\Event\AiExceptionEvent;
use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\personal_secretary\Exception\ActivityCaptureProviderException;
use Drupal\personal_secretary\Service\ActivityCaptureInterpreter;
use Drupal\personal_secretary\Value\ActivityCaptureExtraction;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Enforces fail-closed governed Terra Activity Capture requests.
 */
final class GovernedTerraFailClosedSubscriber implements EventSubscriberInterface {

  /**
   * Verifies the final request contract after normal pre-generate subscribers.
   */
  public function onPreGenerate(PreGenerateResponseEvent $event): void {
    if (!$this->isGovernedTerraRequest($event->getTags())) {
      return;
    }

    if (
      $event->getProviderId() !== ActivityCaptureInterpreter::GOVERNED_TERRA_PROVIDER_ID
      || $event->getModelId() !== ActivityCaptureInterpreter::GOVERNED_TERRA_MODEL_ID
      || $event->getOperationType() !== 'chat'
    ) {
      throw $this->failure();
    }

    // A pre-generate forced response is a silent substitute just as much as an
    // exception-event forced response, so it is forbidden for this feature.
    if ($event->getForcedOutputObject() !== NULL) {
      throw $this->failure();
    }

    $configuration = $event->getConfiguration();
    if (
      ($configuration['store'] ?? NULL) !== FALSE
      || ($configuration['reasoning_effort'] ?? NULL) !== 'none'
      || ($configuration['background'] ?? NULL) !== FALSE
    ) {
      throw $this->failure();
    }

    $allowedConfiguration = [
      'background',
      'max_output_tokens',
      'reasoning_effort',
      'store',
    ];
    if (array_diff(array_keys($configuration), $allowedConfiguration) !== []) {
      throw $this->failure();
    }

    if (!in_array('skip_moderation', $event->getTags(), TRUE)) {
      throw $this->failure();
    }

    $input = $event->getInput();
    if (!$input instanceof ChatInput || $input->getChatTools()) {
      throw $this->failure();
    }

    $schema = $input->getChatStructuredJsonSchema();
    if (
      !is_array($schema)
      || ($schema['name'] ?? NULL) !== 'activity_capture_extraction'
      || ($schema['strict'] ?? NULL) !== TRUE
      || ($schema['schema'] ?? NULL) !== ActivityCaptureExtraction::structuredJsonSchema()
    ) {
      throw $this->failure();
    }
  }

  /**
   * Converts governed Terra provider failures into a terminal safe failure.
   */
  public function onAiException(AiExceptionEvent $event): void {
    if (!$this->isGovernedTerraRequest($event->getTags())) {
      return;
    }

    throw $this->failure($event->getException());
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // PHP_INT_MIN intentionally runs after ordinary recovery subscribers. This
    // makes their attempted mutations observable here and terminally rejected
    // before ProviderProxy can return a substitute output or invoke transport.
    return [
      PreGenerateResponseEvent::EVENT_NAME => ['onPreGenerate', PHP_INT_MIN],
      AiExceptionEvent::class => ['onAiException', PHP_INT_MIN],
    ];
  }

  /**
   * Returns whether this request belongs to governed Terra Activity Capture.
   *
   * @param string[] $tags
   *   Drupal AI request tags.
   */
  private function isGovernedTerraRequest(array $tags): bool {
    return in_array(ActivityCaptureInterpreter::GOVERNED_TERRA_REQUEST_TAG, $tags, TRUE);
  }

  /**
   * Builds the feature-safe terminal provider exception.
   */
  private function failure(?\Throwable $previous = NULL): ActivityCaptureProviderException {
    return new ActivityCaptureProviderException(
      ActivityCaptureInterpreter::GOVERNED_TERRA_FAILURE_MESSAGE,
      previous: $previous,
    );
  }

}