<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$mode = $argv[1] ?? '';
$filters = [
  'parity' => 'testExecutionSupportParity',
  'local' => 'testLiveMinistralV3Benchmark',
  'score-openai' => 'testScoreOpenAiV3Benchmark',
];
if (!isset($filters[$mode])) {
  fwrite(STDERR, "Usage: php scripts/activity-capture-provider-v3.php parity|local|score-openai\n");
  exit(2);
}

$command = [
  PHP_BINARY,
  $root . '/vendor/bin/phpunit',
  '-c',
  $root . '/web/core/phpunit.xml.dist',
  '--filter',
  $filters[$mode],
  $root . '/web/modules/custom/personal_secretary/tests/src/Kernel/ActivityCaptureBenchmarkV3ProviderKernelTest.php',
];
passthru(implode(' ', array_map('escapeshellarg', $command)), $status);
exit($status);
