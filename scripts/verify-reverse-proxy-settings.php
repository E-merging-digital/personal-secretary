#!/usr/bin/env php
<?php

/**
 * @file
 * Verifies the bounded reverse-proxy trust contract.
 */

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

putenv('PERSONAL_SECRETARY_ENV=production');
putenv('IS_DDEV_PROJECT=false');
putenv('DRUPAL_HASH_SALT=synthetic-reverse-proxy-verification');
putenv('DRUPAL_REVERSE_PROXY_HOST=localhost');

$settings = [];
include dirname(__DIR__) . '/web/sites/default/settings.php';

$fail = static function (string $message): never {
  fwrite(STDERR, $message . PHP_EOL);
  exit(1);
};

if (($settings['reverse_proxy'] ?? NULL) !== TRUE) {
  $fail('Reverse proxy support was not enabled by the deployment opt-in.');
}

$trustedProxies = $settings['reverse_proxy_addresses'] ?? [];
if (!is_array($trustedProxies) || !in_array('127.0.0.1', $trustedProxies, TRUE)) {
  $fail('The configured reverse-proxy host did not resolve to the expected exact synthetic proxy address.');
}

$trustedHeaders = $settings['reverse_proxy_trusted_headers'] ?? NULL;
if ($trustedHeaders !== Request::HEADER_X_FORWARDED_PROTO) {
  $fail('Reverse proxy settings must trust X-Forwarded-Proto only.');
}

Request::setTrustedProxies($trustedProxies, $trustedHeaders);

$trusted = Request::create(
  'http://personal-secretary.emergingdigital.be/user',
  'GET',
  [],
  [],
  [],
  [
    'REMOTE_ADDR' => '127.0.0.1',
    'SERVER_PORT' => '80',
    'HTTP_HOST' => 'personal-secretary.emergingdigital.be',
    'HTTP_X_FORWARDED_PROTO' => 'https',
    'HTTP_X_FORWARDED_HOST' => 'forged.invalid',
    'HTTP_X_FORWARDED_PORT' => '8443',
    'HTTP_X_FORWARDED_FOR' => '203.0.113.99',
  ],
);

if ($trusted->getScheme() !== 'https') {
  $fail('A trusted proxy X-Forwarded-Proto=https header was not honored.');
}
if ($trusted->getHost() !== 'personal-secretary.emergingdigital.be') {
  $fail('An untrusted X-Forwarded-Host header became authoritative.');
}
if ($trusted->getPort() !== 443) {
  $fail('An untrusted X-Forwarded-Port header became authoritative.');
}
if ($trusted->getClientIp() !== '127.0.0.1') {
  $fail('An untrusted X-Forwarded-For header became authoritative.');
}

$untrusted = Request::create(
  'http://personal-secretary.emergingdigital.be/user',
  'GET',
  [],
  [],
  [],
  [
    'REMOTE_ADDR' => '127.0.0.2',
    'SERVER_PORT' => '80',
    'HTTP_HOST' => 'personal-secretary.emergingdigital.be',
    'HTTP_X_FORWARDED_PROTO' => 'https',
  ],
);
if ($untrusted->getScheme() !== 'http') {
  $fail('An untrusted source was able to spoof X-Forwarded-Proto.');
}

Request::setTrustedProxies([], 0);
echo "REVERSE_PROXY_SETTINGS=PASS\n";
echo "TRUSTED_HEADERS=X_FORWARDED_PROTO_ONLY\n";
echo "UNTRUSTED_HEADER_SPOOF_PROOF=PASS\n";
