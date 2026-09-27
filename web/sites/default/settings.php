<?php

/**
 * @file
 * Repository-owned settings for synthetic bootstrap environments.
 */

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Request;

$environment = getenv('PERSONAL_SECRETARY_ENV') ?: 'production';
$config['config_split.config_split.development']['status'] = $environment === 'development';

$settings['config_sync_directory'] = dirname(__DIR__, 3) . '/config/sync';


// Easy Encryption private decryption material must never be stored in the
// database or repository in production. Infrastructure supplies an external
// writable directory through this environment variable before real OAuth use.
$easy_encryption_private_key_directory = trim((string) (
  getenv('PERSONAL_SECRETARY_EASY_ENCRYPTION_PRIVATE_KEY_DIRECTORY') ?: ''
));
if ($easy_encryption_private_key_directory !== '') {
  $settings['easy_encryption']['private_key_directory'] =
    $easy_encryption_private_key_directory;
}



$reverse_proxy_host = trim((string) (getenv('DRUPAL_REVERSE_PROXY_HOST') ?: ''));
if ($reverse_proxy_host !== '') {
  $reverse_proxy_addresses = gethostbynamel($reverse_proxy_host);
  if ($reverse_proxy_addresses === FALSE || $reverse_proxy_addresses === []) {
    throw new RuntimeException('DRUPAL_REVERSE_PROXY_HOST must resolve to at least one IPv4 address.');
  }

  $reverse_proxy_addresses = array_values(array_unique(array_filter(
    $reverse_proxy_addresses,
    static fn(string $address): bool => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== FALSE,
  )));
  if ($reverse_proxy_addresses === []) {
    throw new RuntimeException('DRUPAL_REVERSE_PROXY_HOST resolved without a valid IPv4 address.');
  }

  $settings['reverse_proxy'] = TRUE;
  $settings['reverse_proxy_addresses'] = $reverse_proxy_addresses;
  $settings['reverse_proxy_trusted_headers'] = Request::HEADER_X_FORWARDED_PROTO;
}

if (getenv('IS_DDEV_PROJECT') === 'true') {
  $settings['hash_salt'] = 'personal-secretary-ddev-synthetic-bootstrap';

  $databases['default']['default'] = [
    'database' => 'db',
    'username' => 'db',
    'password' => 'db',
    'prefix' => '',
    'host' => 'db',
    'port' => '3306',
    'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
    'driver' => 'mysql',
  ];

  $settings['trusted_host_patterns'] = [
    '^.+\\.ddev\\.site$',
    '^localhost$',
  ];
}
else {
  $hash_salt = getenv('DRUPAL_HASH_SALT');
  if (!$hash_salt) {
    throw new RuntimeException('DRUPAL_HASH_SALT is required outside DDEV.');
  }
  $settings['hash_salt'] = $hash_salt;
}

$local_settings = __DIR__ . '/settings.local.php';
if (is_file($local_settings)) {
  include $local_settings;
}
