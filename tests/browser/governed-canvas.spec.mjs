import { expect, test } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';

const target = '/canvas-governed-sdc-baseline';

test.describe('governed Canvas baseline', () => {
  test('renders only the approved SDC composition', async ({ page }, testInfo) => {
    const browserErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        browserErrors.push(`console: ${message.text()}`);
      }
    });
    page.on('pageerror', (error) => {
      browserErrors.push(`page: ${error.message}`);
    });

    const response = await page.goto(target, { waitUntil: 'domcontentloaded' });
    expect(response?.status()).toBe(200);

    const hero = page.locator('[data-ed-component="emerging_digital:hero"]');
    const trustList = page.locator('[data-ed-component="emerging_digital:trust-list"]');
    const cta = page.locator('[data-ed-component="emerging_digital:cta"]');

    await expect(hero).toHaveCount(1);
    await expect(trustList).toHaveCount(1);
    await expect(cta).toHaveCount(1);
    await expect(page.locator('[data-ed-component]')).toHaveCount(3);

    await expect(
      hero.getByRole('heading', {
        level: 1,
        name: 'Canvas gouverné par le design system Agency',
      }),
    ).toBeVisible();
    await expect(
      trustList.getByRole('heading', { name: 'Contrôles de composition' }),
    ).toBeVisible();
    await expect(trustList.getByRole('listitem')).toHaveCount(3);
    await expect(
      cta.getByRole('heading', { name: 'Composition Canvas bornée' }),
    ).toBeVisible();

    const horizontalOverflow = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    );
    expect(horizontalOverflow).toBe(false);
    expect(browserErrors).toEqual([]);

    const screenshotDir = path.resolve('artifacts/browser-validation/screenshots');
    await mkdir(screenshotDir, { recursive: true });
    await page.screenshot({
      path: path.join(
        screenshotDir,
        `governed-canvas-${testInfo.project.name}.png`,
      ),
      fullPage: true,
    });
  });
});
