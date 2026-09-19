<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$command = [
  PHP_BINARY,
  $root . '/vendor/bin/phpunit',
  '-c',
  $root . '/web/core/phpunit.xml.dist',
  '--filter',
  'testV3FixtureContractAndOracleResolver',
  $root . '/web/modules/custom/personal_secretary/tests/src/Kernel/ActivityCaptureBenchmarkV3KernelTest.php',
];

$escaped = implode(' ', array_map('escapeshellarg', $command));
passthru($escaped, $status);
exit($status);
