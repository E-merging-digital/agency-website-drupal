import { readFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import { test, expect } from './support/browser-audit.mjs';

const contract = JSON.parse(await readFile(new URL('./contracts/homepage-brand-1059-preprod.json', import.meta.url), 'utf8'));

const surfaces = [
  {
    lang: 'fr',
    target: contract.target,
    h1: 'Créer, améliorer ou moderniser votre plateforme web',
    subtitle: 'E-merging Digital accompagne les PME, ASBL et organisations qui lancent un nouveau projet, doivent faire évoluer une plateforme existante ou veulent moderniser un environnement devenu difficile à maintenir. Nous partons du besoin métier avant de choisir les technologies qui le servent.',
    primary: 'Parler de mon projet',
    secondary: 'Voir les expertises',
    axes: [
      ['CRÉER', 'Vous partez d’un nouveau besoin. Nous aidons à cadrer les usages, les parcours, les contenus et la base technique pour construire une plateforme utile, maintenable et capable d’évoluer.'],
      ['AMÉLIORER', 'Votre plateforme fonctionne déjà, mais vous avez besoin de nouvelles fonctionnalités, de meilleures intégrations, de parcours plus clairs ou d’automatiser certains usages. Nous faisons évoluer l’existant sans repartir de zéro par réflexe.'],
      ['MODERNISER', 'Votre site ou application repose sur un socle vieillissant, accumule de la dette technique ou devient trop coûteux à faire évoluer. Nous aidons à prioriser, fiabiliser et moderniser progressivement, jusqu’à la migration lorsque celle-ci est réellement justifiée.'],
    ],
    primaryPath: '/fr/contact',
    secondaryPath: '/fr/services',
    canonical: '/fr',
    alternateLang: 'en',
  },
  {
    lang: 'en',
    target: contract.target_en,
    h1: 'Create, improve or modernise your web platform',
    subtitle: 'E-merging Digital supports SMEs, non-profits and other organisations launching a new project, evolving an existing platform or modernising an environment that has become difficult to maintain. We start with the business need before choosing the technologies that best serve it.',
    primary: 'Discuss my project',
    secondary: 'Explore our expertise',
    axes: [
      ['CREATE', 'You are starting from a new need. We help define the use cases, user journeys, content and technical foundations needed to build a useful, maintainable platform that can evolve over time.'],
      ['IMPROVE', 'Your platform already works, but you need new features, better integrations, clearer user journeys or automation for some processes. We evolve what already exists without starting from scratch by default.'],
      ['MODERNISE', 'Your website or application relies on an ageing foundation, has accumulated technical debt or is becoming too costly to evolve. We help prioritise, stabilise and modernise it progressively, through to migration when migration is genuinely justified.'],
    ],
    primaryPath: '/en/contact',
    secondaryPath: '/en/services',
    canonical: '/en',
    alternateLang: 'fr',
  },
];

test.describe('Homepage Brand #1059 PREPROD FR+EN parity', () => {
  for (const surface of surfaces) {
    test(`${contract.id}: ${surface.lang} exact candidate`, async ({ page, audit }, testInfo) => {
      const response = await page.goto(surface.target, { waitUntil: 'domcontentloaded' });
      expect(response).not.toBeNull();
      expect(response.status()).toBeLessThan(400);

      await expect(page.getByRole('heading', { name: surface.h1, level: 1, exact: true })).toBeVisible();
      await expect(page.getByText(surface.subtitle, { exact: true }).first()).toBeVisible();
      const primary = page.getByRole('link', { name: surface.primary, exact: true }).first();
      const secondary = page.getByRole('link', { name: surface.secondary, exact: true }).first();
      await expect(primary).toBeVisible();
      await expect(secondary).toBeVisible();
      expect(new URL(await primary.getAttribute('href'), page.url()).pathname).toBe(surface.primaryPath);
      expect(new URL(await secondary.getAttribute('href'), page.url()).pathname).toBe(surface.secondaryPath);
      for (const [heading, body] of surface.axes) {
        await expect(page.getByRole('heading', { name: heading, exact: true }).first()).toBeVisible();
        await expect(page.getByText(body, { exact: true }).first()).toBeVisible();
      }
      audit.checks.functional = 'PASS';
      audit.checks.cta_destinations = 'PASS';

      const dom = await page.evaluate((alternateLang) => {
        const schema = [...document.querySelectorAll('script[type="application/ld+json"]')]
          .map((node) => node.textContent || '')
          .filter(Boolean);
        return {
          h1Count: document.querySelectorAll('h1').length,
          lang: document.documentElement.lang,
          overflow: document.documentElement.scrollWidth > window.innerWidth + 1,
          canonical: document.querySelector('link[rel="canonical"]')?.href ?? '',
          alternateCount: document.querySelectorAll(`link[rel="alternate"][hreflang="${alternateLang}"]`).length,
          schema,
        };
      }, surface.alternateLang);
      expect(dom.h1Count).toBe(1);
      expect(dom.lang).toBe(surface.lang);
      expect(dom.overflow).toBeFalsy();
      audit.checks.dom = 'PASS';
      expect(dom.canonical).not.toBe('');
      expect(new URL(dom.canonical).pathname).toBe(surface.canonical);
      expect(dom.alternateCount).toBeGreaterThan(0);
      audit.checks.canonical = 'PASS';
      audit.checks.hreflang = 'PASS';
      expect(dom.schema.length).toBeGreaterThan(0);
      for (const value of dom.schema) expect(() => JSON.parse(value)).not.toThrow();
      audit.checks.schema_continuity = 'PASS';

      await page.locator('body').click({ position: { x: 1, y: 1 } });
      await page.keyboard.press('Tab');
      const focus = await page.evaluate(() => ({
        tag: document.activeElement?.tagName ?? '',
        visible: document.activeElement instanceof HTMLElement
          ? Boolean(document.activeElement.offsetWidth || document.activeElement.offsetHeight)
          : false,
      }));
      expect(focus.tag).not.toBe('BODY');
      expect(focus.visible).toBeTruthy();
      audit.checks.accessibility_baseline = 'PASS';

      audit.checks.console = audit.consoleErrors.length === 0 && audit.pageErrors.length === 0 ? 'PASS' : 'FAIL';
      audit.checks.network = audit.http5xx.length === 0 && audit.unexpectedHttp4xx.length === 0 && audit.failedRequests.length === 0 ? 'PASS' : 'FAIL';
      expect(audit.consoleErrors).toEqual([]);
      expect(audit.pageErrors).toEqual([]);
      expect(audit.http5xx).toEqual([]);
      expect(audit.unexpectedHttp4xx).toEqual([]);
      expect(audit.failedRequests).toEqual([]);

      const screenshotDirectory = path.resolve('artifacts/browser-validation/screenshots');
      await mkdir(screenshotDirectory, { recursive: true });
      const screenshotFile = `homepage-1059-${surface.lang}-${testInfo.project.name}.png`;
      const screenshotPath = path.join(screenshotDirectory, screenshotFile);
      await page.screenshot({ path: screenshotPath, fullPage: true });
      await testInfo.attach(`homepage-1059-${surface.lang}-${testInfo.project.name}`, { path: screenshotPath, contentType: 'image/png' });
      audit.screenshot = `screenshots/${screenshotFile}`;
      audit.checks.visual = 'PASS';
    });
  }
});
