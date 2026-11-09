#!/usr/bin/env bash
# Exact read-only PREPROD collector, sent over the existing pinned Agency SSH identity.
set -euo pipefail

[[ "$(id -un)" == 'agency-preprod' ]] || exit 71
[[ -r /var/www/agency-preprod/current/vendor/autoload.php ]] || exit 72

# Do not bootstrap Drupal or open a database connection. The existing provider
# reads only release metadata and project-owned terminal receipt files.
php <<'PHP'
<?php
declare(strict_types=1);

$release = realpath('/var/www/agency-preprod/current');
if ($release === false || preg_match('~^/var/www/agency-preprod/releases/[0-9]{14}-[0-9a-f]{12,40}$~', $release) !== 1) {
  exit(73);
}
$appRoot = realpath($release . '/web');
if ($appRoot !== $release . '/web') {
  exit(74);
}

require $release . '/vendor/autoload.php';
foreach (['CapabilityRegistryReader', 'RuntimeMetadataReader', 'EnvironmentDataStateProvider'] as $class) {
  require $appRoot . '/modules/custom/agency_operations/src/Service/' . $class . '.php';
}

// The host is asserted by the fixed PREPROD release root above, then supplied
// to the same host-aware reader used by the HTTP controller.
$requests = new \Symfony\Component\HttpFoundation\RequestStack();
$requests->push(\Symfony\Component\HttpFoundation\Request::create('https://preprod.emergingdigital.be/'));
$provider = new \Drupal\agency_operations\Service\EnvironmentDataStateProvider(
  $appRoot,
  new \Drupal\agency_operations\Service\RuntimeMetadataReader($appRoot, $requests),
  new \Drupal\agency_operations\Service\CapabilityRegistryReader($appRoot),
);
$state = $provider->read();
if (($state['runtime']['environment'] ?? null) !== 'PREPROD'
  || ($state['runtime']['release_available'] ?? null) !== true) {
  exit(75);
}
echo json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
PHP
