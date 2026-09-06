#!/usr/bin/env bash
set -Eeuo pipefail

# #390 — Outside AI read-only early-adoption pilot.
#
# This script is intentionally DDEV/local-only and disposable. It does not
# persist prerelease dependencies in the product branch and never targets
# PREPROD or PROD.
#
# Existing upstream capabilities reused:
# - Tool API
# - Tool Belt - Entity
# - MCP Server
# - MCP Server Tool Bridge
#
# No Agency Tool API plugin or MCP implementation is created here.

readonly TOOL_VERSION="1.0.0-beta7"
readonly TOOL_BELT_VERSION="1.0.0-alpha5"
readonly MCP_SERVER_VERSION="2.0.0-beta2"
readonly MCP_BRIDGE_VERSION="1.0.0-beta1"

readonly PILOT_USER="agency_mcp_pilot"
readonly PILOT_MAIL="agency-mcp-pilot@example.invalid"
readonly ENTITY_TYPES_TOOL="tool_belt:entity_type_list"
readonly BUNDLE_FIELDS_TOOL="tool_belt:entity_bundle_field_definitions"
readonly SNAPSHOT_NAME="outside-ai-390-$(date -u +%Y%m%d%H%M%S)"

ROOT="$(git rev-parse --show-toplevel 2>/dev/null || true)"
if [[ -z "${ROOT}" || ! -f "${ROOT}/composer.json" || ! -f "${ROOT}/composer.lock" ]]; then
  echo "ERROR: run from the Agency repository checkout." >&2
  exit 2
fi
cd "${ROOT}"

for command in ddev git python3; do
  command -v "${command}" >/dev/null 2>&1 || {
    echo "ERROR: required command is unavailable: ${command}" >&2
    exit 2
  }
done

if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
  echo "ERROR: tracked working tree must be clean before the disposable pilot." >&2
  exit 2
fi

if ! ddev describe >/dev/null 2>&1; then
  echo "ERROR: DDEV project is not available/running." >&2
  exit 2
fi

TMP_DIR="$(mktemp -d)"
cp composer.json "${TMP_DIR}/composer.json"
cp composer.lock "${TMP_DIR}/composer.lock"

DB_SNAPSHOT_CREATED=0
CLEANUP_DONE=0

cleanup() {
  local exit_code=$?
  if [[ "${CLEANUP_DONE}" -eq 1 ]]; then
    exit "${exit_code}"
  fi
  CLEANUP_DONE=1
  trap - EXIT

  set +e

  if [[ "${DB_SNAPSHOT_CREATED}" -eq 1 ]]; then
    ddev snapshot restore "${SNAPSHOT_NAME}" >/dev/null 2>&1
  fi

  cp "${TMP_DIR}/composer.json" composer.json
  cp "${TMP_DIR}/composer.lock" composer.lock
  ddev composer install --no-interaction >/dev/null 2>&1

  if [[ "${DB_SNAPSHOT_CREATED}" -eq 1 ]]; then
    ddev snapshot --cleanup --name "${SNAPSHOT_NAME}" -y >/dev/null 2>&1
  fi

  rm -rf "${TMP_DIR}"

  if [[ "${exit_code}" -eq 0 ]]; then
    echo "CLEANUP=PASS"
  else
    echo "CLEANUP=PASS_AFTER_FAILURE"
  fi
  exit "${exit_code}"
}
trap cleanup EXIT

echo "TARGET=DDEV"
echo "PROD_ACCESS=NONE"
echo "PREPROD_ACCESS=NONE"
echo "WRITE_CAPABILITIES=NONE"

ddev snapshot --name "${SNAPSHOT_NAME}" -y >/dev/null
DB_SNAPSHOT_CREATED=1

echo "COMPOSER_PHASE=START"
ddev composer require --no-interaction --with-all-dependencies \
  "drupal/tool:${TOOL_VERSION}" \
  "drupal/tool_belt:${TOOL_BELT_VERSION}" \
  "drupal/mcp_server:${MCP_SERVER_VERSION}" \
  "drupal/mcp_server_tool_bridge-mcp_server_tool_bridge:${MCP_BRIDGE_VERSION}"

ddev composer audit --locked --no-interaction

# Enable only the minimum modules needed for the two schema-read tools and MCP
# exposure. Do not enable Tool Belt content/user/system/workspace modules.
ddev drush en -y tool tool_belt_entity mcp_server mcp_server_tool_bridge
ddev drush cr -y

echo "UPSTREAM_STACK=INSTALLED"
echo "TOOL_API=${TOOL_VERSION}"
echo "TOOL_BELT=${TOOL_BELT_VERSION}"
echo "MCP_SERVER=${MCP_SERVER_VERSION}"
echo "MCP_SERVER_TOOL_BRIDGE=${MCP_BRIDGE_VERSION}"

# Prove the exact upstream tools exist before any MCP configuration.
ddev drush tool:info "${ENTITY_TYPES_TOOL}" --json >"${TMP_DIR}/entity-types-info.json"
ddev drush tool:info "${BUNDLE_FIELDS_TOOL}" --json >"${TMP_DIR}/bundle-fields-info.json"

# Create a dedicated local, non-admin principal. It receives no elevated role.
# If the upstream read tools require broad administration permissions, the pilot
# must fail rather than granting them merely to make the demo pass.
if ! ddev drush php:eval "\$u = user_load_by_name('${PILOT_USER}'); exit(\$u ? 0 : 1);" >/dev/null 2>&1; then
  PILOT_PASSWORD="$(python3 - <<'PY'
import secrets
print(secrets.token_urlsafe(32))
PY
)"
  ddev drush user:create "${PILOT_USER}" \
    --mail="${PILOT_MAIL}" \
    --password="${PILOT_PASSWORD}" >/dev/null
fi

PILOT_UID="$(
  ddev drush php:eval "\$u = user_load_by_name('${PILOT_USER}'); if (!\$u) { throw new \RuntimeException('pilot user missing'); } echo \$u->id();" \
    2>/dev/null
)"

if [[ -z "${PILOT_UID}" || "${PILOT_UID}" == "1" ]]; then
  echo "ERROR: dedicated non-admin Drupal principal was not proven." >&2
  exit 3
fi

echo "DRUPAL_PRINCIPAL=${PILOT_USER}"
echo "DRUPAL_UID=${PILOT_UID}"
echo "DRUPAL_UID1=FORBIDDEN"

# Run the read tools directly through Tool API under the dedicated Drupal
# account. These are the baseline semantics MCP must preserve.
if ! ddev drush tool:run "${ENTITY_TYPES_TOOL}" \
  --uid="${PILOT_UID}" --json >"${TMP_DIR}/entity-types-result.json"; then
  echo "MATERIAL_GAP=TOOL_BELT_ENTITY_TYPE_LIST_ACCESS_OR_RUNTIME"
  exit 10
fi

if ! ddev drush tool:run "${BUNDLE_FIELDS_TOOL}" \
  --input='{"entity_type_id":"node","bundle":"page"}' \
  --uid="${PILOT_UID}" --json >"${TMP_DIR}/bundle-fields-result.json"; then
  echo "MATERIAL_GAP=TOOL_BELT_BUNDLE_FIELDS_ACCESS_OR_RUNTIME"
  exit 11
fi

echo "TOOL_API_DIRECT_READS=PASS"

# Expose exactly the two proven Tool Belt reads through the existing bridge.
# The bridge owns the config entity; no custom MCP plugin is introduced.
ddev drush php:eval "
\$storage = \Drupal::entityTypeManager()->getStorage('mcp_tool_config');
\$definitions = [
  'agency_entity_types' => [
    'tool_id' => '${ENTITY_TYPES_TOOL}',
    'description' => 'List Drupal entity types for bounded Agency schema inspection.',
  ],
  'agency_bundle_fields' => [
    'tool_id' => '${BUNDLE_FIELDS_TOOL}',
    'description' => 'Describe fields for an explicitly selected Drupal entity type and bundle.',
  ],
];
foreach (\$definitions as \$id => \$definition) {
  if (\$existing = \$storage->load(\$id)) {
    \$existing->delete();
  }
  \$storage->create([
    'id' => \$id,
    'tool_id' => \$definition['tool_id'],
    'description' => \$definition['description'],
    'status' => TRUE,
  ])->save();
}
"
ddev drush cr -y

# STDIO discovery is read-only. We deliberately do not grant anonymous broader
# permissions and do not claim an authenticated MCP execution context: upstream
# mcp_server issue #3585912 tracks the missing first-class account binding.
python3 - "${TMP_DIR}/mcp-discovery.json" <<'PY'
import json
import subprocess
import sys
import time

output_path = sys.argv[1]
process = subprocess.Popen(
    ["ddev", "drush", "mcp:server"],
    stdin=subprocess.PIPE,
    stdout=subprocess.PIPE,
    stderr=subprocess.PIPE,
    text=True,
    bufsize=1,
)

def send(message):
    assert process.stdin is not None
    process.stdin.write(json.dumps(message, separators=(",", ":")) + "\n")
    process.stdin.flush()

def receive(expected_id, timeout=20):
    assert process.stdout is not None
    deadline = time.time() + timeout
    while time.time() < deadline:
        line = process.stdout.readline()
        if not line:
            if process.poll() is not None:
                break
            continue
        try:
            payload = json.loads(line)
        except json.JSONDecodeError:
            continue
        if payload.get("id") == expected_id:
            return payload
    raise RuntimeError(f"No MCP response for id={expected_id}")

try:
    send({
        "jsonrpc": "2.0",
        "id": 1,
        "method": "initialize",
        "params": {
            "protocolVersion": "2025-11-25",
            "capabilities": {},
            "clientInfo": {"name": "agency-390-pilot", "version": "1"},
        },
    })
    init = receive(1)
    if "error" in init:
        raise RuntimeError(f"MCP initialize failed: {init['error']}")

    send({"jsonrpc": "2.0", "method": "notifications/initialized"})
    send({"jsonrpc": "2.0", "id": 2, "method": "tools/list", "params": {}})
    tools_result = receive(2)
    if "error" in tools_result:
        raise RuntimeError(f"MCP tools/list failed: {tools_result['error']}")

    tools = tools_result.get("result", {}).get("tools", [])
    by_name = {tool.get("name"): tool for tool in tools}
    required = {
        "tool_api__agency_entity_types",
        "tool_api__agency_bundle_fields",
    }
    missing = sorted(required.difference(by_name))
    if missing:
        raise RuntimeError(f"Expected MCP tools missing: {', '.join(missing)}")

    evidence = {
        "initialize": init,
        "tools": [by_name[name] for name in sorted(required)],
    }
    with open(output_path, "w", encoding="utf-8") as handle:
        json.dump(evidence, handle, indent=2, ensure_ascii=False)

    entity_types_schema = by_name["tool_api__agency_entity_types"].get("inputSchema", {})
    properties = entity_types_schema.get("properties")
    if isinstance(properties, list):
        print("MCP_BRIDGE_SCHEMA_GAP=#3604059")
        print("MCP_ENTITY_TYPES_CALL=NOT_EXECUTED")
        sys.exit(20)

    # Even when discovery/schema succeeds, do not execute a governed tool over
    # STDIO until mcp_server can bind the session to the dedicated Drupal
    # account. A successful anonymous call would not satisfy Agency authority.
    print("MCP_DISCOVERY=PASS")
    print("MCP_STDIO_ACCOUNT_BINDING=NOT_PROVEN")
    print("MCP_STDIO_TOOL_CALL=NOT_EXECUTED")
    print("MATERIAL_GAP=UPSTREAM_MCP_SERVER_IDENTITY_#3585912")
    sys.exit(21)
finally:
    try:
        process.terminate()
        process.wait(timeout=5)
    except Exception:
        process.kill()
PY
