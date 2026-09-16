#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PATH='/usr/sbin:/usr/bin:/sbin:/bin'
LC_ALL=C
export PATH LC_ALL

HELPER_DEST='/usr/local/sbin/agency-prod-runtime-error-counts'
SUDOERS_DEST='/etc/sudoers.d/agency-prod-runtime-error-counts'
STAGE_DIR="$HOME/.agency-1202-runtime-error-capability-stage"
STAGE_HELPER="$STAGE_DIR/agency-prod-runtime-error-counts"
STAGE_SUDOERS="$STAGE_DIR/agency-prod-runtime-error-counts.sudoers"

probe_exact_sudo() {
  local result
  result="$(probe_exact_sudo_with_reason "$@")"
  printf '%s' "${result%% *}"
}
probe_exact_sudo_with_reason() {
  # Listing success alone proves neither NOPASSWD nor exact authorization.
  # Accept only one fully understood long-format entry; keep policy private.
  local policy stderr_file result rc=0
  if ! stderr_file="$(umask 077; mktemp 2>/dev/null)"; then
    printf '%s' 'UNKNOWN INTERNAL_TEMPFILE_ERROR'
    return
  fi
  policy="$(LC_ALL=C sudo -k -n -ll -- "$@" 2>"$stderr_file")" || rc=$?
  result="$(printf '%s' "$policy" | python3 -c '
import re
import sys

policy = sys.stdin.read()
rc = int(sys.argv[1])
with open(sys.argv[2], encoding="utf-8") as stream:
    error = stream.read()
args = sys.argv[3:]
expected = " ".join(args)
pattern = (r"\s*Sudoers entry:(?: /[^\s]+)?\n[ \t]+RunAsUsers: root\n"
           r"(?:[ \t]+RunAsGroups: root\n)?"
           r"[ \t]+Options: ([^\n]+)\n"
           r"[ \t]+Commands:\n[ \t]+([^\n]+)\n"
           r"[ \t]*Matched: ([^\n]+)\s*")
match = re.fullmatch(pattern, policy)
options = [option.strip() for option in match[1].split(",")] if match else []
# Only understood defaults/tags: unknown options can change authorization.
allowed = {"!authenticate", "authenticate", "!setenv", "env_reset",
           "mail_badpass", "use_pty", "log_input", "log_output",
           "noexec", "!sudoedit_follow", "sudoedit_checkdir"}
auth = set(options) & {"authenticate", "!authenticate"}
safe_options = all(option in allowed or re.fullmatch(
    r"secure_path=/[A-Za-z0-9_./-]*(?::/[A-Za-z0-9_./-]*)*", option)
    for option in options)
safe_args = bool(args) and all(re.fullmatch(r"[A-Za-z0-9_./=-]+", arg) for arg in args)
exact = (match is not None and match[2] == expected and match[3] == expected
         and len(options) == len({option.split("=", 1)[0] for option in options})
         and len(auth) == 1
         and safe_options and safe_args)
state = "UNKNOWN"
if rc == 0 and not error and exact:
    state = "AVAILABLE" if "!authenticate" in auth else "UNAVAILABLE"
elif rc == 1 and not policy.strip() and safe_args:
    # Require a complete C-locale command denial, never a listing/auth failure.
    quote = chr(39)
    command = re.escape(quote + expected + quote)
    identity = r"[A-Za-z0-9_.-]+"
    denial = (r"(?:sudo: )?(?:Sorry, user " + identity
              + r" is not allowed to execute " + command
              + r" as root on " + identity + r"\.|User " + identity
              + r" is not allowed to run " + command
              + r" as root on " + identity + r"\.)\n?")
    if re.fullmatch(denial, error):
        state = "UNAVAILABLE"
# Diagnostics describe only UNKNOWN; the #1208 decision above is unchanged.
reason = "NONE"
if state == "UNKNOWN":
    if "sudo: a password is required" in error:
        reason = "STDERR_PASSWORD_REQUIRED"
    elif error:
        reason = "STDERR_PRESENT"
    elif rc != 0:
        reason = "EXIT_UNSUPPORTED"
    elif not policy.strip():
        reason = "POLICY_EMPTY"
    elif len(re.findall(r"(?m)^\s*Sudoers entry:", policy)) > 1:
        reason = "MULTIPLE_ENTRIES"
    elif not re.search(r"(?m)^[ \t]*Matched:", policy):
        reason = ("MATCHED_MISSING" if re.search(
            r"(?m)^\s*Sudoers entry:", policy) else "POLICY_FORMAT_UNSUPPORTED")
    elif re.search(r"(?m)^[ \t]+RunAs(?:Users|Groups): (?!root$)", policy):
        reason = "RUNAS_MISMATCH"
    elif match is None:
        reason = "POLICY_FORMAT_UNSUPPORTED"
    elif match[2] != expected or match[3] != expected or not safe_args:
        reason = "COMMAND_MISMATCH"
    elif len(auth) != 1 or any(options.count(tag) > 1 for tag in auth):
        reason = "AUTH_AMBIGUOUS"
    elif not safe_options or len(options) != len({
            option.split("=", 1)[0] for option in options}):
        reason = "OPTION_UNSUPPORTED"
    else:
        reason = "POLICY_FORMAT_UNSUPPORTED"
print(state, reason, end="")
' "$rc" "$stderr_file" "$@" 2>/dev/null)" || result='UNKNOWN INTERNAL_PARSER_ERROR'
  if ! rm -f -- "$stderr_file" 2>/dev/null; then
    result='UNKNOWN INTERNAL_TEMPFILE_ERROR'
  fi
  case "$result" in
    'AVAILABLE NONE'|'UNAVAILABLE NONE'|\
    'UNKNOWN INTERNAL_TEMPFILE_ERROR'|'UNKNOWN INTERNAL_PARSER_ERROR'|\
    'UNKNOWN STDERR_PASSWORD_REQUIRED'|'UNKNOWN STDERR_PRESENT'|\
    'UNKNOWN EXIT_UNSUPPORTED'|'UNKNOWN POLICY_EMPTY'|\
    'UNKNOWN MULTIPLE_ENTRIES'|'UNKNOWN MATCHED_MISSING'|\
    'UNKNOWN RUNAS_MISMATCH'|'UNKNOWN COMMAND_MISMATCH'|\
    'UNKNOWN AUTH_AMBIGUOUS'|'UNKNOWN OPTION_UNSUPPORTED'|\
    'UNKNOWN POLICY_FORMAT_UNSUPPORTED') printf '%s' "$result" ;;
    *) printf '%s' 'UNKNOWN INTERNAL_PARSER_ERROR' ;;
  esac
}
fixed_sudoers_hash() {
  # Optional pre-existing read capability only; never provision this privilege.
  sudo -k -n -- /usr/bin/sha256sum -- /etc/sudoers.d/agency-prod-runtime-error-counts 2>/dev/null |
    python3 -c '
import re
import sys

match = re.fullmatch(r"([0-9a-f]{64})  /etc/sudoers\.d/agency-prod-runtime-error-counts\n", sys.stdin.read())
if not match:
    raise SystemExit(1)
print(match[1], end="")
' 2>/dev/null
}
classify_target() {
  local path="$1"
  local expected_hash="$2"
  local expected_meta="$3"
  local metadata actual_hash

  if [[ -L "$path" ]]; then
    printf '%s' 'NONCONFORMANT'
    return
  fi
  if [[ ! -e "$path" ]]; then
    printf '%s' 'ABSENT'
    return
  fi
  if [[ ! -f "$path" ]]; then
    printf '%s' 'NONCONFORMANT'
    return
  fi
  if ! metadata="$(stat -c '%U:%G:%a' -- "$path" 2>/dev/null)"; then
    printf '%s' 'UNKNOWN'
    return
  fi
  if [[ -r "$path" ]]; then
    if ! actual_hash="$(sha256sum -- "$path" 2>/dev/null | awk '{print $1}')"; then
      printf '%s' 'UNKNOWN'
      return
    fi
  elif [[ "$path" == "$SUDOERS_DEST" ]]; then
    if ! actual_hash="$(fixed_sudoers_hash)"; then
      printf '%s' 'UNKNOWN'
      return
    fi
  else
    printf '%s' 'UNKNOWN'
    return
  fi
  if [[ ! "$actual_hash" =~ ^[0-9a-f]{64}$ ]]; then
    printf '%s' 'UNKNOWN'
    return
  fi
  [[ "$metadata" == "$expected_meta" && "$actual_hash" == "$expected_hash" ]] \
    && printf '%s' 'ALREADY_CONFORMANT' \
    || printf '%s' 'NONCONFORMANT'
}
MAIN_SHA="${1:-}"
PLAN_ID="${2:-}"
SERVER_USER="${3:-}"
HELPER_SOURCE_SHA256="${4:-}"
RENDERED_SUDOERS_SHA256="${5:-}"

[[ "$#" -eq 5 ]]
[[ "$MAIN_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$PLAN_ID" =~ ^plan-1202-[1-9][0-9]*-1$ ]]
[[ "$SERVER_USER" =~ ^[A-Za-z0-9._-]+$ ]]
[[ "$HELPER_SOURCE_SHA256" =~ ^[0-9a-f]{64}$ ]]
[[ "$RENDERED_SUDOERS_SHA256" =~ ^[0-9a-f]{64}$ ]]
[[ "$(id -un)" == "$SERVER_USER" ]]
[[ "$HOME" == /* ]]

SERVER_USER_SHA256="$(printf '%s' "$SERVER_USER" | sha256sum | awk '{print $1}')"
HELPER_STATE="$(classify_target "$HELPER_DEST" "$HELPER_SOURCE_SHA256" 'root:root:755')"
SUDOERS_STATE="$(classify_target "$SUDOERS_DEST" "$RENDERED_SUDOERS_SHA256" 'root:root:440')"
read -r PRIVILEGED_HELPER_INSTALL WHY_UNKNOWN_HELPER_INSTALL <<< "$(probe_exact_sudo_with_reason \
  /usr/bin/install -o root -g root -m 0755 -- \
  "$STAGE_HELPER" "$HELPER_DEST")"
read -r PRIVILEGED_SUDOERS_INSTALL WHY_UNKNOWN_SUDOERS_INSTALL <<< "$(probe_exact_sudo_with_reason \
  /usr/bin/install -o root -g root -m 0440 -- \
  "$STAGE_SUDOERS" "$SUDOERS_DEST")"

read -r PRIVILEGED_VISUDO_VALIDATION WHY_UNKNOWN_VISUDO_VALIDATION <<< "$(probe_exact_sudo_with_reason \
  /usr/sbin/visudo -cf "$STAGE_SUDOERS")"

export MAIN_SHA PLAN_ID SERVER_USER_SHA256
export HELPER_SOURCE_SHA256 RENDERED_SUDOERS_SHA256
export HELPER_STATE SUDOERS_STATE
export PRIVILEGED_HELPER_INSTALL PRIVILEGED_SUDOERS_INSTALL
export PRIVILEGED_VISUDO_VALIDATION
export WHY_UNKNOWN_HELPER_INSTALL WHY_UNKNOWN_SUDOERS_INSTALL WHY_UNKNOWN_VISUDO_VALIDATION
python3 - <<'PY'
import hashlib
import json
import os
import sys

helper_state = os.environ['HELPER_STATE']
sudoers_state = os.environ['SUDOERS_STATE']
valid_states = {'ABSENT', 'ALREADY_CONFORMANT', 'NONCONFORMANT', 'UNKNOWN'}
if helper_state not in valid_states or sudoers_state not in valid_states:
    raise SystemExit(70)

if helper_state == 'ABSENT' and sudoers_state == 'ABSENT':
    install_state = 'ABSENT'
elif helper_state == 'ALREADY_CONFORMANT' and sudoers_state == 'ALREADY_CONFORMANT':
    install_state = 'ALREADY_CONFORMANT'
elif 'NONCONFORMANT' in {helper_state, sudoers_state}:
    install_state = 'NONCONFORMANT'
elif 'UNKNOWN' in {helper_state, sudoers_state}:
    install_state = 'UNKNOWN'
else:
    install_state = 'NONCONFORMANT'

privileges = {
    'PRIVILEGED_HELPER_INSTALL': os.environ['PRIVILEGED_HELPER_INSTALL'],
    'PRIVILEGED_SUDOERS_INSTALL': os.environ['PRIVILEGED_SUDOERS_INSTALL'],
    'PRIVILEGED_VISUDO_VALIDATION': os.environ['PRIVILEGED_VISUDO_VALIDATION'],
}
valid_privileges = {'AVAILABLE', 'UNAVAILABLE', 'UNKNOWN'}
if any(value not in valid_privileges for value in privileges.values()):
    raise SystemExit(70)

valid_reasons = {
    'NONE', 'INTERNAL_TEMPFILE_ERROR', 'INTERNAL_PARSER_ERROR',
    'STDERR_PASSWORD_REQUIRED', 'STDERR_PRESENT', 'EXIT_UNSUPPORTED',
    'POLICY_EMPTY', 'MULTIPLE_ENTRIES', 'MATCHED_MISSING', 'RUNAS_MISMATCH',
    'COMMAND_MISMATCH', 'AUTH_AMBIGUOUS', 'OPTION_UNSUPPORTED',
    'POLICY_FORMAT_UNSUPPORTED',
}
reasons = {}
for key, privilege in privileges.items():
    reason_key = key.replace('PRIVILEGED_', 'WHY_UNKNOWN_', 1)
    reason = os.environ.get(reason_key)
    if reason not in valid_reasons or (reason == 'NONE') != (privilege != 'UNKNOWN'):
        raise SystemExit(70)
    reasons[reason_key] = reason

status = 'FAIL'
install_decision = 'BLOCKED'
if all(value == 'AVAILABLE' for value in privileges.values()):
    if install_state == 'ABSENT':
        status = 'PASS'
        install_decision = 'INSTALL_REQUIRED'
    elif install_state == 'ALREADY_CONFORMANT':
        status = 'PASS'
        install_decision = 'ALREADY_CONFORMANT'

identity = {
    'ISSUE': 1202,
    'TARGET': 'PROD',
    'MODE': 'PLAN',
    'MAIN_SHA': os.environ['MAIN_SHA'],
    'PLAN_ID': os.environ['PLAN_ID'],
    'SERVER_USER_SHA256': os.environ['SERVER_USER_SHA256'],
    'HELPER_SOURCE_SHA256': os.environ['HELPER_SOURCE_SHA256'],
    'RENDERED_SUDOERS_SHA256': os.environ['RENDERED_SUDOERS_SHA256'],
    'HELPER_DESTINATION': '/usr/local/sbin/agency-prod-runtime-error-counts',
    'SUDOERS_DESTINATION': '/etc/sudoers.d/agency-prod-runtime-error-counts',
}
identity.update({
    'HELPER_EXPECTED_OWNER_GROUP_MODE': 'root:root:0755',
    'SUDOERS_EXPECTED_OWNER_GROUP_MODE': 'root:root:0440',
    'HELPER_STATE': helper_state,
    'SUDOERS_STATE': sudoers_state,
    'INSTALL_STATE': install_state,
    'INSTALL_DECISION': install_decision,
    **privileges,
})

plan_digest = None
if status == 'PASS':
    encoded = json.dumps(identity, sort_keys=True, separators=(',', ':')).encode()
    plan_digest = hashlib.sha256(encoded).hexdigest()

receipt = dict(identity)
receipt.update({
    **reasons,
    'STATUS': status,
    'RAW_SUDO_POLICY_EXPOSURE': 'NONE',
    'NONCONFORMANT_OVERWRITE': 'FORBIDDEN',
    'REAL_PROD_MUTATION': 'NONE',
    'CANNOT_BE_APPROVED': 'NO' if status == 'PASS' else 'YES',
    'PLAN_DIGEST': plan_digest,
})
print(json.dumps(receipt, sort_keys=True, separators=(',', ':')))
sys.exit(0 if status == 'PASS' else 1)
PY
