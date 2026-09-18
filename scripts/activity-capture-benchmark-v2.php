<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$required = [
  'PS_ACTIVITY_CAPTURE_BENCHMARK_V2_LIVE',
  'PS_ACTIVITY_CAPTURE_PROVIDER',
  'PS_ACTIVITY_CAPTURE_MODEL',
  'PS_ACTIVITY_CAPTURE_BENCHMARK_HOST',
  'PS_ACTIVITY_CAPTURE_BENCHMARK_PORT',
  'PS_ACTIVITY_CAPTURE_BENCHMARK_RESULT',
];
foreach ($required as $name) {
  if (trim((string) getenv($name)) === '') {
    throw new RuntimeException($name . ' is required.');
  }
}

$command = [
  PHP_BINARY,
  $root . '/vendor/bin/phpunit',
  '-c',
  $root . '/web/core/phpunit.xml.dist',
  '--filter',
  'testLiveV2Benchmark',
  $root . '/web/modules/custom/personal_secretary/tests/src/Kernel/ActivityCaptureBenchmarkV2KernelTest.php',
];
$escaped = implode(' ', array_map('escapeshellarg', $command));
passthru($escaped, $status);
exit($status);
