<?php

/**
 * Base Drupal settings scaffold.
 *
 * Keep this include first to preserve Drupal defaults and documentation.
 */
require __DIR__ . '/default.settings.php';

/**
 * Project-wide paths shared by all environments.
 */
$settings['config_sync_directory'] = '../config/sync';
$settings['file_private_path'] = '../private';

// Disable production-only configuration split in local/DDEV.
$config['config_split.config_split.production']['status'] = FALSE;

/**
 * Include environment-specific overrides in a predictable order:
 * 1) DDEV managed settings (project-level local container environment).
 * 2) Developer local overrides (machine-level, never versioned).
 */
if (file_exists(__DIR__ . '/settings.ddev.php')) {
  include __DIR__ . '/settings.ddev.php';
}

// Let Drupal/Guzzle trust the HTTPS certificate served by the DDEV router.
if (getenv('IS_DDEV_PROJECT') === 'true') {
  $ddev_project = getenv('DDEV_PROJECT');
  $ddev_project_cert = $ddev_project
    ? '/mnt/ddev_config/traefik/certs/' . $ddev_project . '.crt'
    : '';
  $ddev_caroot = getenv('CAROOT') ?: '/mnt/ddev-global-cache/mkcert';
  $ddev_root_ca = $ddev_caroot . '/rootCA.pem';

  if (is_readable($ddev_project_cert)) {
    $settings['http_client_config']['verify'] = $ddev_project_cert;
  }
  elseif (is_readable($ddev_root_ca)) {
    $settings['http_client_config']['verify'] = $ddev_root_ca;
  }
}

if (file_exists(__DIR__ . '/settings.local.php')) {
  require __DIR__ . '/settings.local.php';
}
