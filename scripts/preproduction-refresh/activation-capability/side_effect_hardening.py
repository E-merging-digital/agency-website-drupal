#!/usr/bin/python3 -I
"""Fixed #874 PREPROD candidate side-effect hardening.

This module composes the canonical #859/#866 sanitizer and changes only the
small fixed set of active Drupal configuration values that belong to PREPROD
side-effect isolation. It does not anonymize users, purge Webform/session/log
state, clear queues, or remove credentials; those remain exclusively owned by
the pinned agency-preprod-staging-sanitizer.py + agency-preprod-refresh-v1.
"""
from __future__ import annotations

import hashlib
import json
import re
import subprocess
from typing import Any, Mapping

PHP_BIN = "/usr/bin/php8.4"
HARDENING_PROFILE_ID = "agency-preprod-refresh-side-effects-v1"
RUNTIME_VALIDATOR = "/var/www/agency-preprod/current/scripts/preproduction/validate-runtime.sh"
CLEAN_ENV = {"PATH": "/usr/bin:/bin", "HOME": "/root", "LC_ALL": "C"}
HEX_RE = re.compile(r"^[0-9A-Fa-f]+$")

REQUIRED_SANITIZER_EVIDENCE = (
    "auth_material_invalidation",
    "queues_purge",
    "runtime_state_reset",
    "credential_state_removal",
)

# Only #874-owned side-effect config is changed here. No sensitive-data
# sanitization semantic is duplicated.
CONFIG_UPDATES: dict[str, tuple[tuple[tuple[str, ...], Any], ...]] = {
    "automated_cron.settings": ((('interval',), 0),),
    "config_split.config_split.production": ((('status',), False),),
    "config_split.config_split.preproduction": ((('status',), True),),
    "google_tag.settings": ((('default_google_tag_entity',), None),),
    "system.mail": (
        (("interface", "default"), "symfony_mailer"),
        (("mailer_dsn", "scheme"), "native"),
        (("mailer_dsn", "user"), None),
        (("mailer_dsn", "password"), None),
    ),
}

REQUIRED_RUNTIME_STATES = {
    "production_config_split": "OFF",
    "preproduction_config_split": "ON",
    "google_tag": "OFF",
    "mail_transport": "NATIVE_NULL_CREDENTIALS",
    "automated_cron": "OFF",
    "openai_key": "ABSENT",
    "external_ai_egress": "BLOCKED_BY_SERVER_OWNED_SETTINGS",
    "production_webhook_api_credentials": "ABSENT_BY_CANONICAL_SANITIZER",
    "external_action_queues": "CLEARED_BY_CANONICAL_SANITIZER",
    "candidate_egress_before_runtime": "NONE_BY_RUNTIME_ISOLATION",
}

PHP_TRANSFORM = r'''
$payload=json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$raw=hex2bin($payload['hex']);
if ($raw === false) { exit(80); }
$data=@unserialize($raw, ['allowed_classes'=>false]);
if (!is_array($data)) { exit(81); }
foreach ($payload['updates'] as $update) {
  $path=$update['path'];
  if (!is_array($path) || count($path) < 1) { exit(82); }
  $cursor=&$data;
  $last=array_pop($path);
  foreach ($path as $part) {
    if (!is_string($part) || $part === '') { exit(83); }
    if (!array_key_exists($part, $cursor) || !is_array($cursor[$part])) {
      $cursor[$part]=[];
    }
    $cursor=&$cursor[$part];
  }
  $cursor[$last]=$update['value'];
  unset($cursor);
}
echo bin2hex(serialize($data));
'''

PHP_PROBE = r'''
$payload=json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$raw=hex2bin($payload['hex']);
if ($raw === false) { exit(80); }
$data=@unserialize($raw, ['allowed_classes'=>false]);
if (!is_array($data)) { exit(81); }
$out=[];
foreach ($payload['paths'] as $path) {
  $cursor=$data;
  $found=true;
  foreach ($path as $part) {
    if (!is_array($cursor) || !array_key_exists($part, $cursor)) { $found=false; break; }
    $cursor=$cursor[$part];
  }
  $out[]=['found'=>$found, 'value'=>$found ? $cursor : null];
}
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
'''


class HardeningError(RuntimeError):
    """Fail-closed side-effect hardening violation."""


def hardening_profile_payload() -> dict[str, object]:
    return {
        "profile_id": HARDENING_PROFILE_ID,
        "canonical_sanitizer_ownership": "REUSED_NOT_COPIED",
        "preactivation_config_rewrite": "FIXED_SIDE_EFFECT_CONFIG_ONLY",
        "config_names": sorted(CONFIG_UPDATES),
        "runtime_validator": RUNTIME_VALIDATOR,
        "required_runtime_states": REQUIRED_RUNTIME_STATES,
        "candidate_runtime_reachable": False,
    }


def hardening_profile_sha256() -> str:
    raw = json.dumps(hardening_profile_payload(), sort_keys=True, separators=(",", ":")).encode()
    return hashlib.sha256(raw).hexdigest()


def _php(script: str, payload: Mapping[str, Any]) -> str:
    result = subprocess.run(
        [PHP_BIN, "-d", "display_errors=0", "-r", script],
        input=json.dumps(payload, separators=(",", ":")),
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        text=True,
        check=False,
        env=CLEAN_ENV,
    )
    if result.returncode != 0:
        raise HardeningError("Fixed PHP config transform/probe failed.")
    return result.stdout.strip()


def _serialized_hex_transform(raw_hex: str, updates: tuple[tuple[tuple[str, ...], Any], ...]) -> str:
    if not raw_hex or not HEX_RE.fullmatch(raw_hex) or len(raw_hex) % 2:
        raise HardeningError("Active config serialization is invalid.")
    payload = {
        "hex": raw_hex,
        "updates": [{"path": list(path), "value": value} for path, value in updates],
    }
    transformed = _php(PHP_TRANSFORM, payload)
    if not transformed or not HEX_RE.fullmatch(transformed) or len(transformed) % 2:
        raise HardeningError("Hardened config serialization is invalid.")
    return transformed.lower()


def _serialized_probe(raw_hex: str, paths: list[tuple[str, ...]]) -> list[dict[str, Any]]:
    payload = {"hex": raw_hex, "paths": [list(path) for path in paths]}
    try:
        result = json.loads(_php(PHP_PROBE, payload))
    except json.JSONDecodeError as exc:
        raise HardeningError("Hardened config probe is invalid.") from exc
    if not isinstance(result, list) or len(result) != len(paths):
        raise HardeningError("Hardened config probe shape is invalid.")
    return result


def _config_hex(database: str, name: str, sanitizer: Any) -> str:
    sanitizer.require_columns(database, "config", {"collection", "name", "data"}, required_table=True)
    ref = sanitizer.table_ref(database, "config")
    empty = sanitizer.sql_string("")
    encoded_name = sanitizer.sql_string(name)
    count = sanitizer.run_sql(
        f"SELECT COUNT(*) FROM {ref} WHERE collection={empty} AND name={encoded_name}"
    )
    if count != "1":
        raise HardeningError("Required side-effect config is absent or ambiguous.")
    value = sanitizer.run_sql(
        f"SELECT HEX(data) FROM {ref} WHERE collection={empty} AND name={encoded_name}"
    )
    if not value or not HEX_RE.fullmatch(value) or len(value) % 2:
        raise HardeningError("Required side-effect config serialization is invalid.")
    return value


def _store_config_hex(database: str, name: str, raw_hex: str, sanitizer: Any) -> None:
    if not HEX_RE.fullmatch(raw_hex):
        raise HardeningError("Unsafe hardened config bytes.")
    ref = sanitizer.table_ref(database, "config")
    empty = sanitizer.sql_string("")
    encoded_name = sanitizer.sql_string(name)
    sanitizer.run_sql(
        f"UPDATE {ref} SET data=UNHEX('{raw_hex}') WHERE collection={empty} AND name={encoded_name}"
    )


def harden_candidate(database: str, sanitizer: Any, sanitizer_evidence: Mapping[str, str]) -> dict[str, str]:
    """Apply only the fixed #874 PREPROD side-effect config hardening."""
    for key in REQUIRED_SANITIZER_EVIDENCE:
        if str(sanitizer_evidence.get(key, "")) != "PASS":
            raise HardeningError(f"Canonical sanitizer evidence missing: {key}")

    for name, updates in CONFIG_UPDATES.items():
        current_hex = _config_hex(database, name, sanitizer)
        hardened_hex = _serialized_hex_transform(current_hex, updates)
        _store_config_hex(database, name, hardened_hex, sanitizer)

    assertion = assert_candidate_hardened(database, sanitizer, sanitizer_evidence)
    assertion.update({
        "side_effect_hardening": "PASS",
        "hardening_profile_id": HARDENING_PROFILE_ID,
        "hardening_profile_sha256": hardening_profile_sha256(),
        "candidate_egress_before_runtime": "NONE",
        "runtime_side_effect_validation_required": "YES",
    })
    return assertion


def assert_candidate_hardened(database: str, sanitizer: Any, sanitizer_evidence: Mapping[str, str]) -> dict[str, str]:
    """Re-read only fixed non-secret target values and fail closed on mismatch."""
    for key in REQUIRED_SANITIZER_EVIDENCE:
        if str(sanitizer_evidence.get(key, "")) != "PASS":
            raise HardeningError(f"Canonical sanitizer evidence missing: {key}")
    for name, updates in CONFIG_UPDATES.items():
        current_hex = _config_hex(database, name, sanitizer)
        paths = [path for path, _ in updates]
        probes = _serialized_probe(current_hex, paths)
        for probe, (_, expected) in zip(probes, updates, strict=True):
            if probe.get("found") is not True or probe.get("value") != expected:
                raise HardeningError("Fixed PREPROD side-effect assertion failed.")
    return {
        "production_config_split": "OFF",
        "preproduction_config_split": "ON",
        "google_tag": "OFF",
        "mail_transport": "NATIVE_NULL_CREDENTIALS",
        "automated_cron": "OFF",
        "provider_credential_state": "ABSENT_BY_CANONICAL_SANITIZER",
        "external_action_queues": "CLEARED_BY_CANONICAL_SANITIZER",
    }
