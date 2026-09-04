#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path

ITEMS = (
    "staging_helper", "staging_sanitizer", "staging_policy",
    "helper", "bundle_dir", "side_effect_hardening", "runtime_state_digest",
    "capability_profile", "bundle_manifest", "state_dir", "incoming_dir",
    "candidates_dir", "backups_dir", "authority_state", "maintenance_marker",
    "sudoers", "nginx_snippets_dir", "nginx_conf_dir", "fence_snippet",
    "internal_readiness", "vhost", "deploy_lock", "refresh_lock",
    "current_release", "current_web",
)
FIELDS = ("state", "type", "owner", "group", "mode", "digest_state", "sha256")
VHOST_COUNT_KEYS = (
    "vhost_server_block_count",
    "vhost_hostname_declaration_count",
    "vhost_hostname_block_count",
    "vhost_application_block_count",
    "vhost_safe_auxiliary_block_count",
    "vhost_application_fence_include_count",
    "vhost_total_fence_include_count",
)
RUNTIME_DB_PROBE_STATES = {
    "OBSERVED",
    "RELEASE_UNAVAILABLE",
    "RELEASE_ACCESS_FAILED",
    "DRUSH_MISSING",
    "DRUSH_ACCESS_FAILED",
    "DRUSH_NOT_EXECUTABLE",
    "DRUSH_EXEC_FAILED",
    "DRUSH_FAILED",
    "OUTPUT_EMPTY",
    "OUTPUT_INVALID",
}
RUNTIME_DB_EXIT_CLASSES = {"ZERO", "NONZERO", "NOT_RUN"}
TOP_LEVEL = {
    "observer_schema", "execution_uid", "execution_gid", "execution_user_sha256",
    "host_identity_sha256", "sudoers_dir_searchable", "state_dir_searchable",
    "vhost_selector_schema", *VHOST_COUNT_KEYS,
    "runtime_release_target_sha256", "runtime_release_name",
    "runtime_db_probe_schema", "runtime_db_probe_state",
    "runtime_db_probe_exit_class", "runtime_db_name",
    "PLAN_MUTATION", "HELPER_EXECUTION", "SUDO_EXECUTION", "PROD_ACCESS",
    "PREPROD_DB_MUTATION", "PREPROD_BACKUP", "FENCE_MUTATION", "NGINX_MUTATION",
}
ALLOWED_KEYS = TOP_LEVEL | {f"{item}.{field}" for item in ITEMS for field in FIELDS}
HEX64 = re.compile(r"^[0-9a-f]{64}$")
SAFE_NAME = re.compile(r"^[A-Za-z0-9_.-]+$")
SAFE_DB_NAME = re.compile(r"^[A-Za-z0-9_]{1,64}$")
RELEASE_NAME = re.compile(r"^[0-9]{14}-[0-9a-f]{12}$")
UNOBSERVABLE = "UNOBSERVABLE_UNPRIVILEGED"


class EvidenceError(RuntimeError):
    pass


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def sha256_text(text: str) -> str:
    return hashlib.sha256(text.encode()).hexdigest()


def parse_observation(path: Path) -> dict[str, str]:
    result: dict[str, str] = {}
    for line_no, raw in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
        if not raw or "=" not in raw:
            raise EvidenceError(f"invalid observation line {line_no}")
        key, value = raw.split("=", 1)
        if key not in ALLOWED_KEYS:
            raise EvidenceError(f"unexpected observation key: {key}")
        if key in result:
            raise EvidenceError(f"duplicate observation key: {key}")
        if any(ch in value for ch in "\x00\r\n\t"):
            raise EvidenceError(f"unsafe observation value: {key}")
        result[key] = value
    missing = sorted(ALLOWED_KEYS - result.keys())
    if missing:
        raise EvidenceError("missing observation keys: " + ",".join(missing))
    return result


def build_expected(repo_root: Path) -> dict:
    base = repo_root / "scripts/preproduction-refresh/activation-capability"
    provisioning = base / "provisioning"
    profile = json.loads((provisioning / "profile.json").read_text())
    capability = json.loads((base / "profile.json").read_text())
    bundle = json.loads((base / "bundle.json").read_text())

    if profile.get("issue_number") != 874 or profile.get("parent_issue") != 816:
        raise EvidenceError("provisioning profile lineage mismatch")
    if profile.get("profile_id") != "agency-preprod-refresh-capability-provision-v1":
        raise EvidenceError("provisioning profile identity mismatch")
    if profile.get("apply", {}).get("data_activation_authority_after_apply") != "DISABLED":
        raise EvidenceError("data activation authority is not disabled")
    if profile.get("apply", {}).get("real_data_activation") != "FORBIDDEN":
        raise EvidenceError("real data activation is not forbidden")
    if capability.get("activation", {}).get("runtime_database") != "agency_preprod":
        raise EvidenceError("runtime database contract mismatch")
    if bundle.get("data_activation_authority_after_provisioning") != "DISABLED":
        raise EvidenceError("bundle authority contract mismatch")

    sources = {
        "helper": base / "agency-preprod-refresh-control",
        "side_effect_hardening": base / "side_effect_hardening.py",
        "runtime_state_digest": base / "runtime_state_digest.py",
        "disabled_authority_state": base / "data-activation-authority.disabled.json",
        "fence_snippet": base / "nginx/agency-preprod-refresh-fence.conf",
        "internal_readiness": base / "nginx/agency-preprod-refresh-internal-readiness.conf",
        "capability_profile": base / "profile.json",
    }
    source_digests = {}
    for key, source in sources.items():
        actual = sha256_file(source)
        pinned = profile["digests"][key]
        if actual != pinned:
            raise EvidenceError(f"source bundle digest mismatch: {key}")
        source_digests[key] = actual
    source_digests["bundle_manifest"] = sha256_file(base / "bundle.json")
    source_digests["sudoers"] = sha256_file(provisioning / "agency-preprod-refresh-control.sudoers")
    source_digests["provisioning_profile"] = sha256_file(provisioning / "profile.json")
    source_digests["observer"] = sha256_file(provisioning / "observe-host-state.sh")
    source_digests["evaluator"] = sha256_file(provisioning / "evaluate-plan-evidence.py")
    source_digests["vhost_selector"] = sha256_file(provisioning / "nginx-vhost-selector.py")
    source_digests["runtime_db_probe"] = sha256_file(provisioning / "runtime-db-identity-probe.py")

    return {
        "source_digests": source_digests,
        "staging": {
            "staging_helper": capability["canonical_sanitization"]["existing_helper_sha256"],
            "staging_sanitizer": capability["canonical_sanitization"]["sanitizer_sha256"],
            "staging_policy": capability["canonical_sanitization"]["policy_sha256"],
        },
        "runtime_db": capability["activation"]["runtime_database"],
        "deploy_user_hash": sha256_text(capability["sudoers"]["deploy_identity"]),
        "managed": {
            "helper": ("REGULAR", "root", "root", "755", source_digests["helper"]),
            "bundle_dir": ("DIRECTORY", "root", "root", "755", None),
            "side_effect_hardening": ("REGULAR", "root", "root", "644", source_digests["side_effect_hardening"]),
            "runtime_state_digest": ("REGULAR", "root", "root", "644", source_digests["runtime_state_digest"]),
            "capability_profile": ("REGULAR", "root", "root", "644", source_digests["capability_profile"]),
            "bundle_manifest": ("REGULAR", "root", "root", "644", source_digests["bundle_manifest"]),
            "state_dir": ("DIRECTORY", "root", "root", "711", None),
            "incoming_dir": ("DIRECTORY", "root", "root", "700", None),
            "candidates_dir": ("DIRECTORY", "root", "root", "700", None),
            "backups_dir": ("DIRECTORY", "root", "root", "700", None),
            "authority_state": ("REGULAR", "root", "root", "600", source_digests["disabled_authority_state"]),
            "sudoers": ("REGULAR", "root", "root", "440", source_digests["sudoers"]),
            "nginx_snippets_dir": ("DIRECTORY", "root", "root", "755", None),
            "nginx_conf_dir": ("DIRECTORY", "root", "root", "755", None),
            "fence_snippet": ("REGULAR", "root", "root", "644", source_digests["fence_snippet"]),
            "internal_readiness": ("REGULAR", "root", "root", "644", source_digests["internal_readiness"]),
        },
    }


def item(obs: dict[str, str], name: str, field: str) -> str:
    return obs[f"{name}.{field}"]


def is_absent(obs: dict[str, str], name: str) -> bool:
    return item(obs, name, "state") == "ABSENT"


def bounded_count(obs: dict[str, str], key: str) -> int:
    value = obs[key]
    if not re.fullmatch(r"[0-9]{1,3}", value):
        raise EvidenceError(f"invalid bounded vhost count: {key}")
    count = int(value)
    if count > 64:
        raise EvidenceError(f"vhost count exceeds bounded evidence limit: {key}")
    return count


def validate_runtime_db_probe_shape(obs: dict[str, str]) -> None:
    if obs["runtime_db_probe_schema"] != "1":
        raise EvidenceError("runtime database probe schema mismatch")

    state = obs["runtime_db_probe_state"]
    exit_class = obs["runtime_db_probe_exit_class"]
    db_name = obs["runtime_db_name"]
    if state not in RUNTIME_DB_PROBE_STATES:
        raise EvidenceError("invalid runtime database probe state")
    if exit_class not in RUNTIME_DB_EXIT_CLASSES:
        raise EvidenceError("invalid runtime database probe exit class")
    if db_name != "NONE" and not SAFE_DB_NAME.fullmatch(db_name):
        raise EvidenceError("unsafe runtime database identity observation")

    if state == "OBSERVED":
        if exit_class != "ZERO" or db_name == "NONE":
            raise EvidenceError("runtime database probe evidence inconsistent")
        return
    if state == "DRUSH_FAILED":
        if exit_class != "NONZERO" or db_name != "NONE":
            raise EvidenceError("runtime database probe evidence inconsistent")
        return
    if state in {"OUTPUT_EMPTY", "OUTPUT_INVALID"}:
        if exit_class != "ZERO" or db_name != "NONE":
            raise EvidenceError("runtime database probe evidence inconsistent")
        return
    if exit_class != "NOT_RUN" or db_name != "NONE":
        raise EvidenceError("runtime database probe evidence inconsistent")


def validate_metadata_shape(obs: dict[str, str]) -> None:
    if obs["observer_schema"] != "2":
        raise EvidenceError("observer schema mismatch")
    if obs["vhost_selector_schema"] != "1":
        raise EvidenceError("vhost selector schema mismatch")
    for key in VHOST_COUNT_KEYS:
        bounded_count(obs, key)
    validate_runtime_db_probe_shape(obs)
    for key in ("host_identity_sha256", "execution_user_sha256", "runtime_release_target_sha256"):
        if obs[key] != "NONE" and not HEX64.fullmatch(obs[key]):
            raise EvidenceError(f"invalid digest-shaped metadata: {key}")
    for name in ITEMS:
        state = item(obs, name, "state")
        typ = item(obs, name, "type")
        digest_state = item(obs, name, "digest_state")
        digest = item(obs, name, "sha256")
        if state == UNOBSERVABLE:
            if name != "sudoers":
                raise EvidenceError(f"unobservable state forbidden for managed item: {name}")
            if (
                typ != "UNOBSERVABLE"
                or item(obs, name, "owner") != "NONE"
                or item(obs, name, "group") != "NONE"
                or item(obs, name, "mode") != "NONE"
                or digest_state != "UNOBSERVABLE"
                or digest != "NONE"
            ):
                raise EvidenceError("invalid unprivileged sudoers observation shape")
            continue
        if state not in {"ABSENT", "PRESENT"}:
            raise EvidenceError(f"invalid state: {name}")
        if typ not in {"ABSENT", "REGULAR", "DIRECTORY", "SYMLINK", "OTHER"}:
            raise EvidenceError(f"invalid type: {name}")
        if digest_state not in {"ABSENT", "READABLE", "UNREADABLE", "NOT_FILE"}:
            raise EvidenceError(f"invalid digest state: {name}")
        if digest != "NONE" and not HEX64.fullmatch(digest):
            raise EvidenceError(f"invalid digest: {name}")
        if state == "PRESENT":
            for field in ("owner", "group"):
                if not SAFE_NAME.fullmatch(item(obs, name, field)):
                    raise EvidenceError(f"unsafe {field}: {name}")
            if not re.fullmatch(r"[0-7]{3,4}", item(obs, name, "mode")):
                raise EvidenceError(f"invalid mode: {name}")


def evaluate(obs: dict[str, str], expected: dict) -> dict[str, str]:
    validate_metadata_shape(obs)
    for key in ("PLAN_MUTATION", "HELPER_EXECUTION", "SUDO_EXECUTION", "PROD_ACCESS",
                "PREPROD_DB_MUTATION", "PREPROD_BACKUP", "FENCE_MUTATION", "NGINX_MUTATION"):
        if obs[key] != "NONE":
            raise EvidenceError(f"mutation boundary violated: {key}")
    if obs["execution_uid"] == "0" or obs["execution_user_sha256"] != expected["deploy_user_hash"]:
        raise EvidenceError("PREPROD execution identity mismatch")
    if not HEX64.fullmatch(obs["host_identity_sha256"]):
        raise EvidenceError("host identity unavailable")

    if obs["sudoers_dir_searchable"] not in {"YES", "NO"}:
        raise EvidenceError("invalid sudoers directory observability")
    sudoers_unobservable = obs["sudoers_dir_searchable"] == "NO"
    if sudoers_unobservable:
        if item(obs, "sudoers", "state") != UNOBSERVABLE:
            raise EvidenceError("sudoers evidence must be unobservable when directory is not searchable")
    elif item(obs, "sudoers", "state") == UNOBSERVABLE:
        raise EvidenceError("sudoers unobservable state inconsistent with searchable directory")

    if obs["state_dir_searchable"] not in {"YES", "NOT_PRESENT"}:
        raise EvidenceError("capability state directory is not safely observable without privileged read")

    for name, digest in expected["staging"].items():
        if item(obs, name, "type") != "REGULAR":
            raise EvidenceError(f"missing required predecessor: {name}")
        if item(obs, name, "digest_state") != "READABLE" or item(obs, name, "sha256") != digest:
            raise EvidenceError(f"required predecessor digest mismatch: {name}")

    if item(obs, "vhost", "type") != "REGULAR":
        raise EvidenceError("canonical PREPROD vhost missing or unsafe")
    server_blocks = bounded_count(obs, "vhost_server_block_count")
    hostname_declarations = bounded_count(obs, "vhost_hostname_declaration_count")
    hostname_blocks = bounded_count(obs, "vhost_hostname_block_count")
    application_blocks = bounded_count(obs, "vhost_application_block_count")
    safe_auxiliary_blocks = bounded_count(obs, "vhost_safe_auxiliary_block_count")
    application_fence_count = bounded_count(obs, "vhost_application_fence_include_count")
    total_fence_count = bounded_count(obs, "vhost_total_fence_include_count")
    if application_blocks != 1:
        raise EvidenceError("canonical PREPROD application-serving block is ambiguous")
    if hostname_declarations != hostname_blocks:
        raise EvidenceError("duplicate PREPROD hostname declaration within a server block")
    if hostname_blocks != 1 + safe_auxiliary_blocks:
        raise EvidenceError("PREPROD hostname server-block role is ambiguous")
    if server_blocks < hostname_blocks:
        raise EvidenceError("invalid PREPROD vhost server-block counts")
    if application_fence_count not in {0, 1}:
        raise EvidenceError("application fence include count is unsafe")
    if total_fence_count != application_fence_count:
        raise EvidenceError("fence include is duplicated or outside the application-serving block")

    if not is_absent(obs, "maintenance_marker"):
        raise EvidenceError("maintenance marker present; provisioning must remain blocked")
    for lock in ("deploy_lock", "refresh_lock"):
        if not is_absent(obs, lock) and item(obs, lock, "type") != "REGULAR":
            raise EvidenceError(f"unsafe lock path type: {lock}")
    if item(obs, "current_release", "type") != "SYMLINK" or not RELEASE_NAME.fullmatch(obs["runtime_release_name"]):
        raise EvidenceError("runtime release identity unavailable or unsafe")
    if item(obs, "current_web", "type") != "DIRECTORY":
        raise EvidenceError("runtime web root unavailable")

    runtime_db_probe_state = obs["runtime_db_probe_state"]
    if runtime_db_probe_state != "OBSERVED":
        raise EvidenceError(
            f"runtime database identity probe unavailable: {runtime_db_probe_state}"
        )
    if obs["runtime_db_name"] != expected["runtime_db"]:
        raise EvidenceError("runtime database identity mismatch")

    drift: list[str] = []
    missing: list[str] = []
    protected_unreadable: list[str] = []
    exact: list[str] = []
    for name, spec in expected["managed"].items():
        typ, owner, group, mode, digest = spec
        if item(obs, name, "state") == UNOBSERVABLE:
            if name != "sudoers" or not sudoers_unobservable:
                raise EvidenceError(f"unexpected unobservable managed state: {name}")
            drift.append(name)
            continue
        if is_absent(obs, name):
            missing.append(name)
            continue
        if item(obs, name, "type") != typ:
            raise EvidenceError(f"unexpected managed path type: {name}")
        metadata_exact = (
            item(obs, name, "owner") == owner
            and item(obs, name, "group") == group
            and item(obs, name, "mode").lstrip("0") == mode.lstrip("0")
        )
        digest_exact = True
        if digest is not None:
            if item(obs, name, "digest_state") == "UNREADABLE":
                if name == "authority_state":
                    protected_unreadable.append(name)
                    continue
                digest_exact = False
            elif item(obs, name, "digest_state") != "READABLE":
                digest_exact = False
            else:
                digest_exact = item(obs, name, "sha256") == digest
        if name == "authority_state" and not metadata_exact:
            raise EvidenceError("protected authority-state metadata mismatch")
        if metadata_exact and digest_exact:
            exact.append(name)
        else:
            drift.append(name)

    if protected_unreadable:
        raise EvidenceError("privileged read required for protected exact-state proof: " + ",".join(protected_unreadable))

    vhost_metadata_exact = (
        item(obs, "vhost", "owner") == "root"
        and item(obs, "vhost", "group") == "root"
        and item(obs, "vhost", "mode").lstrip("0") == "644"
    )

    core = {"helper", "bundle_dir", "state_dir", "authority_state", "sudoers", "fence_snippet", "internal_readiness"}
    clean = core.issubset(set(missing)) and application_fence_count == 0
    all_managed_exact = len(exact) == len(expected["managed"])
    if clean:
        classification = "CLEAN_INSTALL"
    elif all_managed_exact and vhost_metadata_exact and application_fence_count == 1:
        classification = "NO_OP_ALREADY_EXACT"
    else:
        classification = "BOUNDED_RECONCILIATION"

    mutations: list[str] = []
    if classification != "NO_OP_ALREADY_EXACT":
        for name in sorted(set(missing + drift)):
            mutations.append(f"CONVERGE_{name.upper()}")
        if application_fence_count == 0:
            mutations.append("INSERT_VHOST_FENCE_INCLUDE")
        if not vhost_metadata_exact:
            mutations.append("NORMALIZE_VHOST_METADATA")
        mutations.extend(["NGINX_CONFIG_TEST", "NGINX_RELOAD"])
    if not mutations:
        mutations = ["NONE"]

    rollback = [
        "RESTORE_PRESTATE_HELPER_BUNDLE_STATE_SUDOERS_FENCE_INTERNAL_VHOST",
        "NGINX_CONFIG_TEST",
        "NGINX_RELOAD",
        "REMOVE_PROVISIONING_TRANSACTION_STAGE",
    ]

    source = expected["source_digests"]
    result = {
        "PLAN_EVIDENCE_MODEL": "READ_ONLY_FIXED_PATH_METADATA_V4_BOUNDED_RUNTIME_DB_PROBE",
        "FUTURE_APPLY_CLASSIFICATION": classification,
        "FUTURE_APPLY_MUTATION_INVENTORY": ";".join(mutations),
        "FUTURE_APPLY_ROLLBACK_INVENTORY": ";".join(rollback),
        "PRIVILEGED_READ_REQUIRED": "false",
        "SUDOERS_OBSERVABILITY": UNOBSERVABLE if sudoers_unobservable else "OBSERVABLE_UNPRIVILEGED",
        "PREPROD_EXECUTION_SURFACE_IDENTITY": obs["host_identity_sha256"],
        "RUNTIME_RELEASE_IDENTITY": obs["runtime_release_name"],
        "RUNTIME_DATABASE_PROBE_STATE": runtime_db_probe_state,
        "RUNTIME_DATABASE_PROBE_EXIT_CLASS": obs["runtime_db_probe_exit_class"],
        "RUNTIME_DATABASE_IDENTITY": obs["runtime_db_name"],
        "DEPLOY_LOCK_STATE": item(obs, "deploy_lock", "state") + "/" + item(obs, "deploy_lock", "type"),
        "REFRESH_LOCK_STATE": item(obs, "refresh_lock", "state") + "/" + item(obs, "refresh_lock", "type"),
        "MAINTENANCE_MARKER_STATE": item(obs, "maintenance_marker", "state"),
        "VHOST_HOSTNAME_DECLARATION_COUNT": obs["vhost_hostname_declaration_count"],
        "VHOST_APPLICATION_BLOCK_COUNT": obs["vhost_application_block_count"],
        "VHOST_SAFE_AUXILIARY_BLOCK_COUNT": obs["vhost_safe_auxiliary_block_count"],
        "VHOST_APPLICATION_FENCE_INCLUDE_COUNT": obs["vhost_application_fence_include_count"],
        "DATA_ACTIVATION_AUTHORITY_AFTER_APPLY": "DISABLED",
        "PROVISIONING_PROFILE_DIGEST": source["provisioning_profile"],
        "PINNED_SOURCE_BUNDLE_DIGESTS": ",".join(f"{k}:{source[k]}" for k in sorted(source) if k not in {"provisioning_profile", "observer", "evaluator"}),
        "HOST_METADATA_FIELDS": "presence,type,owner,group,mode,digest_state,sha256,release_identity,runtime_db_probe_state,runtime_db_identity,lock_state,bounded_vhost_topology_counts",
        "PLAN_MUTATION": "NONE",
        "HELPER_EXECUTION": "NONE",
        "SUDO_EXECUTION": "NONE",
        "PROD_ACCESS": "NONE",
        "PREPROD_DB_MUTATION": "NONE",
        "PREPROD_BACKUP": "NONE",
        "FENCE_MUTATION": "NONE",
        "NGINX_MUTATION": "NONE",
        "DATA_ACTIVATION_AUTHORITY": "DISABLED",
    }
    for name in expected["managed"]:
        if item(obs, name, "state") == UNOBSERVABLE:
            status = UNOBSERVABLE
        elif is_absent(obs, name):
            status = "ABSENT"
        elif name in exact:
            status = "EXACT"
        else:
            status = "DRIFT_OR_UNVERIFIED"
        result[f"HOST_STATE_{name.upper()}"] = status
    return result


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--observation", required=True, type=Path)
    parser.add_argument("--repository-root", required=True, type=Path)
    args = parser.parse_args()
    try:
        obs = parse_observation(args.observation)
        expected = build_expected(args.repository_root.resolve())
        result = evaluate(obs, expected)
    except (EvidenceError, OSError, KeyError, json.JSONDecodeError) as exc:
        print(f"PLAN_EVIDENCE_FAIL_CLOSED={exc}", file=sys.stderr)
        if "privileged read required" in str(exc):
            print("PRIVILEGED_READ_REQUIRED=true", file=sys.stderr)
        return 1
    for key, value in result.items():
        print(f"{key}={value}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
