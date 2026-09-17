<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\personal_secretary\Value\ActivityCaptureExtraction;
use Drupal\personal_secretary\Value\ActivityCaptureInput;

/**
 * Provider-neutral boundary for one narrow AI linguistic extraction.
 */
interface ActivityCaptureInterpreterInterface {

  public function interpret(ActivityCaptureInput $input): ActivityCaptureExtraction;

}
