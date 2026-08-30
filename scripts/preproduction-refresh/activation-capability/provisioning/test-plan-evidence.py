#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import importlib.util
from pathlib import Path

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location("plan_evidence", HERE / "evaluate-plan-evidence.py")
assert SPEC and SPEC.loader
mod = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(mod)

H = lambda s: hashlib.sha256(s.encode()).hexdigest()

EXPECTED = {
    "source_digests": {
        "helper": H("helper"),
        "side_effect_hardening": H("side"),
        "runtime_state_digest": H("runtime"),
        "disabled_authority_state": H("disabled"),
        "fence_snippet": H("fence"),
        "internal_readiness": H("internal"),
        "capability_profile": H("profile"),
        "bundle_manifest": H("bundle"),
        "sudoers": H("sudoers"),
        "provisioning_profile": H("provisioning"),
        "observer": H("observer"),
        "evaluator": H("evaluator"),
    },
    "staging": {
        "staging_helper": H("staging-helper"),
        "staging_sanitizer": H("sanitizer"),
        "staging_policy": H("policy"),
    },
    "runtime_db": "agency_preprod",
    "deploy_user_hash": H("agency-preprod"),
    "managed": {
        "helper": ("REGULAR", "root", "root", "755", H("helper")),
        "bundle_dir": ("DIRECTORY", "root", "root", "755", None),
        "side_effect_hardening": ("REGULAR", "root", "root", "644", H("side")),
        "runtime_state_digest": ("REGULAR", "root", "root", "644", H("runtime")),
        "capability_profile": ("REGULAR", "root", "root", "644", H("profile")),
        "bundle_manifest": ("REGULAR", "root", "root", "644", H("bundle")),
        "state_dir": ("DIRECTORY", "root", "root", "711", None),
        "incoming_dir": ("DIRECTORY", "root", "root", "700", None),
        "candidates_dir": ("DIRECTORY", "root", "root", "700", None),
        "backups_dir": ("DIRECTORY", "root", "root", "700", None),
        "authority_state": ("REGULAR", "root", "root", "600", H("disabled")),
        "sudoers": ("REGULAR", "root", "root", "440", H("sudoers")),
        "nginx_snippets_dir": ("DIRECTORY", "root", "root", "755", None),
        "nginx_conf_dir": ("DIRECTORY", "root", "root", "755", None),
        "fence_snippet": ("REGULAR", "root", "root", "644", H("fence")),
        "internal_readiness": ("REGULAR", "root", "root", "644", H("internal")),
    },
}


def set_absent(obs, name):
    for field, value in {
        "state": "ABSENT", "type": "ABSENT", "owner": "NONE", "group": "NONE",
        "mode": "NONE", "digest_state": "ABSENT", "sha256": "NONE",
    }.items():
        obs[f"{name}.{field}"] = value


def set_present(obs, name, typ="REGULAR", owner="root", group="root", mode="644", digest=None, readable=True):
    obs[f"{name}.state"] = "PRESENT"
    obs[f"{name}.type"] = typ
    obs[f"{name}.owner"] = owner
    obs[f"{name}.group"] = group
    obs[f"{name}.mode"] = mode
    if typ == "REGULAR":
        obs[f"{name}.digest_state"] = "READABLE" if readable else "UNREADABLE"
        obs[f"{name}.sha256"] = digest if readable and digest else "NONE"
    else:
        obs[f"{name}.digest_state"] = "NOT_FILE"
        obs[f"{name}.sha256"] = "NONE"


def base_obs():
    obs = {
        "observer_schema": "1",
        "execution_uid": "1001",
        "execution_gid": "1001",
        "execution_user_sha256": H("agency-preprod"),
        "host_identity_sha256": H("preprod-host"),
        "sudoers_dir_searchable": "YES",
        "state_dir_searchable": "YES",
        "vhost_server_name_count": "1",
        "vhost_fence_include_count": "1",
        "runtime_release_target_sha256": H("/var/www/agency-preprod/releases/20260829010000-234760742223"),
        "runtime_release_name": "20260829010000-234760742223",
        "runtime_db_name_state": "OBSERVED",
        "runtime_db_name": "agency_preprod",
        "PLAN_MUTATION": "NONE",
        "HELPER_EXECUTION": "NONE",
        "SUDO_EXECUTION": "NONE",
        "PROD_ACCESS": "NONE",
        "PREPROD_DB_MUTATION": "NONE",
        "PREPROD_BACKUP": "NONE",
        "FENCE_MUTATION": "NONE",
        "NGINX_MUTATION": "NONE",
    }
    for name in mod.ITEMS:
        set_absent(obs, name)
    for name, digest in EXPECTED["staging"].items():
        set_present(obs, name, digest=digest, mode="755" if name == "staging_helper" else "644")
    for name, (typ, owner, group, mode, digest) in EXPECTED["managed"].items():
        set_present(obs, name, typ=typ, owner=owner, group=group, mode=mode, digest=digest)
    set_absent(obs, "maintenance_marker")
    set_present(obs, "vhost", owner="root", group="root", mode="644", digest=H("vhost"))
    set_absent(obs, "deploy_lock")
    set_absent(obs, "refresh_lock")
    set_present(obs, "current_release", typ="SYMLINK", owner="agency-preprod", group="www-data", mode="777")
    set_present(obs, "current_web", typ="DIRECTORY", owner="agency-preprod", group="www-data", mode="750")
    return obs


def expect_error(obs, needle):
    try:
        mod.evaluate(obs, EXPECTED)
    except mod.EvidenceError as exc:
        assert needle in str(exc), (needle, str(exc))
        return
    raise AssertionError(f"expected EvidenceError containing {needle!r}")


def test_clean_install():
    obs = base_obs()
    for name in EXPECTED["managed"]:
        if name not in {"nginx_snippets_dir", "nginx_conf_dir"}:
            set_absent(obs, name)
    obs["vhost_fence_include_count"] = "0"
    obs["state_dir_searchable"] = "NOT_PRESENT"
    result = mod.evaluate(obs, EXPECTED)
    assert result["FUTURE_APPLY_CLASSIFICATION"] == "CLEAN_INSTALL"
    assert "INSTALL" not in result.get("PRIVILEGED_READ_REQUIRED", "")
    print("CLEAN_INSTALL=PASS")


def test_noop():
    result = mod.evaluate(base_obs(), EXPECTED)
    assert result["FUTURE_APPLY_CLASSIFICATION"] == "NO_OP_ALREADY_EXACT"
    assert result["FUTURE_APPLY_MUTATION_INVENTORY"] == "NONE"
    print("EXACT_INSTALLED_NO_OP=PASS")


def test_reconciliation():
    obs = base_obs()
    obs["helper.sha256"] = H("managed-drift")
    result = mod.evaluate(obs, EXPECTED)
    assert result["FUTURE_APPLY_CLASSIFICATION"] == "BOUNDED_RECONCILIATION"
    assert "CONVERGE_HELPER" in result["FUTURE_APPLY_MUTATION_INVENTORY"]
    print("BOUNDED_RECONCILIATION=PASS")


def test_fail_closed_cases():
    obs = base_obs(); obs["staging_helper.sha256"] = H("wrong")
    expect_error(obs, "required predecessor digest mismatch")
    print("WRONG_DIGEST=FAIL_CLOSED")

    obs = base_obs(); obs["authority_state.owner"] = "agency-preprod"
    expect_error(obs, "protected authority-state metadata mismatch")
    print("WRONG_OWNER=FAIL_CLOSED")

    obs = base_obs(); obs["authority_state.mode"] = "644"
    expect_error(obs, "protected authority-state metadata mismatch")
    print("WRONG_MODE=FAIL_CLOSED")

    obs = base_obs(); set_present(obs, "helper", typ="SYMLINK", owner="root", group="root", mode="777")
    expect_error(obs, "unexpected managed path type")
    print("UNEXPECTED_FILE_TYPE=FAIL_CLOSED")

    obs = base_obs(); set_absent(obs, "staging_policy")
    expect_error(obs, "missing required predecessor")
    print("MISSING_REQUIRED_PRECONDITION=FAIL_CLOSED")

    obs = base_obs(); set_present(obs, "authority_state", owner="root", group="root", mode="600", readable=False)
    expect_error(obs, "privileged read required")
    print("PRIVILEGED_READ_BOUNDARY=FAIL_CLOSED")


def test_unreadable_sudoers_conservative_reconcile():
    obs = base_obs()
    set_present(obs, "sudoers", owner="root", group="root", mode="440", readable=False)
    result = mod.evaluate(obs, EXPECTED)
    assert result["FUTURE_APPLY_CLASSIFICATION"] == "BOUNDED_RECONCILIATION"
    assert result["PRIVILEGED_READ_REQUIRED"] == "false"
    assert "CONVERGE_SUDOERS" in result["FUTURE_APPLY_MUTATION_INVENTORY"]
    print("UNREADABLE_SUDOERS=CONSERVATIVE_RECONCILIATION")


def test_metadata_only():
    result = mod.evaluate(base_obs(), EXPECTED)
    serialized = "\n".join(f"{k}={v}" for k, v in result.items())
    forbidden = ["password", "private key", "settings.php", "runtime.env", "basic auth", "raw sql", "pii"]
    lowered = serialized.lower()
    assert all(token not in lowered for token in forbidden)
    assert result["PLAN_MUTATION"] == "NONE"
    assert result["HELPER_EXECUTION"] == "NONE"
    assert result["SUDO_EXECUTION"] == "NONE"
    assert result["PROD_ACCESS"] == "NONE"
    assert result["DATA_ACTIVATION_AUTHORITY"] == "DISABLED"
    print("METADATA_ONLY_OUTPUT=PASS")
    print("SECRET_VALUE_OUTPUT=ABSENT")
    print("PLAN_MUTATION=NONE")
    print("HELPER_EXECUTION=NONE")
    print("SUDO_EXECUTION=NONE")
    print("PROD_ACCESS=NONE")
    print("DATA_ACTIVATION_AUTHORITY_DISABLED=PASS")


def main():
    test_clean_install()
    test_noop()
    test_reconciliation()
    test_fail_closed_cases()
    test_unreadable_sudoers_conservative_reconcile()
    test_metadata_only()
    print("#876_FUTURE_PLAN_CONTRACT=PASS")
    print("#879_PLAN_EVIDENCE_MATRIX=PASS")


if __name__ == "__main__":
    main()
