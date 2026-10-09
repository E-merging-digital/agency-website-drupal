import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, rmSync, unlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { runInNewContext } from 'node:vm';

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
  assert.doesNotMatch(workflow, /rm -f[^\n]*\.ddev\/\.env\.web/);
});

test('only whitelisted sanitized JSON is uploaded', () => {
  assert.match(browser, /writeFile\(output, JSON\.stringify\(status/);
  assert.doesNotMatch(browser, /screenshot|storageState|trace\.start|console\.log\(login/);
  assert.match(workflow, /Upload ONLY sanitized JSON/);
  assert.doesNotMatch(workflow, /\.har\b|trace\.zip|\$\{\{\s*secrets\./);
  assert.match(workflow, /git status --porcelain/);
});

test('Node 24 is set up before browser tooling needs node; initial runner prerequisites remain strict', () => {
  const initial = workflow.match(/      - name: Verify established Agency toolchain\n        shell: bash\n        run: \|\n([\s\S]*?)(?=\n      - name: )/);
  assert.ok(initial, 'Expected initial toolchain step');
  assert.match(initial[1], /set -euo pipefail/);
  assert.match(initial[1], /for tool in git gh jq docker ddev openssl; do command -v "\$tool"; done/);
  assert.doesNotMatch(initial[1], /\bnode\b/);

  const checkout = workflow.indexOf('      - name: Checkout immutable #533 HEAD');
  const setup = workflow.indexOf('      - name: Set up locked Node 24');
  const install = workflow.indexOf('      - name: Install browser from existing npm lockfile');
  assert.ok(checkout > 0 && setup > checkout && install > setup);
  assert.match(workflow.slice(setup, install), /uses: actions\/setup-node@v6[\s\S]*node-version: '24'[\s\S]*cache: npm/);

  const installStep = workflow.slice(install, workflow.indexOf('\n      - name: Build disposable Drupal Canvas;', install));
  assert.match(installStep, /set -euo pipefail\n          command -v node\n          node --version\n          node --check/);
  assert.match(installStep, /npm ci --ignore-scripts/);
});


test('disposable rootless DDEV router ports stay unprivileged and provider-free', () => {
  const config = workflow.match(/cat > \.ddev\/config\.gate-canvas-provider-free\.yaml <<EOF\n([\s\S]*?)\n          EOF/);
  assert.ok(config, 'Expected the workflow-owned disposable DDEV configuration');
  const entries = config[1].split('\n').map(line => line.trim()).filter(Boolean);
  assert.equal(entries.length, 3, 'Only the unique project name and two router ports are permitted');
  assert.match(entries[0], /^name: agency-canvas-no-provider-\$\{GITHUB_RUN_ID\}-\$\{GITHUB_RUN_ATTEMPT\}$/);
  const ports = new Map();
  const isSafePort = port => Number.isInteger(port) && port >= 1024 && port <= 65535 && port !== 80 && port !== 443;
  for (const entry of entries.slice(1)) {
    const parsed = entry.match(/^(router_http_port|router_https_port):\s*["']?(\d+)["']?$/);
    assert.ok(parsed, 'Expected a numeric DDEV router port, with optional YAML quotes');
    assert.equal(ports.has(parsed[1]), false, 'Duplicate router port setting');
    const port = Number(parsed[2]);
    assert.ok(isSafePort(port), 'Privileged or invalid DDEV router port');
    ports.set(parsed[1], port);
  }
  assert.deepEqual([...ports.keys()].sort(), ['router_http_port', 'router_https_port']);
  assert.notEqual(ports.get('router_http_port'), ports.get('router_https_port'));
  for (const privileged of [80, 443]) assert.equal(isSafePort(privileged), false);
  assert.doesNotMatch(workflow.replace(config[0], ''), /^\s*router_(?:http|https)_port\s*:/m);
  assert.doesNotMatch(workflow, /^\s*(?:router_bind_all_interfaces|bind_all_interfaces)\s*:\s*true\s*$/m);
  assert.doesNotMatch(workflow, /global_config\.yaml|ip_unprivileged_port_start|CAP_NET_BIND_SERVICE|\bsudo\b/);
  assert.match(workflow, /test -z "\$\{OPENAI_API_KEY:-\}"/);
  for (const preserved of [
    'test ! -e .ddev/.env.web',
    'ddev utility configyaml',
    'ddev start -y',
    'ddev delete --omit-snapshot --yes',
    'rm -f .ddev/config.gate-canvas-provider-free.yaml',
    'test -z "$(git status --porcelain)"',
  ]) assert.ok(workflow.includes(preserved), preserved);
});


test('blocked external requests: bounded first-only metadata, no URL leakage, strict abort and network phase', () => {
  const source = browser.match(/function classifyBlockedExternal\(url, request, base\) \{[\s\S]*?\n\}/);
  assert.ok(source, 'Expected a pure bounded classifier');
  const classify = runInNewContext('(' + source[0] + ')');
  const base = new URL('https://agency.ddev.site:33001/editor');
  const fixtures = [
    ['https://agency.ddev.site:33002/private?token=secret', 'same_hostname_different_port'],
    ['https://other.ddev.site/login?key=secret', 'other_ddev_site'],
    ['https://analytics.example.com/collect?authorization=secret', 'public_external'],
    ['http://localhost:5000/hidden?credential=secret', 'private_or_other'],
  ];
  for (const [target, relation] of fixtures) {
    const metadata = JSON.parse(JSON.stringify(classify(new URL(target), {
      method: () => 'POST', resourceType: () => 'fetch',
    }, base)));
    assert.deepEqual(Object.keys(metadata).sort(),
      ['method', 'origin_relation', 'resource_type', 'scheme_class']);
    assert.equal(metadata.origin_relation, relation);
    assert.equal(metadata.method, 'POST');
    assert.equal(metadata.resource_type, 'fetch');
    assert.ok(['http', 'https', 'other'].includes(metadata.scheme_class));
    const serialized = JSON.stringify(metadata);
    assert.doesNotMatch(serialized, /secret|token|credential|hostname|pathname|https?:\/\/|\.com|\.site/);
  }
  const unknown = JSON.parse(JSON.stringify(classify(
    new URL('file:///private/local-path?secret=1'),
    { method: () => 'TRACE', resourceType: () => 'websocket' }, base,
  )));
  assert.deepEqual(unknown, {
    origin_relation: 'private_or_other', method: 'OTHER',
    resource_type: 'other', scheme_class: 'other',
  });
  const gate = browser.match(/if \(url\.origin !== base\.origin\) \{([\s\S]*?)\n    \}/);
  assert.ok(gate);
  assert.match(gate[1], /if \(status\.external_attempts === 0\)/);
  assert.match(gate[1], /status\.first_blocked_external = classifyBlockedExternal\(url, request, base\)/);
  assert.match(gate[1], /status\.external_attempts\+\+/);
  assert.match(gate[1], /await route\.abort\('blockedbyclient'\)/);
  assert.doesNotMatch(gate[1], /route\.continue/);
  assert.match(browser, /status\.phase = 'network_integrity';[\s\S]*?status\.external_attempts !== 0/);
  assert.match(browser, /status\.phase = 'component_integrity';\n  if \(status\.components_modified\)/);
  assert.match(browser, /status\.canvas_ai_post_attempts !== 0/);
  assert.match(browser, /status\.forbidden_mutation_attempts !== 0/);
  assert.match(browser, /await route\.abort\('blockedbyclient'\)/);
  assert.doesNotMatch(browser, /submitUserMessage|submit\.click\(|submitButton\.click\(|keyboard\.press\(/);
  assert.doesNotMatch(source[0], /console\.log|request\.url\(\)|writeFile|status\.|\.href|\.search|\.pathname/);
});

test('intermediate cleanup permits only three JSON evidence paths and preserves strict final audit', () => {
  const start = workflow.indexOf('      - name: Always clean exact DDEV project and secret-free temp files');
  const end = workflow.indexOf('      - name: Upload ONLY sanitized JSON', start);
  assert.ok(start !== -1 && end > start);
  const cleanup = workflow.slice(start, end);
  const expected = [
    'artifacts/canvas-ai-provider-free-smoke/result.json',
    'artifacts/canvas-ai-provider-free-smoke/integrity.json',
    'artifacts/canvas-ai-provider-free-smoke/cleanup.json',
  ];
  assert.match(cleanup, /status="\$\(git status --porcelain --untracked-files=all -- \. \\\n/);
  const actual = [...cleanup.matchAll(/':\(exclude,top\)([^']+)'/g)].map(m => m[1]);
  assert.deepEqual(actual, expected);
  assert.equal((cleanup.match(/:\(exclude,top\)/g) ?? []).length, 3);
  assert.match(cleanup, /if \[\[ -n "\$status" \]\]; then cleanup_failed=1; fi/);
  assert.match(cleanup, /ddev delete --omit-snapshot --yes \|\| cleanup_failed=1/);
  assert.match(cleanup, /if \[\[ -e \.ddev\/\.env\.web \]\]; then cleanup_failed=1; fi/);
  const final = workflow.slice(workflow.indexOf('      - name: Delete temporary evidence'));
  assert.match(final, /rm -rf artifacts\/canvas-ai-provider-free-smoke/);
  assert.match(final, /test -z "\$\(git status --porcelain\)"/);
  assert.doesNotMatch(final, /:\(exclude|--untracked-files=no/);

  const dir = mkdtempSync(join(tmpdir(), 'agency-r61-git-status-'));
  const git = (...args) => execFileSync('git', args, { cwd: dir, encoding: 'utf8' }).trim();
  const folder = join(dir, 'artifacts/canvas-ai-provider-free-smoke');
  try {
    git('init', '-q');
    writeFileSync(join(dir, 'tracked.txt'), 'clean\n');
    git('add', 'tracked.txt');
    git('-c', 'user.email=ci@example.invalid', '-c', 'user.name=CI',
      'commit', '-qm', 'baseline');
    mkdirSync(folder, { recursive: true });
    for (const path of expected) writeFileSync(join(dir, path), '{}\n');
    const status = () => git('status', '--porcelain', '--untracked-files=all', '--',
      '.', ...actual.map(path => ':(exclude,top)' + path));
    assert.equal(status(), '', 'Expected evidence must not trip intermediate audit');
    writeFileSync(join(folder, 'extra.json'), '{}\n');
    assert.match(status(), /extra\.json/);
    unlinkSync(join(folder, 'extra.json'));
    writeFileSync(join(dir, 'tracked.txt'), 'changed\n');
    assert.match(status(), /tracked\.txt/);
    git('restore', 'tracked.txt');
    assert.notEqual(git('status', '--porcelain'), '',
      'Unrestricted final audit must still detect evidence before its deletion');
    for (const path of expected) unlinkSync(join(dir, path));
    assert.equal(git('status', '--porcelain'), '');
  }
  finally { rmSync(dir, { recursive: true, force: true }); }
});
