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

/**
 * Production email transport.
 *
 * DDEV is intentionally excluded so local email keeps using Mailpit through
 * PHP mail() and DDEV's sendmail_path. In production, define
 * EMERGING_DIGITAL_SMTP_HOST and the variables documented in
 * docs/ticket-313-production-mail.md.
 */
$is_ddev = getenv('IS_DDEV_PROJECT') === 'true';
$smtp_host = getenv('EMERGING_DIGITAL_SMTP_HOST');
if (!$is_ddev && $smtp_host) {
  $smtp_encryption = getenv('EMERGING_DIGITAL_SMTP_ENCRYPTION') ?: 'tls';
  $smtp_encryption = strtolower($smtp_encryption);
  $smtp_from = getenv('EMERGING_DIGITAL_SMTP_FROM')
    ?: getenv('EMERGING_DIGITAL_SMTP_USER')
    ?: NULL;
  $smtp_options = [
    'auto_tls' => TRUE,
    'require_tls' => TRUE,
    'verify_peer' => TRUE,
  ];

  if (in_array($smtp_encryption, ['ssl', 'smtps'], TRUE)) {
    $smtp_scheme = 'smtps';
    $smtp_port = getenv('EMERGING_DIGITAL_SMTP_PORT') ?: 465;
  }
  else {
    $smtp_scheme = 'smtp';
    $smtp_port = getenv('EMERGING_DIGITAL_SMTP_PORT') ?: 587;

    if (in_array($smtp_encryption, ['none', 'false', '0'], TRUE)) {
      $smtp_options['auto_tls'] = FALSE;
      $smtp_options['require_tls'] = FALSE;
    }
  }

  $config['system.mail']['interface'] = [
    'default' => 'symfony_mailer',
  ];
  $config['system.mail']['mailer_dsn'] = [
    'scheme' => $smtp_scheme,
    'host' => $smtp_host,
    'user' => getenv('EMERGING_DIGITAL_SMTP_USER') ?: NULL,
    'password' => getenv('EMERGING_DIGITAL_SMTP_PASSWORD') ?: NULL,
    'port' => (int) $smtp_port,
    'options' => $smtp_options,
  ];

  $smtp_local_domain = getenv('EMERGING_DIGITAL_SMTP_LOCAL_DOMAIN');
  if ($smtp_local_domain) {
    $config['system.mail']['mailer_dsn']['options']['local_domain'] = $smtp_local_domain;
  }

  if ($smtp_from) {
    $handlers =& $config['webform.webform.contact']['handlers'];
    $notification_settings =& $handlers['email_notification']['settings'];
    $notification_settings['from_mail'] = $smtp_from;
    $notification_settings['sender_mail'] = $smtp_from;
    $notification_settings['return_path'] = $smtp_from;
  }
}

if (file_exists(__DIR__ . '/settings.local.php')) {
  require __DIR__ . '/settings.local.php';
}
