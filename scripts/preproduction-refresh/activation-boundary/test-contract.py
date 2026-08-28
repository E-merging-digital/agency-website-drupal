#!/usr/bin/env python3
from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
PROFILE = ROOT / "scripts/preproduction-refresh/activation-boundary/profile.json"
DOC = ROOT / "docs/operations/preproduction-refresh-activation-boundary.md"
PROOF = ROOT / "scripts/preproduction-refresh/activation-boundary/synthetic-proof.sh"


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def main() -> None:
    profile = json.loads(PROFILE.read_text(encoding="utf-8"))
    doc = DOC.read_text(encoding="utf-8")
    proof = PROOF.read_text(encoding="utf-8")

    require(profile["schema_version"] == 1, "profile schema must remain version 1")
    require(profile["issue_number"] == 868, "profile must remain bound to #868")
    require(profile["parent_issue"] == 816, "profile must remain bound to #816")
    require(
        profile["profile_id"] == "agency-preprod-atomic-refresh-activation-v1",
        "unexpected profile id",
    )

    delivery = profile["delivery_scope"]
    for key in (
        "real_prod_data_read",
        "real_prod_data_transfer",
        "real_preprod_mutation",
        "real_backup",
        "real_activation",
        "real_rollback",
        "merge",
    ):
        require(delivery[key] == "FORBIDDEN", f"delivery scope widened: {key}")

    runtime = profile["current_runtime"]
    require(runtime["runtime_database"] == "agency_preprod", "runtime DB identity changed")
    require(runtime["runtime_database_user"] == "agency_preprod", "runtime DB user changed")
    require(runtime["hash_salt"] == "PREPROD_SERVER_OWNED_PRESERVE", "hash salt boundary weakened")
    require(runtime["database_credentials"] == "PREPROD_SERVER_OWNED_PRESERVE", "credential boundary weakened")
    require(runtime["application_release_change"] == "FORBIDDEN", "release mutation became allowed")

    candidate = profile["candidate"]
    require(candidate["runtime_reachable_before_activation"] is False, "candidate became runtime-reachable")
    require(candidate["raw_prod_runtime_exposure"] == "FORBIDDEN", "raw PROD runtime exposure allowed")
    require(
        candidate["existing_866_helper_reuse_as_persistent_candidate"] == "IMPOSSIBLE_BY_CONTRACT",
        "#866 one-shot helper must not be weakened into a persistent candidate",
    )
    lifecycle = candidate["lifecycle"]
    require(lifecycle.index("SANITIZE_DETERMINISTIC") < lifecycle.index("HARDEN_SIDE_EFFECT_STATE"), "sanitize/harden order invalid")
    require(lifecycle.index("HARDEN_SIDE_EFFECT_STATE") < lifecycle.index("ASSERT_CANDIDATE"), "hardening must precede assertions")
    require(lifecycle.index("ASSERT_CANDIDATE") < lifecycle.index("RETAIN_ROOT_ONLY_UNTIL_ACTIVATION_OR_CLEANUP"), "candidate retention preceded proof")

    gate = profile["side_effect_gate"]
    require(gate["production_config_split"] == "OFF", "production split may not be active")
    require(gate["preproduction_config_split"] == "ON", "PREPROD split must remain active")
    require(gate["ga4_google_tag_outbound"] == "OFF", "GA4 must remain off")
    require(gate["email_delivery"] == "SINK_OR_NULL", "mail must remain sink/null")
    require(gate["automated_cron"] == "OFF", "cron must remain off")
    require(gate["openai_provider_egress"] == "OFF", "OpenAI egress must remain off")
    require(gate["production_webhook_api_credentials"] == "ABSENT", "production webhook credentials must be absent")
    require(gate["webform_submissions"] == 0, "webforms must be empty")
    require(gate["active_sessions"] == 0, "sessions must be empty")

    activation = profile["activation"]
    require(
        activation["model"] == "ATOMIC_MULTI_TABLE_RENAME_WITHIN_FIXED_RUNTIME_DATABASE_NAME",
        "activation model changed",
    )
    require(activation["database_rename_statement"] == "FORBIDDEN", "database rename assumption introduced")
    require(activation["requires_mariadb_atomic_ddl"] is True, "atomic DDL proof is mandatory")
    for key in (
        "preflight_reject_if_views_present",
        "preflight_reject_if_triggers_present",
        "preflight_reject_if_events_present",
        "preflight_reject_if_foreign_keys_present",
        "preflight_require_base_tables_only",
    ):
        require(activation[key] is True, f"activation preflight weakened: {key}")
    require(activation["runtime_settings_mutation"] == "NONE", "runtime settings switch introduced")
    require(activation["runtime_database_name_change"] == "NONE", "runtime DB name change introduced")

    backup = profile["backup"]
    require(backup["mandatory_before_activation"] is True, "backup ceased to be mandatory")
    for key in ("raw_backup_github_artifact", "raw_backup_log_output", "raw_backup_user_visible"):
        require(backup[key] == "FORBIDDEN", f"raw backup exposure allowed: {key}")

    rollback = profile["rollback"]
    require(
        rollback["model"] == "REVERSE_ATOMIC_MULTI_TABLE_RENAME_TO_EXACT_PREVIOUS_TABLE_SET",
        "rollback must restore the exact previous table set",
    )
    require(rollback["fresh_database_as_rollback"] == "FORBIDDEN", "fresh DB is not rollback proof")

    locking = profile["locking"]
    require(locking["outer_lock"].endswith("/shared/deploy.lock"), "shared deploy lock must serialize refresh and release deploy")
    require(locking["concurrent_refresh"] == "FORBIDDEN", "concurrent refresh allowed")
    require(locking["concurrent_release_deploy"] == "FORBIDDEN", "concurrent release deploy allowed")

    privilege = profile["privilege"]
    require(privilege["model"] == "NEW_FIXED_ROOT_OWNED_PINNED_REFRESH_HELPER_REQUIRED", "privilege dependency changed")
    require(privilege["existing_helper_mutation_in_issue_868"] == "FORBIDDEN", "#868 may not mutate installed #861 helper")
    require(privilege["separate_provisioning_tranche_required"] is True, "separate provisioning boundary disappeared")
    for key in (
        "generic_mariadb_sudo",
        "generic_shell_sudo",
        "generic_python_sudo",
        "generic_env_sudo",
        "nopasswd_all",
        "mutable_repository_checkout_as_root",
    ):
        require(privilege[key] == "FORBIDDEN", f"privilege widening allowed: {key}")

    future = profile["future_fixed_capability"]
    require(future["status"] == "DESIGN_ONLY_NOT_PROVISIONED_NOT_EXECUTABLE", "#868 accidentally made privileged capability executable")
    require(future["plan_apply_authority_distinct"] is True, "PLAN/APPLY authority merged")
    require(future["plan_namespace"] == "plan-868-", "PLAN namespace invalid")
    require(future["request_namespace"] == "apply-868-", "APPLY namespace invalid")

    evidence = profile["evidence"]
    require(evidence["metadata_only"] is True, "evidence must remain metadata-only")
    for key in ("raw_prod_sql", "raw_preprod_backup", "pii", "secrets"):
        require(evidence[key] == "FORBIDDEN", f"evidence exposure allowed: {key}")

    require(profile["files"]["public_files"] == "NONE", "public files unexpectedly entered #868")
    require(profile["files"]["private_files"] == "NONE", "private files unexpectedly entered #868")

    required_doc_phrases = (
        "runtime database = agency_preprod",
        "does **not** use a database-name switch",
        "atomic **multi-table `RENAME TABLE`** statement",
        "views                    = 0",
        "triggers                 = 0",
        "foreign-key constraints  = 0",
        "A **new fixed root-owned, pinned refresh activation capability is therefore required**",
        "REAL_PREPROD_MUTATION     = NONE",
        "MERGE                     = NOT_PERFORMED",
    )
    for phrase in required_doc_phrases:
        require(phrase in doc, f"durable design phrase missing: {phrase}")

    forbidden_proof_fragments = (
        "preprod.emergingdigital.be",
        "/var/www/agency-preprod",
        "ssh ",
        "scp ",
        "rsync ",
        "drush ",
        "sudo ",
        "agency_preprod_stage_",
    )
    for fragment in forbidden_proof_fragments:
        require(fragment not in proof, f"synthetic proof gained real/runtime execution surface: {fragment}")

    require("agency868_runtime" in proof and "agency868_candidate" in proof, "synthetic schemas missing")
    require("missing_required_table" in proof, "forced atomic rename failure proof missing")
    require("rollback_dump_matches_backup=PASS" in proof, "exact rollback backup identity proof missing")
    require("real_preprod_mutation=NONE" in proof, "synthetic evidence boundary missing")
    require("prod_write_path=NONE" in proof, "PROD write absence proof missing")

    print("preprod_868_activation_boundary_contract=PASS")


if __name__ == "__main__":
    main()
