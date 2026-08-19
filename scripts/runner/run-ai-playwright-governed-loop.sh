#!/usr/bin/env bash
set -euo pipefail

: "${TARGET_DIR:?TARGET_DIR is required}"
: "${ARTIFACT_DIR:?ARTIFACT_DIR is required}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TRUSTED_FIXTURE_DIR="$SCRIPT_DIR/fixtures/ai-playwright-516"
test -f "$TRUSTED_FIXTURE_DIR/BoundedCanvasHeading.php"
test -f "$TRUSTED_FIXTURE_DIR/agency516-run.php"

EXPECTED_PACKAGE='drupal/ai_playwright'
EXPECTED_VERSION='1.0.0-alpha1'
BASELINE_UUID='52600000-0000-4000-8000-000000000001'
BASELINE_PATH='/canvas-governed-sdc-baseline'
ORIGINAL_HEADING='Composition Canvas bornée'
TEMP_HEADING='Composition Canvas bornée — vérification agentique'
MARKER='AI Playwright governed SDC loop #516 — DEV-ONLY inert target.'
TEST_MODULE='agency_ai_playwright_516_test'
ARTIFACT_REL='artifacts/ai-playwright-governed-loop'

TARGET_DIR="$(cd "$TARGET_DIR" && pwd)"

source "$TRUSTED_FIXTURE_DIR/part-1.sh"
source "$TRUSTED_FIXTURE_DIR/part-2.sh"
source "$TRUSTED_FIXTURE_DIR/part-3.sh"
