#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

# Dedicated #941 observer for the already-consumed #940 request.
# No arguments, paths, request identities, commands, or selectors are accepted
# from the caller. This file is intended to be streamed to PREPROD over the
# existing normal agency-preprod SSH identity and executed as `bash -s`.
REQUEST_ID='apply-940-first-real-s2s-r1'
EXPECTED_MAIN='0b61d56264ad0163cd3bdbd5ea6e07253a155fbb'
JOB_ROOT='/var/www/agency-preprod/shared/refresh-jobs'
JOB="$JOB_ROOT/$REQUEST_ID"
RESULT="$JOB/result.env"
SANITIZED="$JOB/sanitized.sql"
SANITIZED_TMP="$JOB/sanitized.sql.tmp"
ACTIVATION_WORKER="$JOB/worker.sh"
ROOT_STAGE_PARENT='/run/agency-preprod-refresh'
ROOT_STAGE="$ROOT_STAGE_PARENT/412fc11485c5"
BOOTSTRAP_LOG="$ROOT_STAGE/bootstrap.log"
DEPLOY_LOCK='/var/www/agency-preprod/shared/deploy.lock'
REFRESH_LOCK='/run/lock/agency-preprod-refresh.lock'
CURRENT='/var/www/agency-preprod/current'
CURRENT_UID="$(id -u)"

classify() {
  local terminal_state="$1"
  local result_outcome="$2"
  local worker_process="$3"
  local worker_phase="$4"
  local unsafe="$5"

  if [[ "$unsafe" == YES ]]; then
    printf '%s\n' 'UNOBSERVABLE_FAIL_CLOSED'
    return
  fi

  if [[ "$terminal_state" == PRESENT ]]; then
    case "$result_outcome" in
      COMMITTED|ROLLED_BACK|HUMAN_RECOVERY_REQUIRED)
        printf '%s\n' "$result_outcome"
        ;;
      *)
        printf '%s\n' 'UNOBSERVABLE_FAIL_CLOSED'
        ;;
    esac
    return
  fi

  case "$worker_process:$worker_phase" in
    ALIVE:PREACTIVATION)
      printf '%s\n' 'WORKER_ALIVE_PREACTIVATION'
      ;;
    ALIVE:ACTIVATION_OR_CONVERGENCE)
      printf '%s\n' 'WORKER_ALIVE_ACTIVATION_OR_CONVERGENCE'
      ;;
    DEAD:NONE)
      printf '%s\n' 'WORKER_DEAD_NO_TERMINAL_METADATA'
      ;;
    *)
      printf '%s\n' 'UNOBSERVABLE_FAIL_CLOSED'
      ;;
  esac
}

self_test() {
  [[ "$(classify ABSENT NONE ALIVE PREACTIVATION NO)" == WORKER_ALIVE_PREACTIVATION ]]
  [[ "$(classify ABSENT NONE ALIVE ACTIVATION_OR_CONVERGENCE NO)" == WORKER_ALIVE_ACTIVATION_OR_CONVERGENCE ]]
  [[ "$(classify ABSENT NONE DEAD NONE NO)" == WORKER_DEAD_NO_TERMINAL_METADATA ]]
  [[ "$(classify ABSENT NONE UNOBSERVABLE UNOBSERVABLE NO)" == UNOBSERVABLE_FAIL_CLOSED ]]
  [[ "$(classify PRESENT COMMITTED DEAD NONE NO)" == COMMITTED ]]
  [[ "$(classify PRESENT ROLLED_BACK ALIVE ACTIVATION_OR_CONVERGENCE NO)" == ROLLED_BACK ]]
  [[ "$(classify PRESENT HUMAN_RECOVERY_REQUIRED DEAD NONE NO)" == HUMAN_RECOVERY_REQUIRED ]]
  [[ "$(classify PRESENT INVALID ALIVE PREACTIVATION NO)" == UNOBSERVABLE_FAIL_CLOSED ]]
  [[ "$(classify ABSENT NONE ALIVE PREACTIVATION YES)" == UNOBSERVABLE_FAIL_CLOSED ]]
}

if [[ "${1:-}" == '--self-test' ]]; then
  [[ "$#" -eq 1 ]]
  self_test
  printf '%s\n' 'SELF_TEST=PASS'
  exit 0
fi
[[ "$#" -eq 0 ]]

field() {
  local key="$1"
  local file="$2"
  awk -F= -v wanted="$key" '
    $1 == wanted {
      sub(/^[^=]*=/, "")
      print
      exit
    }
  ' "$file"
}

request_dir_state='ABSENT'
unsafe='NO'
if [[ -L "$JOB" ]]; then
  request_dir_state='UNSAFE'
  unsafe='YES'
elif [[ -d "$JOB" ]]; then
  request_meta="$(stat -c '%u:%a' -- "$JOB" 2>/dev/null || true)"
  if [[ "$request_meta" == "$CURRENT_UID:700" ]]; then
    request_dir_state='PRESENT'
  else
    request_dir_state='UNSAFE'
    unsafe='YES'
  fi
elif [[ -e "$JOB" ]]; then
  request_dir_state='UNSAFE'
  unsafe='YES'
fi

safe_file_state() {
  local path="$1"
  if [[ -L "$path" ]]; then
    printf '%s\n' 'UNSAFE'
  elif [[ -f "$path" ]]; then
    printf '%s\n' 'PRESENT'
  elif [[ -e "$path" ]]; then
    printf '%s\n' 'UNSAFE'
  else
    printf '%s\n' 'ABSENT'
  fi
}

result_env="$(safe_file_state "$RESULT")"
sanitized_sql="$(safe_file_state "$SANITIZED")"
sanitized_sql_tmp="$(safe_file_state "$SANITIZED_TMP")"
activation_worker="$(safe_file_state "$ACTIVATION_WORKER")"
for state in "$result_env" "$sanitized_sql" "$sanitized_sql_tmp" "$activation_worker"; do
  if [[ "$state" == UNSAFE ]]; then
    unsafe='YES'
  fi
done

result_outcome='NONE'
result_detail='NONE'
terminal_metadata_state='NO_TERMINAL_METADATA'
if [[ "$result_env" == PRESENT ]]; then
  terminal_metadata_state='TERMINAL_METADATA_PRESENT'
  result_meta="$(stat -c '%u:%a:%s' -- "$RESULT" 2>/dev/null || true)"
  IFS=: read -r result_uid result_mode result_bytes <<< "$result_meta"
  if [[ "$result_uid" != "$CURRENT_UID" || "$result_mode" != 600 || ! "$result_bytes" =~ ^[0-9]{1,4}$ || "$result_bytes" -gt 4096 ]]; then
    result_outcome='INVALID'
    result_detail='INVALID'
    unsafe='YES'
  else
    schema="$(field schema_version "$RESULT")"
    result_request="$(field request_id "$RESULT")"
    result_main="$(field main_sha "$RESULT")"
    candidate_outcome="$(field outcome "$RESULT")"
    candidate_detail="$(field detail "$RESULT")"
    if [[ "$schema" != 1 || "$result_request" != "$REQUEST_ID" || "$result_main" != "$EXPECTED_MAIN" ]]; then
      result_outcome='INVALID'
      result_detail='INVALID'
      unsafe='YES'
    else
      case "$candidate_outcome" in
        COMMITTED)
          if [[ "$candidate_detail" == SANITIZED_DATABASE_ACTIVE_AND_VALIDATED ]]; then
            result_outcome='COMMITTED'
            result_detail="$candidate_detail"
          else
            result_outcome='INVALID'; result_detail='INVALID'; unsafe='YES'
          fi
          ;;
        ROLLED_BACK)
          case "$candidate_detail" in
            NO_PREPROD_RUNTIME_MUTATION|EXACT_BACKUP_OR_UNCHANGED_RUNTIME_PROVEN|NO_MUTATION_DEPLOY_LOCK_BUSY|NO_PREPROD_RUNTIME_MUTATION_SERVER_TO_SERVER_PREP_FAILED|NO_PREPROD_RUNTIME_MUTATION_SANITIZED_HANDOFF_FAILED|NO_PREPROD_RUNTIME_MUTATION_ACTIVATION_LAUNCH_FAILED|NO_PREPROD_RUNTIME_MUTATION_UNEXPECTED_PREACTIVATION_FAILURE)
              result_outcome='ROLLED_BACK'; result_detail="$candidate_detail"
              ;;
            *)
              result_outcome='INVALID'; result_detail='INVALID'; unsafe='YES'
              ;;
          esac
          ;;
        HUMAN_RECOVERY_REQUIRED)
          case "$candidate_detail" in
            RAW_STAGING_CLEANUP_UNPROVEN|PROD_IDENTITY_STAGE_CLEANUP_UNPROVEN|ROLLBACK_NOT_PROVEN_MAINTENANCE_REMAINS_ON)
              result_outcome='HUMAN_RECOVERY_REQUIRED'; result_detail="$candidate_detail"
              ;;
            *)
              result_outcome='INVALID'; result_detail='INVALID'; unsafe='YES'
              ;;
          esac
          ;;
        *)
          result_outcome='INVALID'; result_detail='INVALID'; unsafe='YES'
          ;;
      esac
    fi
  fi
elif [[ "$result_env" == UNSAFE ]]; then
  terminal_metadata_state='UNSAFE'
  result_outcome='INVALID'
  result_detail='INVALID'
fi

worker_process='UNOBSERVABLE'
worker_phase='UNOBSERVABLE'
worker_process_count='0'
worker_elapsed_seconds='NONE'
# PID 1 is root-owned on the PREPROD host. If its cmdline is readable by the
# ordinary deploy user, cross-UID process command lines are observable enough
# for fixed request-id matching; otherwise absence must not be classified DEAD.
if command -v pgrep >/dev/null 2>&1 && command -v ps >/dev/null 2>&1 && head -c 1 /proc/1/cmdline >/dev/null 2>&1; then
  mapfile -t worker_pids < <(pgrep -f -- "$REQUEST_ID" 2>/dev/null | head -n 9 || true)
  if [[ "${#worker_pids[@]}" -gt 8 ]]; then
    worker_process='UNOBSERVABLE'
    worker_phase='UNOBSERVABLE'
    worker_process_count='0'
  elif [[ "${#worker_pids[@]}" -eq 0 ]]; then
    worker_process='DEAD'
    worker_phase='NONE'
  else
    worker_process='ALIVE'
    worker_process_count="${#worker_pids[@]}"
    max_elapsed=0
    elapsed_observable='YES'
    seen_root='NO'
    seen_deploy_user='NO'
    process_identity_observable='YES'
    for worker_pid in "${worker_pids[@]}"; do
      if [[ ! "$worker_pid" =~ ^[0-9]{1,8}$ || ! -r "/proc/$worker_pid/stat" ]]; then
        elapsed_observable='NO'
        process_identity_observable='NO'
        continue
      fi
      process_meta="$(ps -o euid=,etimes= -p "$worker_pid" 2>/dev/null | awk '{$1=$1; print}' || true)"
      read -r process_euid elapsed <<< "$process_meta"
      if [[ ! "$process_euid" =~ ^[0-9]{1,8}$ || ! "$elapsed" =~ ^[0-9]{1,8}$ ]]; then
        elapsed_observable='NO'
        process_identity_observable='NO'
        continue
      fi
      if (( elapsed > max_elapsed )); then
        max_elapsed="$elapsed"
      fi
      if [[ "$process_euid" == "$CURRENT_UID" ]]; then
        seen_deploy_user='YES'
      elif [[ "$process_euid" == 0 ]]; then
        seen_root='YES'
      else
        process_identity_observable='NO'
      fi
    done
    if [[ "$elapsed_observable" == YES ]]; then
      worker_elapsed_seconds="$max_elapsed"
    fi
    if [[ "$process_identity_observable" == YES && "$seen_deploy_user" == YES ]]; then
      worker_phase='ACTIVATION_OR_CONVERGENCE'
    elif [[ "$process_identity_observable" == YES && "$seen_root" == YES ]]; then
      worker_phase='PREACTIVATION'
    else
      worker_phase='UNOBSERVABLE'
    fi
  fi
fi

root_identity_stage='UNOBSERVABLE'
if [[ -L "$ROOT_STAGE" ]]; then
  root_identity_stage='UNOBSERVABLE'
  unsafe='YES'
elif [[ -d "$ROOT_STAGE" ]]; then
  root_identity_stage='PRESENT'
elif [[ -e "$ROOT_STAGE" ]]; then
  root_identity_stage='UNOBSERVABLE'
  unsafe='YES'
elif [[ -x "$ROOT_STAGE_PARENT" ]]; then
  root_identity_stage='ABSENT'
fi

bootstrap_log='UNOBSERVABLE'
bootstrap_log_bytes='NONE'
if [[ "$root_identity_stage" == ABSENT ]]; then
  bootstrap_log='ABSENT'
elif [[ -L "$BOOTSTRAP_LOG" ]]; then
  bootstrap_log='UNOBSERVABLE'
  unsafe='YES'
elif [[ -f "$BOOTSTRAP_LOG" ]]; then
  bootstrap_bytes="$(stat -c '%s' -- "$BOOTSTRAP_LOG" 2>/dev/null || true)"
  if [[ "$bootstrap_bytes" =~ ^[0-9]{1,12}$ ]]; then
    bootstrap_log='PRESENT'
    bootstrap_log_bytes="$bootstrap_bytes"
  fi
fi

observe_lock() {
  local path="$1"
  local lock_paths
  if ! command -v lslocks >/dev/null 2>&1; then
    printf '%s\n' 'UNOBSERVABLE'
    return
  fi
  if ! lock_paths="$(lslocks --noheadings --output PATH 2>/dev/null)"; then
    printf '%s\n' 'UNOBSERVABLE'
    return
  fi
  if printf '%s\n' "$lock_paths" | awk '{$1=$1; print}' | grep -Fxq -- "$path"; then
    printf '%s\n' 'HELD'
  else
    printf '%s\n' 'FREE'
  fi
}

deploy_lock="$(observe_lock "$DEPLOY_LOCK")"
refresh_lock="$(observe_lock "$REFRESH_LOCK")"

observe_health() {
  local path="$1"
  local code
  if ! command -v curl >/dev/null 2>&1; then
    printf '%s\n' 'UNOBSERVABLE'
    return
  fi
  if ! code="$(curl --silent --show-error --connect-timeout 2 --max-time 5 --output /dev/null --write-out '%{http_code}' --header 'Host: preprod.emergingdigital.be' "http://127.0.0.1${path}" 2>/dev/null)"; then
    printf '%s\n' 'FAIL'
    return
  fi
  if [[ "$code" == 200 ]]; then
    printf '%s\n' 'PASS'
  else
    printf '%s\n' 'FAIL'
  fi
}

health_live="$(observe_health '/health/live')"
health_ready="$(observe_health '/health/ready')"

active_release_sha='UNOBSERVABLE'
active_release_prefix='NONE'
active_release="$(readlink -f -- "$CURRENT" 2>/dev/null || true)"
active_release_base="${active_release##*/}"
if [[ "$active_release_base" =~ ^[0-9]{14}-([0-9a-f]{12})$ ]]; then
  active_release_prefix="${BASH_REMATCH[1]}"
fi

# The temporary #938 path does not expose these root/DB-backed surfaces safely
# to the ordinary PREPROD account. Preserve the boundary instead of inferring.
raw_staging_scope='UNOBSERVABLE'
maintenance_mode='UNOBSERVABLE'
refresh_fence='UNOBSERVABLE'

classification="$(classify "$result_env" "$result_outcome" "$worker_process" "$worker_phase" "$unsafe")"

printf '%s\n' \
  'schema_version=1' \
  "request_id=$REQUEST_ID" \
  "expected_main=$EXPECTED_MAIN" \
  "request_dir=$request_dir_state" \
  "result_env=$result_env" \
  "terminal_metadata_state=$terminal_metadata_state" \
  "result_outcome=$result_outcome" \
  "result_detail=$result_detail" \
  "worker_process=$worker_process" \
  "worker_phase=$worker_phase" \
  "worker_process_count=$worker_process_count" \
  "worker_elapsed_seconds=$worker_elapsed_seconds" \
  "bootstrap_log=$bootstrap_log" \
  "bootstrap_log_bytes=$bootstrap_log_bytes" \
  "sanitized_sql=$sanitized_sql" \
  "sanitized_sql_tmp=$sanitized_sql_tmp" \
  "activation_worker=$activation_worker" \
  "raw_staging_scope=$raw_staging_scope" \
  "root_identity_stage=$root_identity_stage" \
  "refresh_lock=$refresh_lock" \
  "deploy_lock=$deploy_lock" \
  "maintenance_mode=$maintenance_mode" \
  "refresh_fence=$refresh_fence" \
  "active_release_sha=$active_release_sha" \
  "active_release_prefix=$active_release_prefix" \
  "health_live=$health_live" \
  "health_ready=$health_ready" \
  "classification=$classification"
