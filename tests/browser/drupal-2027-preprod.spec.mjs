import { readFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import { test, expect } from './support/browser-audit.mjs';

const contract = JSON.parse(
  await readFile(
    new URL('./contracts/drupal-2027-preprod.json', import.meta.url),
    'utf8',
  ),
);

const h1 = 'Votre plateforme Drupal est-elle prête pour 2027 ?';
const submessage = 'Une plateforme Drupal peut fonctionner correctement aujourd’hui tout en nécessitant de préparer sa prochaine évolution. L’objectif est d’identifier ce qui mérite réellement d’être vérifié — version, dépendances, développements spécifiques, environnement et tests — avant de décider d’une mise à niveau, d’une migration ou d’une modernisation.';
const primaryCta = 'Faire le point sur ma plateforme';
const secondaryCta = 'Voir ce qu’il faut vérifier';
const secondaryCtaTarget = '#points-a-verifier-socle';

const orderedHeadings = [
  'Pourquoi 2027 ? Les jalons à connaître',
  'Dans quelles situations êtes-vous concerné ?',
  'Les points à vérifier',
  'Pourquoi ce n’est pas juste `composer update`',
  'Notre méthode en quatre étapes',
  'Un premier diagnostic gratuit et humain, pas une migration décidée d’avance',
  'Si un audit technique est réellement utile',
  'FAQ minimale',
];

function robotsContainsNoindex(value) {
  return typeof value === 'string'
    && value.toLowerCase().split(',').some((entry) => entry.includes('noindex'));
}

test.describe('Drupal 2027 protected PREPROD landing review', () => {
  test(`${contract.id}: real landing is convincing and technically sane`, async ({
    page,
    audit,
  }, testInfo) => {
    const response = await page.goto(contract.target, {
      waitUntil: 'domcontentloaded',
    });

    expect(response, 'The protected PREPROD landing must return a response.').not.toBeNull();
    expect(response.status(), `Unexpected HTTP status for ${contract.target}`).toBeLessThan(400);

    await expect(page.getByRole('heading', { name: h1, level: 1 })).toBeVisible();
    await expect(page.getByText(submessage, { exact: true })).toBeVisible();
    await expect(page.locator('main')).toBeVisible();
    audit.checks.functional = 'PASS';

    const servicesIllustration = page.locator(
      'img[src*="/images/services/services-page-hero.svg"]',
    );
    await expect(servicesIllustration).toBeVisible();
    audit.checks.hero_services_variant = 'PASS';

    const primary = page.getByRole('link', { name: primaryCta, exact: true }).first();
    const secondary = page.getByRole('link', { name: secondaryCta, exact: true }).first();
    await expect(primary).toBeVisible();
    await expect(secondary).toBeVisible();

    await secondary.click();
    await expect(page).toHaveURL(/#points-a-verifier-socle$/);
    const secondaryTarget = page.locator(secondaryCtaTarget);
    await expect(secondaryTarget).toBeVisible();
    await expect(secondaryTarget).toContainText('1. Socle technique');
    await expect(secondaryTarget).toBeInViewport();
    audit.checks.secondary_cta = 'PASS';

    await primary.click();
    await expect(page).toHaveURL(/#block-emerging-digital-drupal-lifecycle-diagnostic$/);
    audit.checks.primary_cta = 'PASS';

    const form = page.locator(
      'form[id^="webform-submission-drupal-lifecycle-diagnostic"]',
    ).first();
    await expect(form).toBeVisible();
    audit.checks.webform_1007 = 'PASS';

    for (const heading of orderedHeadings) {
      await expect(page.getByRole('heading', { name: heading, level: 2 })).toBeVisible();
    }
    const headingOffsets = [];
    for (const heading of orderedHeadings) {
      headingOffsets.push(await page.getByRole('heading', {
        name: heading,
        level: 2,
      }).evaluate((element) => element.getBoundingClientRect().top + window.scrollY));
    }
    expect(
      headingOffsets.every((offset, index) => index === 0 || offset > headingOffsets[index - 1]),
      'The approved landing sections must remain in the reviewed order.',
    ).toBeTruthy();

    for (const step of [
      '1. COMPRENDRE',
      '2. PRÉPARER',
      '3. VALIDER',
      '4. DÉPLOYER & SUIVRE',
    ]) {
      await expect(page.getByRole('heading', { name: step, level: 3 })).toBeVisible();
    }

    for (const reassurance of [
      'Premier diagnostic gratuit',
      'revue humaine et faible friction',
      'pas de scanner automatique ni d’audit exhaustif',
      'aucun accès au repository ou à la production requis par défaut',
      'audit payant seulement si utile, sans migration automatique',
    ]) {
      await expect(page.getByText(reassurance, { exact: true })).toBeVisible();
    }

    const dom = await page.evaluate(() => ({
      h1Count: document.querySelectorAll('h1').length,
      lang: document.documentElement.lang,
      scrollWidth: document.documentElement.scrollWidth,
      viewportWidth: window.innerWidth,
      hasHorizontalOverflow:
        document.documentElement.scrollWidth > window.innerWidth + 1,
      canonical: document.querySelector('link[rel="canonical"]')?.href ?? '',
      hreflangEn: document.querySelectorAll('link[rel="alternate"][hreflang="en"]').length,
      robotsMeta: document.querySelector('meta[name="robots"]')?.content ?? '',
    }));

    expect(dom.h1Count, 'The landing must expose exactly one H1.').toBe(1);
    expect(dom.lang, 'The landing must expose a French document language.').toBe('fr');
    expect(dom.hasHorizontalOverflow, 'The landing must not overflow horizontally.').toBeFalsy();
    audit.checks.dom = 'PASS';

    expect(dom.canonical, 'A canonical link is expected.').not.toBe('');
    expect(new URL(dom.canonical).pathname).toBe('/fr/drupal-2027');
    audit.checks.canonical = 'PASS';

    expect(dom.hreflangEn, 'No EN hreflang may point to a nonexistent translation.').toBe(0);
    audit.checks.hreflang = 'PASS';

    const xRobotsTag = response.headers()['x-robots-tag'] ?? '';
    expect(
      robotsContainsNoindex(xRobotsTag) || robotsContainsNoindex(dom.robotsMeta),
      'Protected PREPROD must remain noindex.',
    ).toBeTruthy();
    audit.checks.preprod_indexing = 'PASS';

    await page.locator('body').click({ position: { x: 1, y: 1 } });
    await page.keyboard.press('Tab');
    const focus = await page.evaluate(() => ({
      tag: document.activeElement?.tagName ?? '',
      visible: document.activeElement instanceof HTMLElement
        ? Boolean(document.activeElement.offsetWidth || document.activeElement.offsetHeight)
        : false,
    }));
    expect(focus.tag, 'Keyboard navigation must move focus to an element.').not.toBe('BODY');
    expect(focus.visible, 'The first keyboard focus target must be visible.').toBeTruthy();
    audit.checks.accessibility_baseline = 'PASS';

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
    expect(audit.http5xx, 'No same-origin HTTP 5xx response is allowed.').toEqual([]);
    expect(audit.unexpectedHttp4xx, 'No unexpected same-origin HTTP 4xx response is allowed.').toEqual([]);
    expect(audit.failedRequests, 'No same-origin browser request should fail.').toEqual([]);

    const screenshotDirectory = path.resolve(
      'artifacts/browser-validation/screenshots',
    );
    await mkdir(screenshotDirectory, { recursive: true });
    const screenshotFile = `drupal-2027-${testInfo.project.name}.png`;
    const screenshotPath = path.join(screenshotDirectory, screenshotFile);
    await page.screenshot({ path: screenshotPath, fullPage: true });
    await testInfo.attach(`drupal-2027-${testInfo.project.name}`, {
      path: screenshotPath,
      contentType: 'image/png',
    });
    audit.screenshot = `screenshots/${screenshotFile}`;
    audit.checks.visual = 'PASS';
  });
});