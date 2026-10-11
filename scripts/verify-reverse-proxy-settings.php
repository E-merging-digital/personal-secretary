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

// R13 / Task #238: synthetic TEST-NET peers only; NOT real Caddy trust.
// No live proxy IP, real HTTPS, Google endpoint or authentication is used.
$settingsFixture = static function (bool $ddev): array {
  $settings = [];
  $config = [];
  $databases = [];
  putenv('PERSONAL_SECRETARY_ENV=' . ($ddev ? 'development' : 'production'));
  putenv('IS_DDEV_PROJECT=' . ($ddev ? 'true' : 'false'));
  putenv('DRUPAL_REVERSE_PROXY_HOST=');
  include dirname(__DIR__) . '/web/sites/default/settings.php';
  return $settings;
};

$dev = $settingsFixture(TRUE);
$patterns = [
  '^.+\\.ddev\\.site$',
  '^localhost$',
  '^ps-dev\\.internal\\.emergingdigital\\.be$',
];
if (($dev['trusted_host_patterns'] ?? NULL) !== $patterns
  || array_key_exists('reverse_proxy', $dev)) {
  $fail('DEV exact trusted Host patterns or opt-in proxy baseline changed.');
}
// 192.0.2.10 / 198.51.100.10 are TEST-NET fixtures, never actual proxies.
$trustedTestPeer = '192.0.2.10';
$untrustedTestPeer = '198.51.100.10';
Request::setTrustedHosts($patterns);
Request::setTrustedProxies([$trustedTestPeer], Request::HEADER_X_FORWARDED_PROTO);

$makeSynthetic = static function (string $host, string $peer, array $headers = []): Request {
  return new Request([], [], [], [], [], array_replace([
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/personal-secretary/calendar/google',
    'SERVER_NAME' => 'ps-dev.internal.emergingdigital.be',
    'SERVER_PORT' => '80',
    'HTTP_HOST' => $host,
    'REMOTE_ADDR' => $peer,
  ], $headers));
};
$forged = [
  'HTTP_X_FORWARDED_PROTO' => 'https',
  'HTTP_X_FORWARDED_HOST' => 'personal-secretary.emergingdigital.be',
  'HTTP_X_FORWARDED_PORT' => '443',
  'HTTP_X_FORWARDED_FOR' => '203.0.113.57',
  'HTTP_FORWARDED' => 'for=203.0.113.88;host=personal-secretary.emergingdigital.be:443;proto=http',
];

$private = $makeSynthetic('ps-dev.internal.emergingdigital.be:8443', $trustedTestPeer, $forged);
if ($private->server->get('SERVER_PORT') !== '80'
  || Request::getTrustedHeaderSet() !== Request::HEADER_X_FORWARDED_PROTO
  || Request::getTrustedProxies() !== [$trustedTestPeer]
  || $private->getScheme() !== 'https'
  || $private->getHost() !== 'ps-dev.internal.emergingdigital.be'
  || $private->getPort() !== 8443
  || $private->getSchemeAndHttpHost() !== 'https://ps-dev.internal.emergingdigital.be:8443'
  || $private->getClientIp() !== $trustedTestPeer) {
  $fail('Synthetic trusted private :8443 origin or exclusive XFP header mask was violated.');
}

$untrustedPrivate = $makeSynthetic('ps-dev.internal.emergingdigital.be:8443', $untrustedTestPeer, $forged);
if ($untrustedPrivate->getScheme() !== 'http'
  || $untrustedPrivate->getClientIp() !== $untrustedTestPeer
  || $untrustedPrivate->getHost() !== 'ps-dev.internal.emergingdigital.be'
  || $untrustedPrivate->getPort() !== 8443) {
  $fail('Untrusted synthetic TEST-NET peer gained forwarded-header authority.');
}

foreach ([
  'unrelated.invalid',
  'personal-secretary.emergingdigital.be',
  'ps-dev.internal.emergingdigital.be.evil.invalid',
  'evil.ps-dev.internal.emergingdigital.be',
  'ps-devXinternal.emergingdigital.be',
] as $unknownHost) {
  $rejected = $makeSynthetic($unknownHost, $trustedTestPeer, $forged);
  try {
    $rejected->getHost();
    $fail('PROD, unknown or lookalike Host escaped DEV trusted-host rejection.');
  }
  catch (\Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException) {
    // Expected: Symfony trusted-host enforcement rejected this Host.
  }
}

// A legitimate hostname on port 443 is NOT the approved private :8443 origin.
$wrongPort = $makeSynthetic('ps-dev.internal.emergingdigital.be:443', $trustedTestPeer, $forged);
if ($wrongPort->getSchemeAndHttpHost() === 'https://ps-dev.internal.emergingdigital.be:8443') {
  $fail('Wrong public port was mistaken for private :8443.');
}

// No opt-in: neither DEV patterns nor spoofed forwarded proto affect PROD.
Request::setTrustedHosts([]);
Request::setTrustedProxies([], 0);
$defaultProd = $settingsFixture(FALSE);
if (array_key_exists('trusted_host_patterns', $defaultProd)
  || array_key_exists('reverse_proxy', $defaultProd)) {
  $fail('DEV trusted Hosts or proxy enablement leaked into default PROD.');
}
$untrustedDefault = $makeSynthetic('personal-secretary.emergingdigital.be', $trustedTestPeer, $forged);
if ($untrustedDefault->getScheme() !== 'http') {
  $fail('Forwarded proto affected default PROD without proxy opt-in.');
}
// Standalone verifier must leave Symfony global test state at its defaults.
Request::setTrustedHosts([]);
Request::setTrustedProxies([], 0);
echo "PRIVATE_8443_SYMFONY_ORIGIN=PASS\n";
echo "FORGED_HEADERS_AND_UNTRUSTED_PEER=REJECTED\n";
echo "DEV_TRUSTED_HOST_NEGATIVES=PASS\n";
echo "NO_PROXY_OPT_IN=PASS\n";

Request::setTrustedProxies([], 0);
echo "REVERSE_PROXY_SETTINGS=PASS\n";
echo "TRUSTED_HEADERS=X_FORWARDED_PROTO_ONLY\n";
echo "UNTRUSTED_HEADER_SPOOF_PROOF=PASS\n";
