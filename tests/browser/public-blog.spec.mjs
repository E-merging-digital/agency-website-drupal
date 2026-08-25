import { readFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import { test, expect } from './support/browser-audit.mjs';

const contract = JSON.parse(
  await readFile(new URL('./contracts/public-blog.json', import.meta.url), 'utf8'),
);
const browserBaseUrl = process.env.BROWSER_VALIDATION_BASE_URL ?? 'http://127.0.0.1';
const expectZeroGa4 =
  new URL(browserBaseUrl).hostname === 'preprod.emergingdigital.be'
  || process.env.BROWSER_VALIDATION_EXPECT_ZERO_GA4 === '1';
const ga4MeasurementId = 'G-K5TDNZCPTY';

async function getVisiblePrimaryNavigationLink(page, name) {
  const isMobile = await page.evaluate(() =>
    window.matchMedia('(max-width: 48rem)').matches,
  );

  if (isMobile) {
    const toggle = page.locator('[data-mobile-nav-toggle]');
    const drawer = page.locator('[data-mobile-nav-drawer]');

    await expect(toggle).toBeVisible();
    await expect(toggle).toHaveAttribute('aria-label', 'Ouvrir le menu principal');
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(drawer).toHaveAttribute('role', 'dialog');
    await expect(drawer).toHaveAttribute('aria-label', 'Menu principal');
    await expect(drawer).toHaveAttribute('aria-hidden', 'true');

    await toggle.click();

    await expect(toggle).toHaveAttribute('aria-label', 'Fermer le menu principal');
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(drawer).toHaveAttribute('aria-hidden', 'false');
    await expect(
      page.getByRole('dialog', {
        name: 'Menu principal',
        exact: true,
      }),
    ).toBeVisible();

    const drawerLink = drawer.getByRole('link', {
      name,
      exact: true,
    }).first();
    await expect(drawerLink).toBeVisible();
    return drawerLink;
  }

  const desktopLink = page.getByRole('link', {
    name,
    exact: true,
  }).first();
  await expect(desktopLink).toBeVisible();
  return desktopLink;
}

test.describe('Browser validation capability proof', () => {
  test(`${contract.id}: public Blog is usable and rendered cleanly`, async ({
    page,
    audit,
  }, testInfo) => {
    const response = await page.goto(contract.target, {
      waitUntil: 'domcontentloaded',
    });

    expect(response, 'The initial Blog navigation must return a response.').not.toBeNull();
    expect(
      response.status(),
      `Unexpected HTTP status for ${contract.target}`,
    ).toBeLessThan(400);

    await expect(
      page.getByRole('heading', { name: 'Blog', level: 1 }),
    ).toBeVisible();
    await expect(page.locator('main')).toBeVisible();

    const servicesLink = await getVisiblePrimaryNavigationLink(page, 'Services');
    await servicesLink.click();
    await expect(page).toHaveURL(/\/fr\/services(?:[/?#]|$)/);

    const blogLink = await getVisiblePrimaryNavigationLink(page, 'Blog');
    await blogLink.click();
    await expect(page).toHaveURL(/\/fr\/blog(?:[/?#]|$)/);
    await expect(
      page.getByRole('heading', { name: 'Blog', level: 1 }),
    ).toBeVisible();

    audit.checks.functional = 'PASS';

    const dom = await page.evaluate(() => ({
      h1Count: document.querySelectorAll('h1').length,
      lang: document.documentElement.lang,
      scrollWidth: document.documentElement.scrollWidth,
      viewportWidth: window.innerWidth,
      hasHorizontalOverflow:
        document.documentElement.scrollWidth > window.innerWidth + 1,
      html: document.documentElement.outerHTML,
    }));

    expect(dom.h1Count, 'The page must expose exactly one H1.').toBe(1);
    expect(dom.lang, 'The FR Blog must expose a French document language.').toBe('fr');
    expect(
      dom.hasHorizontalOverflow,
      `Horizontal overflow detected (${dom.scrollWidth}px > ${dom.viewportWidth}px).`,
    ).toBeFalsy();

    audit.checks.dom = 'PASS';

    if (expectZeroGa4) {
      expect(
        audit.analyticsRequests,
        'PREPROD must not emit Google Tag / Google Analytics / collect requests.',
      ).toEqual([]);
      expect(
        audit.analyticsMeasurementRequests,
        `PREPROD must not emit requests containing ${ga4MeasurementId}.`,
      ).toEqual([]);
      expect(
        dom.html,
        `PREPROD must not inject ${ga4MeasurementId} into the rendered page.`,
      ).not.toContain(ga4MeasurementId);
      audit.checks.analytics = 'PASS';
    }

    const screenshotDirectory = path.resolve(
      'artifacts/browser-validation/screenshots',
    );
    await mkdir(screenshotDirectory, { recursive: true });
    const screenshotFile = `public-blog-${testInfo.project.name}.png`;
    const screenshotPath = path.join(screenshotDirectory, screenshotFile);

    await page.screenshot({
      path: screenshotPath,
      fullPage: true,
    });
    await testInfo.attach(`public-blog-${testInfo.project.name}`, {
      path: screenshotPath,
      contentType: 'image/png',
    });
    audit.screenshot = `screenshots/${screenshotFile}`;

    audit.checks.visual = 'PASS';

    audit.checks.console =
      audit.consoleErrors.length === 0 && audit.pageErrors.length === 0
        ? 'PASS'
        : 'FAIL';
    audit.checks.network =
      audit.http5xx.length === 0 &&
      audit.unexpectedHttp4xx.length === 0 &&
      audit.failedRequests.length === 0
        ? 'PASS'
        : 'FAIL';

    expect(
      audit.consoleErrors,
      'No new browser console error is allowed in this capability proof.',
    ).toEqual([]);
    expect(
      audit.pageErrors,
      'No uncaught browser page error is allowed in this capability proof.',
    ).toEqual([]);
    expect(
      audit.http5xx,
      'No same-origin HTTP 5xx response is allowed.',
    ).toEqual([]);
    expect(
      audit.unexpectedHttp4xx,
      'No same-origin HTTP 4xx response is expected in this public flow.',
    ).toEqual([]);
    expect(
      audit.failedRequests,
      'No same-origin browser request should fail.',
    ).toEqual([]);
  });
});
