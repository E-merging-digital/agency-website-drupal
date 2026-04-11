<?php

/**
 * Base Drupal settings scaffold.
 *
 * Keep this include first to preserve Drupal defaults and documentation.
 */
require __DIR__ . '/default.settings.php';

/**
 * Shared project configuration paths.
 */
$settings['config_sync_directory'] = '../config/sync';
$settings['file_private_path'] = '../private';

/**
 * Load DDEV-generated settings, if available.
 */
if (file_exists(__DIR__ . '/settings.ddev.php')) {
  include __DIR__ . '/settings.ddev.php';
}

/**
 * Load local development override configuration, if available.
 */
if (file_exists(__DIR__ . '/settings.local.php')) {
  require __DIR__ . '/settings.local.php';
}
