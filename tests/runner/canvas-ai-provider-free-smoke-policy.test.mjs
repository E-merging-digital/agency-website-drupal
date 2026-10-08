import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const workflow = readFileSync('.github/workflows/canvas-ai-provider-free-smoke.yml', 'utf8');
const browser = readFileSync('scripts/runner/canvas-ai-provider-free-smoke.mjs', 'utf8');

test('manual owner gate: trusted main, no automatic events or retries', () => {
  assert.match(workflow, /^  workflow_dispatch:/m);
  assert.doesNotMatch(workflow, /^\s+(pull_request|push|issue_comment|workflow_call):/m);
  for (const required of [
    'refs/heads/main',
    'test "$GITHUB_ACTOR" = E-merging-digital',
    'test "$GITHUB_TRIGGERING_ACTOR" = E-merging-digital',
    'test "$SENDER_TYPE" = User',
    'test "$GITHUB_RUN_ATTEMPT" = 1',
    'test "$GITHUB_SHA" = "$live_main"',
  ]) assert.ok(workflow.includes(required), required);
});

test('strict target and immutable trusted script gates', () => {
  for (const required of [
    'repos/$GITHUB_REPOSITORY/issues/530',
    'repos/$GITHUB_REPOSITORY/pulls/533',
    'feature/issue-530-bounded-canvas-ai-composition',
    'test "${#actual[@]}" -eq "${#expected[@]}"',
    'ref: ${{ needs.validate-target.outputs.head_sha }}',
    'test "$(git rev-parse HEAD)" = "$EXPECTED_HEAD_SHA"',
    'git hash-object "$dest"',
    'ref=$GITHUB_SHA',
  ]) assert.ok(workflow.includes(required), required);
  assert.doesNotMatch(workflow, /\$\{\{\s*secrets\./);
  assert.doesNotMatch(workflow, /trusted-canvas-ai-provider-proof\.yml/);
});

test('no send action, no provider and network fail-closed', () => {
  assert.match(browser, /await opener\.click\(\)/);
  assert.equal((browser.match(/await opener\.click\(\)/g) ?? []).length, 1);
  assert.match(browser, /await field\.fill\(prompt\)/);
  assert.match(browser, /await expect\(submit\)\.toBeEnabled\(\)/);
  assert.doesNotMatch(browser, /submitUserMessage|submit\.click\(|submitButton\.click\(/);
  assert.doesNotMatch(browser, /keyboard\.press\(|\.press\(['"]Enter/);
  assert.match(browser, /canvas_ai_post_attempts/);
  assert.match(browser, /await route\.abort\('blockedbyclient'\)/);
  assert.match(browser, /external_attempts/);
  assert.match(workflow, /test ! -e \.ddev\/\.env\.web/);
  assert.match(workflow, /ddev delete --omit-snapshot --yes/);
});

test('only whitelisted sanitized JSON is uploaded', () => {
  assert.match(browser, /writeFile\(output, JSON\.stringify\(status/);
  assert.doesNotMatch(browser, /screenshot|storageState|trace\.start|console\.log\(login/);
  assert.match(workflow, /Upload ONLY sanitized JSON/);
  assert.doesNotMatch(workflow, /\.har\b|trace\.zip|\$\{\{\s*secrets\./);
  assert.match(workflow, /git status --porcelain/);
});
