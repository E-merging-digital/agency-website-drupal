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

/**
 * Include environment-specific overrides in a predictable order:
 * 1) DDEV managed settings (project-level local container environment).
 * 2) Developer local overrides (machine-level, never versioned).
 */
if (file_exists(__DIR__ . '/settings.ddev.php')) {
  include __DIR__ . '/settings.ddev.php';
}

if (file_exists(__DIR__ . '/settings.local.php')) {
  require __DIR__ . '/settings.local.php';
}
