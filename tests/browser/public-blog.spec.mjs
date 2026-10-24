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
const consentStorageKey = 'ed_cookie_consent_v1';

async function getVisiblePrimaryNavigationLink(page, name) {
  const isMobile = await page.evaluate(() =>
    window.matchMedia('(max-width: 48rem)').matches,
  );

  if (isMobile) {
    const header = page.locator('.page-header');
    const toggle = page.locator('[data-mobile-nav-toggle]');
    const drawer = page.locator('[data-mobile-nav-drawer]');

    await expect(header).toHaveAttribute('data-mobile-nav-ready', 'true');
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

async function acceptExternalConsent(page, audit) {
  const banner = page.locator('#ed-cookie-banner');
  const acceptButton = page.locator('[data-ed-cookie-action="accept"]');

  await expect(banner).toBeVisible();
  await expect(acceptButton).toBeVisible();
  await acceptButton.click();
  await expect(banner).toHaveCount(0);

  const consent = await page.evaluate((storageKey) => {
    const raw = window.localStorage.getItem(storageKey);
    return raw ? JSON.parse(raw) : null;
  }, consentStorageKey);

  expect(consent?.necessary).toBe(true);
  expect(consent?.external).toBe(true);
  audit.checks.consent = 'PASS';
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

    if (expectZeroGa4) {
      await acceptExternalConsent(page, audit);
    }

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
      expect(audit.checks.consent).toBe('PASS');
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

/*
 * Agency #1418 R8: homepage proof on the existing trusted public-blog
 * browser lane. Its trusted main workflow executes this exact spec.
 * No change to .github/, Playwright config, runner or control surface.
 */
test.describe('Agency #1418 B+ Kinetic homepage FR/EN', () => {
  test('real home hero is readable and opt-in Kinetic is perceptible', async ({
    page, browser, audit,
  }, testInfo) => {
    test.setTimeout(110_000);
    const surfaces = [
      {
        lang: 'fr', path: '/fr', primary: 'Parler de mon projet',
        secondary: 'Voir les expertises', firstWord: 'CRÉER',
        play: 'Voir la présentation', pause: 'Pause', resume: 'Reprendre',
        stop: 'Arrêter', replay: 'Revoir', reduced: 'Animation réduite',
      },
      {
        lang: 'en', path: '/en', primary: 'Discuss my project',
        secondary: 'Explore our expertise', firstWord: 'CREATE',
        play: 'Watch the presentation', pause: 'Pause', resume: 'Resume',
        stop: 'Stop', replay: 'Replay', reduced: 'Motion is reduced',
      },
    ];

    for (const surface of surfaces) {
      await page.emulateMedia({ reducedMotion: 'no-preference' });
      const response = await page.goto(surface.path, { waitUntil: 'domcontentloaded' });
      expect(response).not.toBeNull();
      expect(response.status()).toBeLessThan(400);
      const hero = page.locator('.page-main--front .ed-section--hero-home');
      await expect(hero).toBeVisible();
      const h1 = hero.locator('h1');
      await expect(h1).toHaveCount(1);
      await expect(h1).toBeVisible();
      await expect(page.locator('h1')).toHaveCount(1);
      await expect(page.locator('html')).toHaveAttribute('lang', surface.lang);
      const primary = page.getByRole('link', { name: surface.primary, exact: true }).first();
      const secondary = page.getByRole('link', { name: surface.secondary, exact: true }).first();
      await expect(primary).toBeVisible();
      await expect(secondary).toBeVisible();
      expect(new URL(await primary.getAttribute('href'), page.url()).pathname)
        .toBe(`/${surface.lang}/contact`);
      expect(new URL(await secondary.getAttribute('href'), page.url()).pathname)
        .toBe(`/${surface.lang}/services`);
      await expect(page.getByText(
        surface.lang === 'fr'
          ? /secteur public|organisations exigeantes/i
          : /public sector|demanding organisations/i,
      ).first()).toBeVisible();

      const visual = hero.locator('.ed-home-kinetic__visual');
      const media = visual.locator('img').first();
      await expect(media).toBeVisible();
      expect(await media.evaluate(el => el.complete && el.naturalWidth > 0)).toBe(true);
      await expect(visual).toHaveAttribute('data-kinetic-static', 'true');
      const play = hero.getByRole('button', { name: surface.play });
      await expect(play).toBeVisible();
      await expect(hero.getByRole('button', { name: surface.replay })).toBeHidden();
      await expect(hero.getByRole('button', { name: surface.stop })).toBeHidden();
      const progress = hero.locator('.ed-home-kinetic__progress');
      expect(await progress.evaluate(el => el.value)).toBe(0);
      const metrics = await page.evaluate(() => ({
        width: window.innerWidth,
        documentWidth: document.documentElement.scrollWidth,
      }));
      expect(metrics.documentWidth).toBeLessThanOrEqual(metrics.width + 1);

      const dir = path.resolve('artifacts/browser-validation/screenshots');
      await mkdir(dir, { recursive: true });
      const staticShot = path.join(dir, `home-1418-${surface.lang}-${testInfo.project.name}-static.png`);
      await page.screenshot({ path: staticShot, fullPage: true });
      await testInfo.attach(`home-${surface.lang}-static`, { path: staticShot, contentType: 'image/png' });

      // Keyboard starts the same voluntary demo; no autoplay.
      await play.focus();
      await page.keyboard.press('Enter');
      await expect(visual).toHaveAttribute('data-kinetic-static', 'false');
      await expect.poll(() => progress.evaluate(el => el.value), { timeout: 5_000 })
        .toBeGreaterThan(200);
      const word = visual.getByText(surface.firstWord, { exact: true });
      await expect.poll(() => word.evaluate(el => Number(getComputedStyle(el).opacity)),
        { timeout: 6_000 }).toBeGreaterThan(0.35);
      const playingShot = path.join(dir, `home-1418-${surface.lang}-${testInfo.project.name}-kinetic.png`);
      await page.screenshot({ path: playingShot, fullPage: true });
      await testInfo.attach(`home-${surface.lang}-kinetic`, {
        path: playingShot, contentType: 'image/png',
      });
      await hero.getByRole('button', { name: surface.pause }).click();
      const pausedAt = await progress.evaluate(el => el.value);
      await page.waitForTimeout(260);
      expect(await progress.evaluate(el => el.value)).toBe(pausedAt);
      await hero.getByRole('button', { name: surface.resume }).click();
      await expect.poll(() => progress.evaluate(el => el.value))
        .toBeGreaterThan(pausedAt);
      await hero.getByRole('button', { name: surface.stop }).click();
      expect(await progress.evaluate(el => el.value)).toBe(0);
      await expect(visual).toHaveAttribute('data-kinetic-static', 'true');
      await expect(hero.getByRole('button', { name: surface.play })).toBeVisible();

      // Finish really animating; timer must not jump instantly to 12s.
      await hero.getByRole('button', { name: surface.play }).click();
      await expect.poll(() => progress.evaluate(el => el.value), { timeout: 18_000 })
        .toBe(12_000);
      await expect(hero.getByRole('button', { name: surface.replay })).toBeVisible();
      await hero.getByRole('button', { name: surface.replay }).click();
      await expect.poll(() => progress.evaluate(el => el.value)).toBeGreaterThan(0);
      await hero.getByRole('button', { name: surface.stop }).click();

      // A reduced-motion user can still read the static hero.
      await page.emulateMedia({ reducedMotion: 'reduce' });
      await hero.getByRole('button', { name: surface.play }).click();
      expect(await progress.evaluate(el => el.value)).toBe(0);
      await expect(visual).toHaveAttribute('data-kinetic-static', 'true');
      await expect(hero.getByText(new RegExp(surface.reduced))).toBeVisible();
      await page.emulateMedia({ reducedMotion: 'no-preference' });
    }

    // A fresh real browser context with JavaScript disabled must retain
    // meaningful H1 and contact CTA without using the Kinetic behavior.
    const staticContext = await browser.newContext({ javaScriptEnabled: false });
    try {
      const staticPage = await staticContext.newPage();
      const response = await staticPage.goto('/fr', { waitUntil: 'domcontentloaded' });
      expect(response).not.toBeNull();
      expect(response.status()).toBeLessThan(400);
      await expect(staticPage.locator('h1')).toHaveCount(1);
      await expect(staticPage.locator('h1')).toBeVisible();
      await expect(staticPage.getByRole('link', { name: 'Parler de mon projet' }).first())
        .toBeVisible();
    }
    finally {
      await staticContext.close();
    }

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
    audit.screenshot = `screenshots/home-1418-en-${testInfo.project.name}-kinetic.png`;
  });
});
