#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import json
from pathlib import Path

BASE = Path(__file__).resolve().parent
PROFILE = BASE / "provisioning/profile.json"
BUNDLE = BASE / "bundle.json"
CAPABILITY = BASE / "profile.json"
REMOTE = BASE / "provisioning/remote-provision-root.sh"
RUN_APPLY = BASE / "provisioning/run-apply.sh"
CONTROL_SUDO = BASE / "provisioning/agency-preprod-refresh-control.sudoers"
INGRESS_SUDO = BASE / "provisioning/agency-preprod-refresh-ingress.sudoers"

PATHS = {
    "helper": BASE / "agency-preprod-refresh-control",
    "ingress": BASE / "agency-preprod-refresh-ingress",
    "authority_installer": BASE / "agency-preprod-refresh-authority-install",
    "authority_abort": BASE / "agency-preprod-refresh-authority-abort",
    "transaction_contract": BASE / "transaction_contract.py",
    "admin_reconcile": BASE / "admin-reconcile.sh",
    "admin_reconcile_php": BASE / "admin-reconcile.php",
    "side_effect_hardening": BASE / "side_effect_hardening.py",
    "runtime_state_digest": BASE / "runtime_state_digest.py",
    "disabled_authority_state": BASE / "data-activation-authority.disabled.json",
    "control_sudoers": CONTROL_SUDO,
    "ingress_sudoers": INGRESS_SUDO,
    "fence_snippet": BASE / "nginx/agency-preprod-refresh-fence.conf",
    "internal_readiness": BASE / "nginx/agency-preprod-refresh-internal-readiness.conf",
    "capability_profile": CAPABILITY,
    "bundle_manifest": BUNDLE,
    "remote_provision_root": REMOTE,
    "run_apply": RUN_APPLY,
}


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main() -> None:
    profile = json.loads(PROFILE.read_text())
    bundle = json.loads(BUNDLE.read_text())
    capability = json.loads(CAPABILITY.read_text())
    remote = REMOTE.read_text()
    run_apply = RUN_APPLY.read_text()
    sudo = CONTROL_SUDO.read_text() + "\n" + INGRESS_SUDO.read_text()

    assert profile["revision_issue"] == 915
    assert profile["abort_revision_issue"] == 917
    assert profile["apply"]["persistent_data_activation_authority_after_apply"] == "DISABLED"
    assert profile["apply"]["transaction_authority_after_apply"] == "ABSENT"
    assert profile["apply"]["abort_helper_after_apply"] == "INSTALLED_ROOT_ONLY"
    assert profile["sudo"]["authority_installer_exposed"] is False
    assert profile["sudo"]["abort_helper_exposed"] is False

    assert bundle["revision_issue"] == 915
    assert bundle["abort_revision_issue"] == 917
    assert bundle["persistent_data_activation_authority_after_provisioning"] == "DISABLED"
    assert bundle["transaction_authority_after_provisioning"] == "ABSENT"
    assert bundle["pre_ingress_abort_after_provisioning"] == "INSTALLED_ROOT_ONLY"
    assert bundle["normal_sudo_exposure_for_abort"] == "NONE"
    assert capability["abort_revision_issue"] == 917
    assert capability["pre_ingress_abort"]["target_state"] == "ABORTED"
    assert capability["pre_ingress_abort"]["normal_agency_preprod_sudo_exposure"] == "NONE"

    for key, path in PATHS.items():
        expected = profile["digests"][key]
        actual = sha256(path)
        assert expected == actual, (key, expected, actual)

    assert bundle["files"]["authority_abort"]["sha256"] == profile["digests"]["authority_abort"]
    assert bundle["files"]["transaction_contract"]["sha256"] == profile["digests"]["transaction_contract"]
    assert bundle["files"]["helper"]["sha256"] == profile["digests"]["helper"]
    assert bundle["files"]["ingress"]["sha256"] == profile["digests"]["ingress"]

    assert "agency-preprod-refresh-authority-abort" not in sudo
    assert "agency-preprod-refresh-authority-install" not in sudo
    for forbidden in ("NOPASSWD: ALL", " SETENV:", "mariadb", "bash", "python", " env"):
        assert forbidden not in sudo

    assert "agency-preprod-refresh-authority-abort" in run_apply
    assert "check_digest authority_abort" in run_apply
    assert ".sudo.abort_helper_exposed == false" in run_apply
    assert "agency-preprod-refresh-authority-abort" in remote
    assert "install -m 0750 -o root -g root \"$SOURCE/agency-preprod-refresh-authority-abort\" \"$AUTH_ABORT\"" in remote
    assert "! grep -REq 'agency-preprod-refresh-authority-(install|abort)'" in remote
    assert "PERSISTENT_DATA_ACTIVATION_AUTHORITY=DISABLED" in remote
    assert "TRANSACTION_AUTHORITY=ABSENT" in remote

    print("PROVISIONING_DIGESTS=EXACT")
    print("ABORT_HELPER=INSTALLED_ROOT_ONLY_FUTURE_SOURCE")
    print("NORMAL_SUDO_EXPOSURE=NONE")
    print("PERSISTENT_DATA_ACTIVATION_AUTHORITY=DISABLED")
    print("TRANSACTION_AUTHORITY=ABSENT")
    print("REAL_PROVISIONING=NONE")
    print("LOCAL_917_PROVISIONING_CONTRACT=PASS")


if __name__ == "__main__":
    main()
