import { readFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import { test, expect } from './support/browser-audit.mjs';

const contract = JSON.parse(
  await readFile(
    new URL('./contracts/drupal-2027-preprod.json', import.meta.url),
    'utf8',
  ),
);

const surfaces = [
  {
    lang: 'fr',
    target: contract.target,
    h1: 'Votre plateforme Drupal est-elle prête pour 2027 ?',
    submessage: 'Une plateforme Drupal peut fonctionner correctement aujourd’hui tout en nécessitant de préparer sa prochaine évolution. L’objectif est d’identifier ce qui mérite réellement d’être vérifié — version, dépendances, développements spécifiques, environnement et tests — avant de décider d’une mise à niveau, d’une migration ou d’une modernisation.',
    primaryCta: 'Faire le point sur ma plateforme',
    secondaryCta: 'Voir ce qu’il faut vérifier',
    secondaryHeading: '1. Socle technique',
    orderedHeadings: [
      'Pourquoi 2027 ? Les jalons à connaître',
      'Dans quelles situations êtes-vous concerné ?',
      'Les points à vérifier',
      'Pourquoi ce n’est pas juste `composer update`',
      'Notre méthode en quatre étapes',
      'Un premier diagnostic gratuit et humain, pas une migration décidée d’avance',
      'Si un audit technique est réellement utile',
      'FAQ minimale',
    ],
    methodSteps: [
      '1. COMPRENDRE',
      '2. PRÉPARER',
      '3. VALIDER',
      '4. DÉPLOYER & SUIVRE',
    ],
    reassurance: [
      'Premier diagnostic gratuit',
      'revue humaine et faible friction',
      'pas de scanner automatique ni d’audit exhaustif',
      'aucun accès au repository ou à la production requis par défaut',
      'audit payant seulement si utile, sans migration automatique',
    ],
    alternateLang: 'en',
    canonical: '/fr/drupal-2027',
  },
  {
    lang: 'en',
    target: contract.target_en,
    h1: 'Is your Drupal platform ready for 2027?',
    submessage: 'A Drupal platform can be working well today while still requiring preparation for its next evolution. The goal is to identify what genuinely needs to be checked — version, dependencies, custom developments, environment and tests — before deciding on an upgrade, migration or modernization.',
    primaryCta: 'Review my platform',
    secondaryCta: 'See what needs to be checked',
    secondaryHeading: '1. Technical foundation',
    orderedHeadings: [
      'Why 2027? The milestones to know',
      'When might this apply to you?',
      'What needs to be checked',
      'Why it is not just `composer update`',
      'Our method in four steps',
      'A free, human first diagnostic — not a migration decided in advance',
      'When a technical audit is genuinely useful',
      'FAQ',
    ],
    methodSteps: [
      '1. UNDERSTAND',
      '2. PREPARE',
      '3. VALIDATE',
      '4. DEPLOY & MONITOR',
    ],
    reassurance: [
      'Free first diagnostic',
      'human review and low friction',
      'no automated scanner or exhaustive audit',
      'no repository or production access required by default',
      'paid audit only when useful, with no automatic migration',
    ],
    alternateLang: 'fr',
    canonical: '/en/drupal-2027',
  },
];

function robotsContainsNoindex(value) {
  return typeof value === 'string'
    && value.toLowerCase().split(',').some((entry) => entry.includes('noindex'));
}

test.describe('Drupal 2027 protected PREPROD FR+EN landing review', () => {
  for (const surface of surfaces) {
    test(`${contract.id}: ${surface.lang} candidate renders complete`, async ({
      page,
      audit,
    }, testInfo) => {
      const response = await page.goto(surface.target, {
        waitUntil: 'domcontentloaded',
      });

      expect(response, `The ${surface.lang} PREPROD landing must return a response.`).not.toBeNull();
      expect(response.status(), `Unexpected HTTP status for ${surface.target}`).toBeLessThan(400);

      await expect(page.getByRole('heading', { name: surface.h1, level: 1 })).toBeVisible();
      await expect(page.getByText(surface.submessage, { exact: true })).toBeVisible();
      await expect(page.locator('main')).toBeVisible();
      audit.checks.functional = 'PASS';

      const servicesIllustration = page.locator(
        'img[src*="/images/services/services-page-hero.svg"]',
      );
      await expect(servicesIllustration).toHaveCount(1);
      if (testInfo.project.name === 'mobile') {
        await expect(servicesIllustration).toBeHidden();
      }
      else {
        await expect(servicesIllustration).toBeVisible();
      }
      audit.checks.hero_services_variant = 'PASS';

      const primary = page.getByRole('link', {
        name: surface.primaryCta,
        exact: true,
      }).first();
      const secondary = page.getByRole('link', {
        name: surface.secondaryCta,
        exact: true,
      }).first();
      await expect(primary).toBeVisible();
      await expect(secondary).toBeVisible();

      await secondary.click();
      await expect(page).toHaveURL(/#points-a-verifier-socle$/);
      const secondaryTarget = page.locator('#points-a-verifier-socle');
      await expect(secondaryTarget).toBeVisible();
      await expect(secondaryTarget).toContainText(surface.secondaryHeading);
      await expect(secondaryTarget).toBeInViewport();
      audit.checks.secondary_cta = 'PASS';

      await primary.click();
      await expect(page).toHaveURL(/#block-emerging-digital-drupal-lifecycle-diagnostic$/);
      audit.checks.primary_cta = 'PASS';

      const form = page.locator(
        'form[id^="webform-submission-drupal-lifecycle-diagnostic"]',
      ).first();
      await expect(form).toBeVisible();
      const formSurface = form.locator('xpath=ancestor::*[contains(concat(" ", normalize-space(@class), " "), " ed-section__content--contact-form ")][1]');
      await expect(formSurface).toBeVisible();
      const formStyle = await form.evaluate((element) => {
        const style = window.getComputedStyle(element);
        return {
          backgroundColor: style.backgroundColor,
          borderRadius: style.borderRadius,
          display: style.display,
        };
      });
      expect(formStyle.backgroundColor).toBe('rgb(255, 255, 255)');
      expect(formStyle.display).toBe('grid');
      expect(formStyle.borderRadius).not.toBe('0px');
      audit.checks.webform_1007 = 'PASS';
      audit.checks.webform_contact_visual_language = 'PASS';

      for (const heading of surface.orderedHeadings) {
        await expect(page.getByRole('heading', { name: heading, level: 2 })).toBeVisible();
      }
      const headingOffsets = [];
      for (const heading of surface.orderedHeadings) {
        headingOffsets.push(await page.getByRole('heading', {
          name: heading,
          level: 2,
        }).evaluate((element) => element.getBoundingClientRect().top + window.scrollY));
      }
      expect(
        headingOffsets.every((offset, index) => index === 0 || offset > headingOffsets[index - 1]),
        `The ${surface.lang} landing sections must remain in the reviewed order.`,
      ).toBeTruthy();

      for (const step of surface.methodSteps) {
        await expect(page.getByRole('heading', { name: step, level: 3 })).toBeVisible();
      }
      for (const reassurance of surface.reassurance) {
        await expect(page.getByText(reassurance, { exact: true })).toBeVisible();
      }

      const dom = await page.evaluate((alternateLang) => ({
        h1Count: document.querySelectorAll('h1').length,
        lang: document.documentElement.lang,
        hasHorizontalOverflow:
          document.documentElement.scrollWidth > window.innerWidth + 1,
        canonical: document.querySelector('link[rel="canonical"]')?.href ?? '',
        alternateCount: document.querySelectorAll(
          `link[rel="alternate"][hreflang="${alternateLang}"]`,
        ).length,
        robotsMeta: document.querySelector('meta[name="robots"]')?.content ?? '',
      }), surface.alternateLang);

      expect(dom.h1Count, 'The landing must expose exactly one H1.').toBe(1);
      expect(dom.lang, `The document language must be ${surface.lang}.`).toBe(surface.lang);
      expect(dom.hasHorizontalOverflow, 'The landing must not overflow horizontally.').toBeFalsy();
      audit.checks.dom = 'PASS';

      expect(dom.canonical, 'A canonical link is expected.').not.toBe('');
      expect(new URL(dom.canonical).pathname).toBe(surface.canonical);
      expect(dom.alternateCount, 'The opposite-language hreflang must exist.').toBeGreaterThan(0);
      audit.checks.canonical = 'PASS';
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
      const screenshotFile = `drupal-2027-${surface.lang}-${testInfo.project.name}.png`;
      const screenshotPath = path.join(screenshotDirectory, screenshotFile);
      await page.screenshot({ path: screenshotPath, fullPage: true });
      await testInfo.attach(`drupal-2027-${surface.lang}-${testInfo.project.name}`, {
        path: screenshotPath,
        contentType: 'image/png',
      });
      audit.screenshot = `screenshots/${screenshotFile}`;
      audit.checks.visual = 'PASS';
    });
  }
});
