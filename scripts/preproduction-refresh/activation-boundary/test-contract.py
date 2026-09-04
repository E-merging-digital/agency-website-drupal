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


def require_exact_order(actual: list[str], expected: list[str], name: str) -> None:
    require(actual == expected, f"{name} ordering changed: {actual!r}")


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
    require(runtime["database_selection"] == "SERVER_OWNED_SHARED_SETTINGS_FIXED_NAME", "runtime DB selection changed")
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
    require(gate["basic_auth"] == "PRESERVED", "Basic Auth boundary weakened")
    require(gate["noindex"] == "PRESERVED", "noindex boundary weakened")
    require(gate["webform_submissions"] == 0, "webforms must be empty")
    require(gate["active_sessions"] == 0, "sessions must be empty")
    require(gate["external_action_queues"] == "CLEARED_OR_BOUNDED", "external queues are not fail-closed")
    require(gate["candidate_egress_before_runtime"] == "NONE", "candidate egress became possible before runtime")

    fence = profile["runtime_fence"]
    expected_fence = {
        "required": True,
        "server_owned": True,
        "current_host_capability_available": False,
        "close_before_runtime_session_drain": True,
        "runtime_sessions_drained_before_backup": True,
        "backup_after_fence_and_drain": True,
        "open_only_after_terminal_success_or_verified_rollback": True,
        "provisioning_required": True,
    }
    for key, expected in expected_fence.items():
        require(fence[key] == expected, f"runtime fence contract weakened: {key}")

    activation = profile["activation"]
    require(
        activation["model"] == "ATOMIC_MULTI_TABLE_RENAME_WITH_FIXED_RUNTIME_DATABASE_AND_HTTP_FENCE",
        "approved activation model changed",
    )
    require(activation["database_rename_statement"] == "FORBIDDEN", "database rename assumption introduced")
    require(activation["requires_mariadb_atomic_ddl"] is True, "atomic DDL proof is mandatory")
    for key in (
        "preflight_reject_if_views_present",
        "preflight_reject_if_triggers_present",
        "preflight_reject_if_events_present",
        "preflight_reject_if_routines_present",
        "preflight_reject_if_foreign_keys_present",
        "preflight_require_base_tables_only",
    ):
        require(activation[key] is True, f"activation preflight weakened: {key}")
    require(activation["runtime_settings_mutation"] == "NONE", "runtime settings switch introduced")
    require(activation["runtime_database_name_change"] == "NONE", "runtime DB name change introduced")

    activation_sequence = [
        "ACQUIRE_SHARED_DEPLOY_LOCK",
        "ACQUIRE_PRIVILEGED_REFRESH_LOCK",
        "REQUIRE_SANITIZED_HARDENED_SEALED_CANDIDATE",
        "REQUIRE_APPLICATION_RELEASE_IDENTITY",
        "CLOSE_SERVER_HTTP_FENCE",
        "DRAIN_RUNTIME_DATABASE_SESSIONS",
        "REVERIFY_APPLICATION_RELEASE_IDENTITY",
        "CREATE_AND_VERIFY_EXACT_RUNTIME_BACKUP",
        "ATOMIC_SWAP_BASE_TABLE_SET",
        "RUN_DRUPAL_CONVERGENCE_BEHIND_FENCE",
        "RUN_SIDE_EFFECT_AND_HEALTH_VALIDATION_BEHIND_FENCE",
        "COMMIT_OR_ROLLBACK",
        "OPEN_SERVER_HTTP_FENCE_ONLY_AFTER_TERMINAL_PROOF",
    ]
    require_exact_order(activation["sequence"], activation_sequence, "activation transaction")
    sequence_index = {step: index for index, step in enumerate(activation["sequence"])}
    require(sequence_index["CLOSE_SERVER_HTTP_FENCE"] < sequence_index["DRAIN_RUNTIME_DATABASE_SESSIONS"], "fence must close before session drain")
    require(sequence_index["DRAIN_RUNTIME_DATABASE_SESSIONS"] < sequence_index["CREATE_AND_VERIFY_EXACT_RUNTIME_BACKUP"], "session drain must precede backup")
    require(sequence_index["CREATE_AND_VERIFY_EXACT_RUNTIME_BACKUP"] < sequence_index["ATOMIC_SWAP_BASE_TABLE_SET"], "verified backup must precede activation")
    require(sequence_index["ATOMIC_SWAP_BASE_TABLE_SET"] < sequence_index["RUN_DRUPAL_CONVERGENCE_BEHIND_FENCE"], "swap must precede convergence")
    require(sequence_index["RUN_SIDE_EFFECT_AND_HEALTH_VALIDATION_BEHIND_FENCE"] < sequence_index["COMMIT_OR_ROLLBACK"], "validation must precede terminal decision")
    require(sequence_index["COMMIT_OR_ROLLBACK"] < sequence_index["OPEN_SERVER_HTTP_FENCE_ONLY_AFTER_TERMINAL_PROOF"], "fence cannot reopen before terminal proof")

    backup = profile["backup"]
    require(backup["mandatory_before_activation"] is True, "backup ceased to be mandatory")
    require(backup["capture_after_http_fence_and_session_drain"] is True, "backup capture moved before fence/drain")
    for key in ("raw_backup_github_artifact", "raw_backup_log_output", "raw_backup_user_visible"):
        require(backup[key] == "FORBIDDEN", f"raw backup exposure allowed: {key}")
    require(
        backup["exact_previous_boundary"] == "VERIFIED_DUMP_PLUS_PREACTIVATION_RUNTIME_STATE_DIGEST",
        "backup no longer proves exact previous boundary",
    )
    require(backup["active_transaction_backup_prunable"] is False, "active transaction backup became prunable")
    require("previous_runtime_state_sha256" in backup["metadata_only_evidence"], "preactivation runtime digest missing from backup evidence")

    config = profile["config_and_drupal"]
    require(config["candidate_full_drupal_boot_before_activation"] is False, "candidate full Drupal boot unexpectedly enabled before activation")
    require(config["post_swap_runs_behind_http_fence"] is True, "post-swap Drupal convergence escaped HTTP fence")
    expected_convergence = [
        "UPDB",
        "CANONICAL_CIM",
        "PREPRODUCTION_SPLIT_IMPORT",
        "RESTORE_PREPROD_ADMIN_ROUTE_FROM_SERVER_OWNED_STATE",
        "GOVERNED_CONTENT_VALIDATE",
        "GOVERNED_CONTENT_DRY_RUN",
        "CACHE_REBUILD",
        "RUNTIME_SIDE_EFFECT_VALIDATION",
        "RUNTIME_HEALTH_VALIDATION",
    ]
    require_exact_order(config["post_swap_order"], expected_convergence, "Drupal convergence")
    require(config["governed_content_apply"] == "FORBIDDEN_BY_DATA_REFRESH_AUTHORITY", "data refresh gained Governed Content apply authority")
    require(
        config["failure_after_swap"] == "ROLLBACK_EXACT_VERIFIED_BACKUP_BEFORE_HTTP_FENCE_OPENS",
        "post-swap convergence failure no longer requires exact backup rollback",
    )

    rollback = profile["rollback"]
    require(rollback["prod_source"] == "NONE", "rollback gained a PROD source")
    require(rollback["fresh_database_as_rollback"] == "FORBIDDEN", "fresh DB is not rollback proof")
    require(
        rollback["before_drupal_convergence"] == "REVERSE_ATOMIC_MULTI_TABLE_RENAME_MAY_RESTORE_EXACT_PREVIOUS_TABLE_SET",
        "pre-convergence rollback model changed",
    )
    require(
        rollback["after_drupal_convergence_started"] == "RESTORE_RECORDED_VERIFIED_PREACTIVATION_BACKUP",
        "post-convergence rollback must restore the verified preactivation backup",
    )
    require(
        rollback["post_rollback_exactness"] == "REQUIRE_RESTORED_RUNTIME_STATE_SHA256_MATCH_PREACTIVATION_RUNTIME_STATE_SHA256",
        "rollback exactness no longer binds to the preactivation runtime digest",
    )
    require(rollback["application_release_change"] == "NONE", "rollback may not change application release")
    require(rollback["http_fence_open_before_verified_restore"] is False, "HTTP fence could open before verified restore")

    failures = profile["partial_failure"]
    require(failures["before_backup"] == "CANDIDATE_CLEANUP_RUNTIME_UNCHANGED", "before-backup failure no longer preserves runtime")
    require(failures["backup_failure"] == "ABORT_RUNTIME_UNCHANGED_HTTP_FENCE_MAY_REOPEN_AFTER_UNCHANGED_PROOF", "backup failure behavior changed")
    require(failures["rename_failure"] == "ATOMIC_STATEMENT_MUST_LEAVE_PREVIOUS_RUNTIME_TABLE_SET_UNCHANGED", "atomic rename failure behavior changed")
    require(failures["post_activation_pre_convergence_failure"] == "REVERSE_RENAME_OR_BACKUP_RESTORE_TO_EXACT_PREVIOUS_BOUNDARY", "pre-convergence failure rollback weakened")
    require(failures["during_or_after_drupal_convergence"] == "RESTORE_VERIFIED_PREACTIVATION_BACKUP", "convergence failure must restore verified backup")
    require(
        failures["rollback_failure"] == "FAIL_CLOSED_KEEP_HTTP_FENCE_CLOSED_RETAIN_BACKUP_AND_RECOVERY_IDENTITY_REQUIRE_OPERATOR_RECOVERY",
        "rollback failure no longer fails closed",
    )

    locking = profile["locking"]
    require(locking["outer_lock"].endswith("/shared/deploy.lock"), "shared deploy lock must serialize refresh and release deploy")
    require(locking["outer_lock_scope"] == "WHOLE_REFRESH_TRANSACTION_INCLUDING_POST_ACTIVATION_VALIDATION_AND_ROLLBACK", "outer lock scope shortened")
    require(locking["concurrent_refresh"] == "FORBIDDEN", "concurrent refresh allowed")
    require(locking["concurrent_release_deploy"] == "FORBIDDEN", "concurrent release deploy allowed")
    require(locking["release_identity_rechecked_inside_http_fence"] is True, "release identity is not rechecked inside fence")

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
    require(future["requires_server_http_fence_provisioning"] is True, "server HTTP fence provisioning dependency disappeared")
    require(future["plan_apply_authority_distinct"] is True, "PLAN/APPLY authority merged")
    require(future["plan_namespace"] == "plan-868-", "PLAN namespace invalid")
    require(future["request_namespace"] == "apply-868-", "APPLY namespace invalid")

    evidence = profile["evidence"]
    require(evidence["metadata_only"] is True, "evidence must remain metadata-only")
    for key in ("raw_prod_sql", "raw_preprod_backup", "raw_db_values", "pii", "secrets"):
        require(evidence[key] == "FORBIDDEN", f"evidence exposure allowed: {key}")

    require(profile["files"]["public_files"] == "NONE", "public files unexpectedly entered #868")
    require(profile["files"]["private_files"] == "NONE", "private files unexpectedly entered #868")

    required_doc_phrases = (
        "runtime database = agency_preprod",
        "does **not** use a database-name switch",
        "close server HTTP/runtime fence",
        "drain existing runtime DB sessions",
        "create and verify exact PREPROD backup",
        "atomic base-table swap, not database rename",
        "views = 0",
        "triggers = 0",
        "events = 0",
        "routines = 0",
        "foreign-key constraints = 0",
        "while the HTTP/runtime fence remains closed",
        "Governed Content validation",
        "Governed Content dry-run",
        "Once `updb`/config convergence has started",
        "Rollback must restore `agency_preprod` from the exact verified pre-activation backup",
        "fence stays closed; backup/recovery identity retained",
        "new fixed root-owned pinned activation capability plus the host HTTP-fence integration",
        "REAL_PREPROD_MUTATION     = NONE",
        "REAL_PREPROD_BACKUP       = NOT_PERFORMED",
        "REAL_ACTIVATION           = NOT_PERFORMED",
        "REAL_ROLLBACK             = NOT_PERFORMED",
        "MERGE                     = NOT_PERFORMED",
    )
    for phrase in required_doc_phrases:
        require(phrase in doc, f"durable hardened design phrase missing: {phrase}")

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
    require("real_prod_data_read=NONE" in proof, "synthetic PROD read boundary missing")
    require("real_prod_data_transfer=NONE" in proof, "synthetic PROD transfer boundary missing")
    require("real_preprod_mutation=NONE" in proof, "synthetic PREPROD mutation boundary missing")
    require("prod_write_path=NONE" in proof, "PROD write absence proof missing")

    print("preprod_868_activation_boundary_contract=PASS")


if __name__ == "__main__":
    main()
