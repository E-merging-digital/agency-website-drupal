<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(DRUPAL_ROOT);
$configDirectory = $projectRoot . '/config/sync';
$manifestPath = $projectRoot
  . '/docs/evidence/configuration-language-canvas-migration-plan-779.yml';
$expectedPlanSha = '8627ce9ec73a45f5501a410e15c4e56fcbc45239ee2c1c166e5d5445337e1a24';
$expectedDistribution = [
  '__none__' => 59,
  'en' => 496,
  'fr' => 39,
  'und' => 1,
];
$targetHashes = [
  'canvas.component.block.announce_block' => '3b56214758ddaf1c79dc6085235eee7ff0a8f4155a28f402992dc6e17b7dbe85',
  'canvas.component.block.emerging_digital_language_switcher' => '405231a6588503962fdb6472209aa4b28bf3b512566bd07faf4595faaca60d8f',
  'canvas.component.block.help_block' => 'e750abe5aaa16db1ff17d7fad5be70b42e9e78953cc7301ec36b91b08b10e354',
  'canvas.component.block.language_block.language_content' => '9603637de8e7605fde38e945ee2e41f75321a2d9f5272c0ede1c3816066d5deb',
  'canvas.component.block.language_block.language_interface' => '6b214b457d4723ec50daaaaaa9e1c3c89fe9e3e3f8d02b3019e67120b387a5d5',
  'canvas.component.block.local_actions_block' => 'ea1ff53f09b1a727d64a23f5106df74c1abebfe99f41604e5e9adeda4f047bcd',
  'canvas.component.block.local_tasks_block' => '9946d83e1a72761bab087a14729823ccb5b440a51c665166423a15c10b089be3',
  'canvas.component.block.page_title_block' => '5b0f786e5b3f4fd679fbf48481707245ec9414d00715825402aff3f259c0175e',
  'canvas.component.block.shortcuts' => 'a44002b9abf907b9c87d259a020560a7293fc9020f8adfa51235bebbc073bd1b',
  'canvas.component.block.system_branding_block' => '79b0ab88edab49dd1bdfa3c97d3597c841946c87084ed41b7ce626f51d466de2',
  'canvas.component.block.system_breadcrumb_block' => '10d354ab94a764f3bb56c754584ebe498f0495c7ecb135797f68e1e275002ef9',
  'canvas.component.block.system_clear_cache_block' => 'c3b2b3b4bc5164df32084d2f549e29cc661f356096f7fc37dd2f8fe357117de9',
  'canvas.component.block.system_menu_block.account' => 'c9da6b2c7b1a0c728391650a9f3fafdc7476d082c00a9d2da658201e37799ce6',
  'canvas.component.block.system_menu_block.admin' => '556a0dd01101f4cfa8a33bd8288f2f6d6a6ce13a0520409c4026608ade7ca177',
  'canvas.component.block.system_menu_block.footer' => '235c69c8f8e111d6a20f789546631625ff834db48af454f73033aa04e4a95c30',
  'canvas.component.block.system_menu_block.main' => 'bf686cb87e194b57bae2df1250ab86409650a2b59f43f031dbd2c65a4e1818bd',
  'canvas.component.block.system_menu_block.online-presence' => '02643a2e8b56f36a90aefa26c165467f94877841239a93ada95f4b5991aa16fb',
  'canvas.component.block.system_menu_block.tools' => '9a4e7c214321f305cdd6f001c23e8f5f5806d98eab26b4d25a6388ad18512d9f',
  'canvas.component.block.system_messages_block' => 'c6a04b67ee2fe726c30469e8d23d922d7057df4f6f9a7186aac6c4d38410bc3f',
  'canvas.component.block.system_powered_by_block' => '318f5047748a073bff487bb5d77e6311c4ad7d5e843d4e21afb65418b3325f1c',
  'canvas.component.block.user_login_block' => '65e7c510ffb6e21920daf34165faa0c2e41712745535a11ccc79bc40e83db9b5',
  'canvas.component.block.views_block.comments_recent-block_1' => 'd71522160e0a580f3a9046b8ca16ed4de591d2e2695aa27c66da8b6d8d2e3dea',
  'canvas.component.block.views_block.content_recent-block_1' => '97a0dd80cca264c34d7cd236c04e79e1f6e023bb5bf72eec31713c8687af468e',
  'canvas.component.block.views_block.who_s_new-block_1' => '36005a46e7d21f73e118b713f00f4c21b00eb0838c27f554215d91e01271e486',
  'canvas.component.block.views_block.who_s_online-who_s_online_block' => 'dced6909ceb73ef4dbf6643d6791ad2766820cc17e056828d9395d9dfe50bfc3',
  'canvas.component.block.webform_block' => 'dce3415b948df6d5857d2458eb047dec1b315259a5fc89cf81f2c58eec2311f7',
  'canvas.component.sdc.emerging_digital.cta' => 'a3beaff1a3ddb2a0e777f1f3ab48deed909ebfbbc138f9ea801fe9079f51ec52',
  'canvas.component.sdc.emerging_digital.hero' => '711bd843840b2244bcac10243761431125d4d4b2aaf4ea180a653b5f2f6516c3',
  'canvas.component.sdc.emerging_digital.trust-list' => '3439a706839df4462caa8cd47445bc065c78f0f9cafa57aa75e723b60cd04053',
  'canvas.component.sdc.olivero.teaser' => 'b9ee199468bfef78a6e500fdc83f2daa0dfdf41b97ec8132fc2a76a319964bde',
];

if (!is_dir($configDirectory) || !is_file($manifestPath)) {
  throw new RuntimeException('Issue #781 verification prerequisites are unavailable.');
}
$manifest = Yaml::parseFile($manifestPath);
if (
  !is_array($manifest)
  || ($manifest['source']['plan_sha256'] ?? NULL) !== $expectedPlanSha
  || ($manifest['target']['distribution'] ?? NULL) !== $expectedDistribution
  || ($manifest['cohort']['total'] ?? NULL) !== 30
  || ($manifest['target']['fr_overrides_created'] ?? NULL) !== 15
) {
  throw new RuntimeException('Issue #781 verification contract drifted.');
}

$names = $manifest['cohort']['names'] ?? NULL;
$labelTargets = $manifest['block_label_targets'] ?? NULL;
$sdcNames = $manifest['sdc_names'] ?? NULL;
if (!is_array($names) || count($names) !== 30
  || !is_array($labelTargets) || count($labelTargets) !== 15
  || !is_array($sdcNames) || count($sdcNames) !== 4) {
  throw new RuntimeException('Issue #781 verification membership is incomplete.');
}
$names = array_values(array_map('strval', $names));
sort($names, SORT_STRING);
if (hash('sha256', implode("\n", $names) . "\n") !== ($manifest['cohort']['names_sha256'] ?? NULL)
  || array_keys($targetHashes) !== $names) {
  throw new RuntimeException('Issue #781 target hash membership mismatch.');
}

$canonicalize = NULL;
$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
  if (!is_array($value)) {
    return $value;
  }
  if (array_is_list($value)) {
    return array_map($canonicalize, $value);
  }
  ksort($value, SORT_STRING);
  foreach ($value as $key => $child) {
    $value[$key] = $canonicalize($child);
  }
  return $value;
};
$semanticHash = static function (array $data) use ($canonicalize): string {
  return hash(
    'sha256',
    json_encode(
      $canonicalize($data),
      JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ),
  );
};
$countDistribution = static function (iterable $names, callable $read): array {
  $counts = [];
  $total = 0;
  foreach ($names as $name) {
    $data = $read($name);
    if (!is_array($data)) {
      throw new RuntimeException('Unable to read configuration for distribution: ' . $name);
    }
    $total++;
    $langcode = isset($data['langcode']) && is_string($data['langcode'])
      ? $data['langcode']
      : '__none__';
    $counts[$langcode] = ($counts[$langcode] ?? 0) + 1;
  }
  ksort($counts, SORT_STRING);
  return [$total, $counts];
};

$repositoryFiles = glob($configDirectory . '/*.yml');
if ($repositoryFiles === FALSE) {
  throw new RuntimeException('Unable to enumerate repository configuration for #781.');
}
sort($repositoryFiles, SORT_STRING);
[$repositoryTotal, $repositoryDistribution] = $countDistribution(
  array_map(static fn(string $path): string => basename($path, '.yml'), $repositoryFiles),
  static fn(string $name): mixed => Yaml::parseFile($configDirectory . '/' . $name . '.yml'),
);
if ($repositoryTotal !== 595 || $repositoryDistribution !== $expectedDistribution) {
  throw new RuntimeException('Issue #781 repository distribution is not exact.');
}

$activeStorage = \Drupal::service('config.storage');
$activeNames = $activeStorage->listAll();
sort($activeNames, SORT_STRING);
[$activeTotal, $activeDistribution] = $countDistribution(
  $activeNames,
  static fn(string $name): mixed => $activeStorage->read($name),
);
if ($activeTotal !== 595 || $activeDistribution !== $expectedDistribution) {
  throw new RuntimeException('Issue #781 active distribution is not exact.');
}
$activeFr = $activeStorage->createCollection('language.fr');

$verified = 0;
$verifiedOverrides = 0;
$items = [];
foreach ($names as $configName) {
  $repositoryPath = $configDirectory . '/' . $configName . '.yml';
  $repositoryData = Yaml::parseFile($repositoryPath);
  $activeData = $activeStorage->read($configName);
  if (!is_array($repositoryData) || !is_array($activeData)) {
    throw new RuntimeException('Issue #781 target config is missing: ' . $configName);
  }
  $expectedHash = $targetHashes[$configName] ?? NULL;
  if (($repositoryData['langcode'] ?? NULL) !== 'en'
    || ($activeData['langcode'] ?? NULL) !== 'en'
    || $semanticHash($repositoryData) !== $expectedHash
    || $semanticHash($activeData) !== $expectedHash) {
    throw new RuntimeException('Issue #781 target semantic state mismatch: ' . $configName);
  }

  $hasExpectedOverride = array_key_exists($configName, $labelTargets);
  $overridePath = $configDirectory . '/language/fr/' . $configName . '.yml';
  $activeOverride = $activeFr->read($configName);
  if ($hasExpectedOverride) {
    $target = $labelTargets[$configName];
    $baseLabel = is_array($target) ? ($target['base'] ?? NULL) : NULL;
    $frLabel = is_array($target) ? ($target['fr'] ?? NULL) : NULL;
    if (!is_string($baseLabel) || !is_string($frLabel)
      || ($repositoryData['label'] ?? NULL) !== $baseLabel
      || ($repositoryData['versioned_properties']['active']['settings']['default_settings']['label'] ?? NULL) !== $baseLabel
      || ($activeData['label'] ?? NULL) !== $baseLabel
      || ($activeData['versioned_properties']['active']['settings']['default_settings']['label'] ?? NULL) !== $baseLabel
      || !is_file($overridePath)) {
      throw new RuntimeException('Issue #781 base/FR label target mismatch: ' . $configName);
    }
    $repositoryOverride = Yaml::parseFile($overridePath);
    $expectedOverride = [
      'label' => $frLabel,
      'versioned_properties' => [
        'active' => [
          'settings' => [
            'default_settings' => [
              'label' => $frLabel,
            ],
          ],
        ],
      ],
    ];
    if (!is_array($repositoryOverride)
      || $canonicalize($repositoryOverride) !== $canonicalize($expectedOverride)
      || !is_array($activeOverride)
      || $canonicalize($activeOverride) !== $canonicalize($expectedOverride)) {
      throw new RuntimeException('Issue #781 FR override mismatch: ' . $configName);
    }
    $verifiedOverrides++;
  }
  else {
    if (is_file($overridePath) || $activeOverride !== FALSE) {
      throw new RuntimeException('Issue #781 found an unexpected FR override: ' . $configName);
    }
  }

  $items[] = [
    'config_name' => $configName,
    'source_kind' => in_array($configName, $sdcNames, TRUE) ? 'sdc' : 'block',
    'semantic_sha256' => $expectedHash,
    'fr_override_verified' => $hasExpectedOverride,
  ];
  $verified++;
}

$coreExtension = Yaml::parseFile($configDirectory . '/core.extension.yml');
$systemSite = Yaml::parseFile($configDirectory . '/system.site.yml');
if (!is_array($coreExtension)
  || isset($coreExtension['module']['config_language_lock'])
  || is_file($configDirectory . '/config_language_lock.settings.yml')
  || \Drupal::moduleHandler()->moduleExists('config_language_lock')
  || !is_array($systemSite)
  || ($systemSite['default_langcode'] ?? NULL) !== 'fr'
  || (($activeStorage->read('system.site')['default_langcode'] ?? NULL) !== 'fr')) {
  throw new RuntimeException('Issue #781 lock/site-default verification failed.');
}

$result = [
  'schema_version' => 1,
  'status' => 'PASS',
  'verdict' => 'THIRTY_CANVAS_CANONICAL_PROMOTIONS_VERIFIED',
  'plan_sha256' => $expectedPlanSha,
  'verified' => $verified,
  'fr_overrides_verified' => $verifiedOverrides,
  'remaining_fr_review_required' => 39,
  'repository_total' => $repositoryTotal,
  'repository_distribution' => $repositoryDistribution,
  'active_total' => $activeTotal,
  'active_distribution' => $activeDistribution,
  'items' => $items,
  'problems' => [],
  'constraints' => [
    'target_semantic_hashes_verified' => TRUE,
    'historical_versions_preserved_by_target_hash' => TRUE,
    'sdc_values_preserved_by_target_hash' => TRUE,
    'config_language_lock_enabled_canonically' => FALSE,
    'system_site_default_langcode' => 'fr',
    'semantic_und_zxx_preserved' => TRUE,
    'config_export_used' => FALSE,
    'production_access_used' => FALSE,
    'provider_secret_used' => FALSE,
  ],
];

echo json_encode(
  $result,
  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
) . PHP_EOL;
