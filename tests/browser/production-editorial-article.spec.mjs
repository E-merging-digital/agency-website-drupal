import assert from 'node:assert/strict';
import { mkdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import { test, expect } from './support/browser-audit.mjs';

const contractPath = process.env.BROWSER_VALIDATION_CONTRACT;
if (!contractPath) {
  throw new Error('BROWSER_VALIDATION_CONTRACT is required.');
}

const contract = JSON.parse(
  await readFile(path.resolve(contractPath), 'utf8'),
);

const requiredTopLevelKeys = [
  'actor',
  'category',
  'hreflang',
  'id',
  'internal_links',
  'issue_number',
  'locales',
  'origin',
  'sitemap',
  'target',
];

assert.deepEqual(Object.keys(contract).sort(), requiredTopLevelKeys.sort());
assert.equal(contract.id, 'production-editorial-401');
assert.equal(contract.issue_number, 401);
assert.equal(contract.actor, 'anonymous');
assert.equal(contract.origin, 'https://emergingdigital.be');
assert.deepEqual(Object.keys(contract.locales).sort(), ['en', 'fr']);
assert.deepEqual(Object.keys(contract.hreflang).sort(), ['en', 'fr', 'x-default']);

function absoluteUrl(value, baseURL) {
  return new URL(value, `${baseURL}/`).toString();
}

async function verifySitemapContains(request, baseURL, sitemapPath, expectedUrl) {
  const sitemapUrl = absoluteUrl(sitemapPath, baseURL);
  const response = await request.get(sitemapUrl);
  expect(response.status(), `Unexpected sitemap status for ${sitemapUrl}`).toBeLessThan(400);

  const xml = await response.text();
  if (xml.includes(`<loc>${expectedUrl}</loc>`)) {
    return;
  }

  const childUrls = [...xml.matchAll(/<loc>(https?:\/\/[^<]+)<\/loc>/g)]
    .map((match) => match[1])
    .filter((url) => {
      try {
        return new URL(url).origin === new URL(baseURL).origin;
      }
      catch {
        return false;
      }
    })
    .slice(0, 20);

  for (const childUrl of childUrls) {
    const childResponse = await request.get(childUrl);
    if (childResponse.status() >= 400) {
      continue;
    }
    if ((await childResponse.text()).includes(`<loc>${expectedUrl}</loc>`)) {
      return;
    }
  }

  throw new Error(`${expectedUrl} is absent from ${sitemapUrl} and its bounded child sitemaps.`);
}

async function verifyLocale(page, request, baseURL, locale, expected) {
  const response = await page.goto(expected.path, {
    waitUntil: 'domcontentloaded',
  });
  expect(response, `Navigation must return a response for ${locale}.`).not.toBeNull();
  expect(response.status(), `Unexpected HTTP status for ${expected.path}`).toBeLessThan(400);

  await expect(page.getByRole('heading', {
    name: expected.title,
    level: 1,
    exact: true,
  })).toBeVisible();
  await expect(page.locator('main')).toBeVisible();
  await expect(page.locator('html')).toHaveAttribute('lang', expected.lang);
  await expect(page.getByText(contract.category, { exact: true }).first()).toBeVisible();

  const image = page.locator(`img[alt="${expected.image_alt}"]`).first();
  await expect(image).toBeVisible();
  const imageState = await image.evaluate((element) => ({
    complete: element.complete,
    naturalHeight: element.naturalHeight,
    naturalWidth: element.naturalWidth,
    src: element.currentSrc || element.src,
  }));
  expect(imageState.complete).toBeTruthy();
  expect(imageState.naturalWidth).toBeGreaterThan(0);
  expect(imageState.naturalHeight).toBeGreaterThan(0);
  expect(new URL(imageState.src).origin).toBe(new URL(baseURL).origin);

  const canonical = await page.locator('link[rel="canonical"]').getAttribute('href');
  expect(canonical, `Canonical is required for ${locale}.`).not.toBeNull();
  expect(absoluteUrl(canonical, baseURL)).toBe(expected.canonical);

  const alternates = await page.locator('link[rel="alternate"][hreflang]').evaluateAll(
    (links) => Object.fromEntries(
      links.map((link) => [
        link.getAttribute('hreflang'),
        link.getAttribute('href'),
      ]),
    ),
  );
  for (const [hreflang, expectedUrl] of Object.entries(contract.hreflang)) {
    expect(alternates[hreflang], `Missing hreflang=${hreflang} on ${locale}.`).toBeTruthy();
    expect(absoluteUrl(alternates[hreflang], baseURL)).toBe(expectedUrl);
  }

  const dom = await page.evaluate(() => ({
    h1Count: document.querySelectorAll('h1').length,
    hasHorizontalOverflow:
      document.documentElement.scrollWidth > window.innerWidth + 1,
    scrollWidth: document.documentElement.scrollWidth,
    viewportWidth: window.innerWidth,
  }));
  expect(dom.h1Count, `${locale} must expose exactly one H1.`).toBe(1);
  expect(
    dom.hasHorizontalOverflow,
    `${locale} horizontal overflow detected (${dom.scrollWidth}px > ${dom.viewportWidth}px).`,
  ).toBeFalsy();

  for (const href of contract.internal_links) {
    await expect(page.locator(`main a[href="${href}"]`).first()).toBeVisible();
    const linkResponse = await request.get(absoluteUrl(href, baseURL));
    expect(linkResponse.status(), `Broken internal link ${href} from ${locale}.`).toBeLessThan(400);
  }

  await verifySitemapContains(request, baseURL, contract.sitemap, expected.canonical);
}

test.describe('Governed production editorial browser proof', () => {
  test(`${contract.id}: #401 is valid in production`, async ({
    page,
    request,
    baseURL,
    audit,
  }, testInfo) => {
    expect(baseURL).toBe(contract.origin);

    await verifyLocale(page, request, baseURL, 'fr', contract.locales.fr);

    const screenshotDirectory = path.resolve(
      'artifacts/browser-validation/screenshots',
    );
    await mkdir(screenshotDirectory, { recursive: true });

    const frScreenshot = path.join(
      screenshotDirectory,
      `production-editorial-401-${testInfo.project.name}-fr.png`,
    );
    await page.screenshot({ path: frScreenshot, fullPage: true });
    await testInfo.attach(`production-editorial-401-${testInfo.project.name}-fr`, {
      path: frScreenshot,
      contentType: 'image/png',
    });

    await verifyLocale(page, request, baseURL, 'en', contract.locales.en);

    const enScreenshot = path.join(
      screenshotDirectory,
      `production-editorial-401-${testInfo.project.name}-en.png`,
    );
    await page.screenshot({ path: enScreenshot, fullPage: true });
    await testInfo.attach(`production-editorial-401-${testInfo.project.name}-en`, {
      path: enScreenshot,
      contentType: 'image/png',
    });

    audit.checks.functional = 'PASS';
    audit.checks.dom = 'PASS';
    audit.screenshot = `screenshots/production-editorial-401-${testInfo.project.name}-fr.png`;
    audit.checks.visual = 'PASS';
    audit.checks.console =
      audit.consoleErrors.length === 0 && audit.pageErrors.length === 0
        ? 'PASS'
        : 'FAIL';
    audit.checks.network =
      audit.http5xx.length === 0
      && audit.unexpectedHttp4xx.length === 0
      && audit.failedRequests.length === 0
        ? 'PASS'
        : 'FAIL';

    expect(audit.consoleErrors, 'No browser console error is allowed.').toEqual([]);
    expect(audit.pageErrors, 'No uncaught browser page error is allowed.').toEqual([]);
    expect(audit.http5xx, 'No same-origin HTTP 5xx is allowed.').toEqual([]);
    expect(audit.unexpectedHttp4xx, 'No same-origin HTTP 4xx is allowed.').toEqual([]);
    expect(audit.failedRequests, 'No same-origin request may fail.').toEqual([]);
  });
});
