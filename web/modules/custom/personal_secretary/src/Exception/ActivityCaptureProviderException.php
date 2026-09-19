<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Exception;

/**
 * Safe failure surfaced when governed Activity Capture provider use fails.
 */
final class ActivityCaptureProviderException extends \RuntimeException {}