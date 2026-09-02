<?php

declare(strict_types=1);

/**
 * Repository-owned settings for synthetic bootstrap environments.
 */

$environment = getenv('PERSONAL_SECRETARY_ENV') ?: 'production';
$config['config_split.config_split.development']['status'] = $environment === 'development';

$settings['config_sync_directory'] = dirname(__DIR__, 3) . '/config/sync';

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
