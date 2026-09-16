<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\personal_secretary\Value\ActivityCaptureInput;
use Drupal\personal_secretary\Value\ActivityCaptureProposal;

/**
 * Provider-neutral boundary for AI-assisted activity capture proposals.
 */
interface ActivityCaptureInterpreterInterface {

  public function interpret(ActivityCaptureInput $input): ActivityCaptureProposal;

}
