#!/usr/bin/env php
<?php

/**
 * @file
 * Tests exact DDEV-only host admission without network or provider actions.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

$fail = static function (string $message): never {
  fwrite(STDERR, "PRIVATE_DEV_HOST=STOP: {$message}\n");
  exit(1);
};

$load = static function (bool $ddev) use ($root): array {
  $settings = [];
  $config = [];
  $databases = [];
  putenv('IS_DDEV_PROJECT=' . ($ddev ? 'true' : 'false'));
  putenv('PERSONAL_SECRETARY_ENV=' . ($ddev ? 'development' : 'production'));
  putenv('DRUPAL_HASH_SALT=synthetic-private-host-test-only');
  putenv('DRUPAL_REVERSE_PROXY_HOST=');
  include $root . '/web/sites/default/settings.php';
  return $settings;
};

$dev = $load(TRUE);
$patterns = $dev['trusted_host_patterns'] ?? NULL;
if (!is_array($patterns) || count($patterns) !== 3) {
  $fail('DDEV trusted-host allowlist must contain precisely three patterns.');
}

$expected = [
  '^.+\\.ddev\\.site$',
  '^localhost$',
  '^ps-dev\\.internal\\.emergingdigital\\.be$',
];
if ($patterns !== $expected) {
  $fail('DDEV trusted-host patterns differ from the exact approved contract.');
}

$allowed = static function (string $host) use ($patterns): bool {
  foreach ($patterns as $pattern) {
    if (preg_match('~' . $pattern . '~D', $host) === 1) {
      return TRUE;
    }
  }
  return FALSE;
};

foreach ([
  'personal-secretary.ddev.site',
  'another.ddev.site',
  'localhost',
  'ps-dev.internal.emergingdigital.be',
] as $host) {
  if (!$allowed($host)) {
    $fail("Expected development Host refused: {$host}");
  }
}

foreach ([
  'personal-secretary.emergingdigital.be',
  'evil.example.net',
  'ps-dev.internal.emergingdigital.be.evil.example.net',
  'evil.ps-dev.internal.emergingdigital.be',
  'ps-devXinternal.emergingdigital.be',
] as $host) {
  if ($allowed($host)) {
    $fail("Unrelated or spoofed Host accepted: {$host}");
  }
}

$prod = $load(FALSE);
if (array_key_exists('trusted_host_patterns', $prod)) {
  $fail('DEV-only trusted-host patterns leaked into non-DDEV settings.');
}
if (array_key_exists('reverse_proxy', $prod)) {
  $fail('Reverse-proxy trust activated without explicit production opt-in.');
}

echo "PRIVATE_DEV_HOST=PASS / DDEV_ONLY / PROD_UNCHANGED\n";
