import { chromium, expect } from '@playwright/test';
import { readFile, mkdir, writeFile } from 'node:fs/promises';
import { dirname } from 'node:path';

const prompt = 'Using the page builder tool, place the existing approved Hero, Trust list and CTA components at the bottom of the page in that order. Do not create any new component.';
const approved = ['emerging_digital:hero', 'emerging_digital:trust-list', 'emerging_digital:cta'];
function classifyBlockedExternal(url, request, base) {
  const scheme_class = url.protocol === 'https:' ? 'https'
    : url.protocol === 'http:' ? 'http' : 'other';
  const rawMethod = request.method();
  const method = ['GET', 'HEAD', 'OPTIONS', 'POST'].includes(rawMethod) ? rawMethod : 'OTHER';
  const rawType = request.resourceType();
  const resource_type = ['document', 'script', 'stylesheet', 'image', 'font', 'fetch', 'xhr']
    .includes(rawType) ? rawType : 'other';
  const hostname = url.hostname.toLowerCase();
  const otherDdevSite = hostname.endsWith('.ddev.site');
  const privateOrAmbiguous = hostname === 'localhost'
    || ['.localhost', '.local', '.internal', '.test', '.invalid', '.example', '.home.arpa']
      .some((suffix) => hostname.endsWith(suffix))
    || hostname.includes(':') || !hostname.includes('.')
    || !/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,}$/i.test(hostname);
  const resolvedPort = (u) => u.port || (u.protocol === 'https:' ? '443'
    : u.protocol === 'http:' ? '80' : '');
  const origin_relation = hostname === base.hostname && resolvedPort(url) !== resolvedPort(base)
    ? 'same_hostname_different_port'
    : otherDdevSite ? 'other_ddev_site'
      : !privateOrAmbiguous && scheme_class !== 'other' ? 'public_external'
        : 'private_or_other';
  return { origin_relation, method, resource_type, scheme_class };
}

const output = process.env.AGENCY_SMOKE_RESULT_PATH;
const status = {
  status: 'FAIL', phase: 'config', editor_http: null,
  initial_component_ids: [], final_component_ids: [],
  panel_open_click_count: 0, scoped_chat_count: 0,
  input_contenteditable: false, input_writable: false, prompt_filled: false,
  native_submit_enabled: false, native_send_click_count: 0,
  prompt_submitted: false, canvas_ai_post_attempts: 0,
  canvas_ai_endpoint_attempts: 0, external_attempts: 0,
  first_blocked_external: null,
  forbidden_mutation_attempts: 0, components_modified: null,
  error_code: null,
};
let browser;
let failed = false;
async function componentIds(page) {
  for (const frame of page.frames()) {
    const ids = await frame.locator('[data-ed-component]').evaluateAll(
      (nodes) => nodes.map((node) => node.getAttribute('data-ed-component')),
    ).catch(() => []);
    if (ids.length >= 3) return ids;
  }
  return [];
}

try {
  const baseText = process.env.AGENCY_SMOKE_BASE_URL;
  const loginFile = process.env.AGENCY_SMOKE_LOGIN_FILE;
  const entityId = process.env.AGENCY_SMOKE_CANVAS_ENTITY_ID;
  if (!output || !baseText || !loginFile || !/^[0-9]+$/.test(entityId ?? '')) {
    throw new Error('MISSING_BOUNDED_RUNTIME');
  }
  if (process.env.OPENAI_API_KEY || process.env.CANVAS_AI_PROVIDER_PROOF === '1') {
    throw new Error('PROVIDER_NOT_ALLOWED');
  }
  const base = new URL(baseText);
  const login = new URL((await readFile(loginFile, 'utf8')).trim());
  if (base.protocol !== 'https:' || login.origin !== base.origin) {
    throw new Error('UNTRUSTED_ORIGIN');
  }
  status.phase = 'browser';
  browser = await chromium.launch();
  const context = await browser.newContext({
    ignoreHTTPSErrors: true, serviceWorkers: 'block',
  });
  await context.route('**/*', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname === '/admin/api/canvas/ai') {
      status.canvas_ai_endpoint_attempts++;
      if (request.method() === 'POST') status.canvas_ai_post_attempts++;
      await route.abort('blockedbyclient');
      return;
    }
    if (url.origin !== base.origin) {
      if (status.external_attempts === 0) {
        status.first_blocked_external = classifyBlockedExternal(url, request, base);
      }
      status.external_attempts++;
      await route.abort('blockedbyclient');
      return;
    }
    if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method())
      && url.pathname.startsWith('/admin/api/canvas/')) {
      status.forbidden_mutation_attempts++;
      await route.abort('blockedbyclient');
      return;
    }
    await route.continue();
  });
  const page = await context.newPage();
  status.phase = 'authentication';
  const loginResponse = await page.goto(login.href, { waitUntil: 'domcontentloaded' });
  if (!loginResponse || loginResponse.status() >= 400) {
    throw new Error('LOGIN_FAILED');
  }
  status.phase = 'editor';
  const response = await page.goto(
    new URL('/canvas/editor/canvas_page/' + entityId, base).href,
    { waitUntil: 'domcontentloaded' },
  );
  status.editor_http = response?.status() ?? null;
  if (status.editor_http !== 200) throw new Error('EDITOR_NOT_200');
  await expect.poll(() => componentIds(page), { timeout: 30000 }).toEqual(approved);
  status.initial_component_ids = await componentIds(page);

  status.phase = 'panel';
  const chat = page.locator('[data-testid="canvas-ai-panel"] deep-chat');
  const initial = await chat.count();
  if (initial > 1) throw new Error('AMBIGUOUS_CHAT');
  if (initial !== 1 || !(await chat.isVisible())) {
    const opener = page.getByRole('button', { name: 'Open AI Panel', exact: true });
    await expect(opener).toHaveCount(1);
    await expect(opener).toBeVisible();
    await expect(opener).toBeEnabled();
    await opener.click();
    status.panel_open_click_count++;
  }
  await expect(chat).toHaveCount(1, { timeout: 10000 });
  await expect(chat).toBeVisible({ timeout: 10000 });
  status.scoped_chat_count = 1;

  status.phase = 'input';
  const field = chat.locator('#text-input');
  await expect(field).toHaveCount(1);
  await expect(field).toHaveAttribute('contenteditable', 'true');
  status.input_contenteditable = true;
  await expect(field).toBeVisible();
  await expect(field).toBeEditable();
  status.input_writable = true;
  await field.fill(prompt);
  await expect(field).toHaveText(prompt);
  status.prompt_filled = true;
  const submit = chat.locator('.input-button.submit-button');
  await expect(submit).toHaveCount(1);
  await expect(submit).toBeVisible();
  await expect(submit).toBeEnabled();
  await expect(submit).not.toHaveAttribute('aria-disabled', 'true');
  await expect(chat.locator('.input-button.disabled-button')).toHaveCount(0);
  status.native_submit_enabled = true;

  // STOP at readiness: no send click, no Enter, no provider dispatch.
  await page.waitForTimeout(750);
  status.final_component_ids = await componentIds(page);
  status.components_modified =
    JSON.stringify(status.initial_component_ids) !== JSON.stringify(status.final_component_ids);
  status.phase = 'network_integrity';
  if (status.canvas_ai_endpoint_attempts !== 0 || status.canvas_ai_post_attempts !== 0
    || status.external_attempts !== 0 || status.forbidden_mutation_attempts !== 0) {
    throw new Error('NETWORK_INTEGRITY_VIOLATION');
  }
  status.phase = 'component_integrity';
  if (status.components_modified) throw new Error('UNEXPECTED_COMPONENT_CHANGE');
  status.phase = 'none';
  status.status = 'PASS';
}
catch {
  failed = true;
  status.error_code = 'SMOKE_FAILED_AT_' + status.phase.toUpperCase();
}
finally {
  if (browser) {
    try { await browser.close(); }
    catch { failed = true; status.status = 'FAIL'; status.error_code = 'BROWSER_CLOSE_FAILED'; }
  }
  if (output) {
    await mkdir(dirname(output), { recursive: true });
    await writeFile(output, JSON.stringify(status, null, 2) + '\n', {
      encoding: 'utf8', mode: 0o600,
    });
  }
}
if (failed || status.status !== 'PASS') {
  process.stderr.write('Provider-free Canvas UI smoke failed closed; see sanitized JSON.\n');
  process.exitCode = 1;
}
