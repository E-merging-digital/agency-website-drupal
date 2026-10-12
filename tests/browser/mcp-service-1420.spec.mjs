import { mkdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import { test, expect } from './support/browser-audit.mjs';

const contract = JSON.parse(await readFile(
  new URL('./contracts/mcp-service-1420.json', import.meta.url), 'utf8',
));
const editorial = await readFile(
  new URL('../../docs/seo/serveurs-mcp-integration-agents-ia-candidate-1415.md', import.meta.url),
  'utf8',
);
const copy = {
  fr: {
    title: "Serveurs MCP et intégration d'agents IA pour vos applications métier",
    hero: 'Connectez les agents IA à vos outils métier. Gardez le contrôle.',
    denied: 'Refusé',
    approval: 'Validation humaine',
  },
  en: {
    title: 'MCP Servers and AI Agent Integration for Business Applications',
    hero: 'Connect AI agents to your business tools. Keep control.',
    denied: 'Denied',
    approval: 'Human approval',
  },
};

function extractApprovedBody(langcode) {
  const tick = String.fromCharCode(96);
  const fence = tick.repeat(3);
  const section = editorial.indexOf('## ' + langcode.toUpperCase() + '\n');
  if (section < 0) throw new Error('Missing approved editorial language: ' + langcode);
  const field = editorial.indexOf('### ' + tick + 'field_detailed_description' + tick, section);
  if (field < 0) throw new Error('Missing approved editorial detailed field: ' + langcode);
  const open = editorial.indexOf(fence + 'html\n', field);
  if (open < 0) throw new Error('Missing approved HTML fence: ' + langcode);
  const end = editorial.indexOf('\n' + fence, open + 7);
  if (end < 0) throw new Error('Missing approved HTML closing fence: ' + langcode);
  return editorial.slice(open + 7, end);
}
function normalize(value) {
  return value.normalize('NFC').replace(/\s+/g, ' ').trim();
}

test.describe('Agency MCP first-slice isolated browser proof #1420', () => {
  test('FR and EN, exact UUID, comparator and safe qualified CTA', async ({
    page, audit,
  }, testInfo) => {
    const postRequests = [];
    page.on('request', (request) => {
      if (request.method() === 'POST') postRequests.push(request.url());
    });
    const screenshotDir = path.resolve('artifacts/browser-validation/screenshots');
    await mkdir(screenshotDir, { recursive: true });
    for (const langcode of ['fr', 'en']) {
      const route = contract.routes[langcode];
      const expected = copy[langcode];
      const response = await page.goto(route.mcp, { waitUntil: 'domcontentloaded' });
      expect(response, 'Service route must respond').not.toBeNull();
      expect(response.status()).toBeLessThan(400);
      expect(await page.locator('html').getAttribute('lang')).toBe(langcode);
      await expect(page.locator('h1')).toHaveCount(1);
      await expect(page.getByRole('heading', {
        level: 1, name: expected.title, exact: true,
      })).toBeVisible();
      await expect(page.locator('article.node--service-mcp')).toHaveCount(1);
      await expect(page.locator('article.node--service-mcp h2', {
        hasText: expected.hero,
      })).toBeVisible();

      const flow = page.locator('.node--service-mcp .ed-mcp-flow');
      await expect(flow).toBeVisible();
      await expect(flow.locator('figcaption')).toHaveCount(1);
      await expect(flow.locator('ol li')).toHaveCount(4);
      await expect(flow.locator('ul li')).toHaveCount(3);
      await expect(flow.getByText(expected.denied, { exact: true })).toBeVisible();
      await expect(flow.getByText(expected.approval, { exact: true })).toBeVisible();
      await expect(flow.getByText(langcode === 'fr'
        ? 'Contrôle d’accès réel' : 'Effective access control', { exact: true })).toBeVisible();
      await expect(flow.getByText(langcode === 'fr'
        ? 'Autorisé' : 'Allowed', { exact: true })).toBeVisible();

      const prose = page.locator('.node--service-mcp .ed-detail-page__main.ed-prose');
      await expect(prose).toHaveCount(1);
      const expectedText = await page.evaluate((html) => {
        const element = document.createElement('div');
        element.style.cssText = 'position:absolute;left:-100000px;width:800px';
        element.innerHTML = html;
        document.body.append(element);
        const value = element.innerText;
        element.remove();
        return value;
      }, extractApprovedBody(langcode));
      expect(normalize(await prose.innerText()),
        'The complete approved expert body must render exactly once.').toBe(normalize(expectedText));

      const cta = page.locator('.node--service-mcp a.button--primary').first();
      await expect(cta).toBeVisible();
      const href = await cta.getAttribute('href');
      expect(href).toBeTruthy();
      const target = new URL(href, page.url());
      expect(target.pathname).toBe('/' + langcode + '/contact');
      expect(target.searchParams.get('type')).toBe('ai');
      expect(target.searchParams.get('source')).toBe('service');
      expect(target.searchParams.get('context')).toBe(expected.title);
      expect(target.hash).toBe('#contact-form');
      const order = await cta.evaluate((element) => {
        const detailed = document.querySelector('.node--service-mcp .ed-detail-page__main');
        return Boolean(detailed)
          && Boolean(element.compareDocumentPosition(detailed) & Node.DOCUMENT_POSITION_FOLLOWING);
      });
      expect(order, 'Qualification CTA must precede the long expert text').toBe(true);

      await cta.focus();
      await page.keyboard.press('Shift+Tab');
      await page.keyboard.press('Tab');
      expect(await cta.evaluate((element) => element === document.activeElement),
        'CTA must be reachable by keyboard').toBe(true);
      const focusStyle = await cta.evaluate((element) => {
        const style = getComputedStyle(element);
        return (style.outlineStyle !== 'none' && style.outlineWidth !== '0px')
          || style.boxShadow !== 'none';
      });
      expect(focusStyle, 'Keyboard focus must be visible').toBe(true);
      const width = await page.evaluate(() => ({
        document: document.documentElement.scrollWidth, viewport: window.innerWidth,
      }));
      expect(width.document, 'Horizontal overflow on MCP Service').toBeLessThanOrEqual(width.viewport + 1);

      const screenshotName = 'mcp-service-' + langcode + '-' + testInfo.project.name + '.png';
      const screenshotPath = path.join(screenshotDir, screenshotName);
      await page.screenshot({ path: screenshotPath, fullPage: true });
      await testInfo.attach(screenshotName, { path: screenshotPath, contentType: 'image/png' });
      audit.screenshot = 'screenshots/' + screenshotName;
      const contactResponse = await page.goto(target.href, { waitUntil: 'domcontentloaded' });
      expect(contactResponse).not.toBeNull();
      expect(contactResponse.status()).toBeLessThan(400);
      await expect(page.locator('#contact-form')).toHaveCount(1);

      const comparatorResponse = await page.goto(route.comparator, {
        waitUntil: 'domcontentloaded',
      });
      expect(comparatorResponse).not.toBeNull();
      expect(comparatorResponse.status()).toBeLessThan(400);
      await expect(page.locator('article.node--service')).toHaveCount(1);
      await expect(page.locator('article.node--service-mcp')).toHaveCount(0);
      await expect(page.locator('.ed-mcp-flow')).toHaveCount(0);
      await expect(page.locator('article.node--service .ed-detail-page__hero')).toHaveCount(1);
      await expect(page.locator('article.node--service .ed-detail-page__main')).toHaveCount(1);
    }
    expect(postRequests, 'No form POST or mutation is allowed during browser proof').toEqual([]);
    expect(audit.consoleErrors).toEqual([]);
    expect(audit.pageErrors).toEqual([]);
    expect(audit.http5xx).toEqual([]);
    expect(audit.unexpectedHttp4xx).toEqual([]);
    expect(audit.failedRequests).toEqual([]);
    audit.checks.functional = 'PASS';
    audit.checks.dom = 'PASS';
    audit.checks.visual = 'PASS';
    audit.checks.console = 'PASS';
    audit.checks.network = 'PASS';
  });
});