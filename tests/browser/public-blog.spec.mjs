import { readFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import { test, expect } from './support/browser-audit.mjs';

const contract = JSON.parse(
  await readFile(new URL('./contracts/public-blog.json', import.meta.url), 'utf8'),
);

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

    const servicesLink = page.getByRole('link', {
      name: 'Services',
      exact: true,
    }).first();
    await expect(servicesLink).toBeVisible();
    await servicesLink.click();
    await expect(page).toHaveURL(/\/fr\/services(?:[/?#]|$)/);

    const blogLink = page.getByRole('link', {
      name: 'Blog',
      exact: true,
    }).first();
    await expect(blogLink).toBeVisible();
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
    }));

    expect(dom.h1Count, 'The page must expose exactly one H1.').toBe(1);
    expect(dom.lang, 'The FR Blog must expose a French document language.').toBe('fr');
    expect(
      dom.hasHorizontalOverflow,
      `Horizontal overflow detected (${dom.scrollWidth}px > ${dom.viewportWidth}px).`,
    ).toBeFalsy();

    audit.checks.dom = 'PASS';

    const screenshotDirectory = path.resolve(
      'artifacts/browser-validation/screenshots',
    );
    await mkdir(screenshotDirectory, { recursive: true });
    const screenshotPath = path.join(
      screenshotDirectory,
      `public-blog-${testInfo.project.name}.png`,
    );

    await page.screenshot({
      path: screenshotPath,
      fullPage: true,
    });
    await testInfo.attach(`public-blog-${testInfo.project.name}`, {
      path: screenshotPath,
      contentType: 'image/png',
    });

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
