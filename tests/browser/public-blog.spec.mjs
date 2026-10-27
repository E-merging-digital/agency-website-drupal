import { execFileSync } from 'node:child_process';
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
// Do not register the candidate-only test when the unrelated PREPROD
// analytics workflow points this shared spec at the currently deployed site.
if (process.env.BROWSER_VALIDATION_BASE_URL !== 'https://preprod.emergingdigital.be') {
test.describe('Agency #1418 B+ Kinetic homepage FR/EN', () => {
  test('real home hero is readable and opt-in Kinetic is perceptible', async ({
    page, browser, audit,
  }, testInfo) => {
    test.setTimeout(110_000);
    const surfaces = [
      {
        lang: 'fr', path: '/fr', firstWord: 'CRÉER',
        play: 'Voir la présentation', pause: 'Pause', resume: 'Reprendre',
        stop: 'Arrêter', replay: 'Revoir', reduced: 'Animation réduite',
      },
      {
        lang: 'en', path: '/en', firstWord: 'CREATE',
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
      const primary = hero.locator('.ed-actions__primary').getByRole('link').first();
      const secondary = hero.locator('.ed-actions__secondary').getByRole('link').first();
      await expect(primary).toBeVisible();
      await expect(secondary).toBeVisible();
      await expect(primary).toHaveText(/\S/);
      await expect(secondary).toHaveText(/\S/);
      // Drupal's source-owned hero may render unprefixed canonical links.
      const routePath = route => new RegExp(`^/(?:${surface.lang}/)?${route}$`);
      expect(new URL(await primary.getAttribute('href'), page.url()).pathname)
        .toMatch(routePath('contact'));
      expect(new URL(await secondary.getAttribute('href'), page.url()).pathname)
        .toMatch(routePath('services'));
      await expect(hero.locator('.ed-section__intro')).toContainText(/\S/);

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
      // The first word is visible only within a real part of its 2.7-5.25s
      // track. Sample progress and computed opacity within the same animation
      // frame, and pause immediately at the first perceptible frame. A skipped
      // visible window remains a real test failure, not a timing exemption.
      const word = visual.getByText(surface.firstWord, { exact: true });
      const visibleFrame = await page.evaluate(({ firstWord, pauseLabel }) => {
        const host = document.querySelector('.page-main--front .ed-section--hero-home');
        const bar = host?.querySelector('.ed-home-kinetic__progress');
        const candidate = Array.from(host?.querySelectorAll('.ed-home-kinetic__word') || [])
          .find(el => el.textContent?.trim() === firstWord);
        const pauseButton = Array.from(host?.querySelectorAll('.ed-home-kinetic__button') || [])
          .find(el => el.textContent?.trim() === pauseLabel);
        if (!bar || !candidate || !pauseButton) {
          throw new Error('Kinetic visible-frame controls are missing.');
        }
        return new Promise((resolve, reject) => {
          const startedAt = performance.now();
          function sample() {
            const elapsed = Number(bar.value);
            const opacity = Number(getComputedStyle(candidate).opacity);
            if (elapsed >= 3_200 && elapsed < 4_800 && opacity > 0.35) {
              pauseButton.click();
              resolve({ elapsed: Number(bar.value), opacity });
              return;
            }
            if (elapsed >= 5_100 || performance.now() - startedAt > 12_000) {
              reject(new Error('No perceptible Kinetic word frame: elapsed=' +
                elapsed + ', opacity=' + opacity));
              return;
            }
            requestAnimationFrame(sample);
          }
          requestAnimationFrame(sample);
        });
      }, { firstWord: surface.firstWord, pauseLabel: surface.pause });
      expect(visibleFrame.opacity).toBeGreaterThan(0.35);
      expect(visibleFrame.elapsed).toBeGreaterThanOrEqual(3_200);
      expect(visibleFrame.elapsed).toBeLessThan(4_800);
      await expect(hero.getByRole('button', { name: surface.resume })).toBeVisible();
      const pausedAt = await progress.evaluate(el => el.value);
      expect(pausedAt).toBe(visibleFrame.elapsed);
      expect(await word.evaluate(el => Number(getComputedStyle(el).opacity)))
        .toBeGreaterThan(0.35);
      const playingShot = path.join(dir, `home-1418-${surface.lang}-${testInfo.project.name}-kinetic.png`);
      await page.screenshot({ path: playingShot, fullPage: true });
      await testInfo.attach(`home-${surface.lang}-kinetic`, {
        path: playingShot, contentType: 'image/png',
      });
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
      const response = await staticPage.goto(new URL('/fr', page.url()).toString(), { waitUntil: 'domcontentloaded' });
      expect(response).not.toBeNull();
      expect(response.status()).toBeLessThan(400);
      await expect(staticPage.locator('h1')).toHaveCount(1);
      await expect(staticPage.locator('h1')).toBeVisible();
      const contactCta = staticPage.locator('.page-main--front .ed-section--hero-home')
        .locator('.ed-actions__primary').getByRole('link').first();
      await expect(contactCta).toBeVisible();
      await expect(contactCta).toHaveText(/\S/);
      expect(new URL(await contactCta.getAttribute('href'), staticPage.url()).pathname)
        .toMatch(/^\/(?:fr\/)?contact$/);
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
}

/*
 * #1473: controlled AI journey visual proof in French and English.
 * The existing editorial AI feature cards remain below the diagram.
 */
if (process.env.BROWSER_VALIDATION_BASE_URL !== 'https://preprod.emergingdigital.be') {
  test.describe('Agency #1473 IA & Drupal governed journey', () => {
    // The editor-owned IA page has only a historical FR Default Content seed.
    // Add an EN translation for fresh isolated CI DDEV without publishing content.
    test.beforeAll(async () => {
      if (process.env.CI !== 'true') {
        return;
      }
      test.setTimeout(90_000);
      const directory = path.resolve('artifacts/browser-validation');
      await mkdir(directory, { recursive: true });
      const lock = path.join(directory, 'ai-1473-fixture.lock');
      const php = [
        "$storage = \\Drupal::entityTypeManager()->getStorage('node');",
        "$matches = $storage->loadByProperties(['uuid' => '3636da02-c8e6-40ea-aa71-54148f029a09']);",
        "if (count($matches) !== 1) {",
        "  throw new \\RuntimeException('Expected exactly one IA & Drupal fixture node.');",
        "}",
        "$node = reset($matches);",
        "if ($node->bundle() !== 'page' || $node->language()->getId() !== 'fr' || $node->label() !== 'IA & Drupal') {",
        "  throw new \\RuntimeException('IA fixture identity or source language diverged.');",
        "}",
        "$path = '/node/' . $node->id();",
        "$alias_storage = \\Drupal::entityTypeManager()->getStorage('path_alias');",
        "foreach ($alias_storage->loadByProperties(['alias' => '/ai-drupal', 'langcode' => 'en']) as $alias) {",
        "  if ($alias->get('path')->value !== $path) {",
        "    throw new \\RuntimeException('English IA alias belongs to another node.');",
        "  }",
        "}",
        "$refs = [];",
        "$ai_features = 0;",
        "foreach ($node->getTranslation('fr')->get('field_home_components') as $item) {",
        "  $paragraph = $item->entity;",
        "  if ($paragraph === NULL) {",
        "    throw new \\RuntimeException('IA fixture has a missing Paragraph reference.');",
        "  }",
        "  if ($paragraph->bundle() === 'ai_features') {",
        "    $ai_features++;",
        "    if (!$paragraph->hasTranslation('en')) {",
        "      $values = [];",
        "      foreach (['field_heading', 'field_items', 'field_text'] as $field) {",
        "        if ($paragraph->hasField($field)) {",
        "          $values[$field] = $paragraph->getTranslation('fr')->get($field)->getValue();",
        "        }",
        "      }",
        "      $paragraph->addTranslation('en', $values);",
        "      $paragraph->save();",
        "    }",
        "  }",
        "  $refs[] = [",
        "    'target_id' => $paragraph->id(),",
        "    'target_revision_id' => $paragraph->getRevisionId(),",
        "  ];",
        "}",
        "if ($ai_features !== 1) {",
        "  throw new \\RuntimeException('Expected exactly one IA features Paragraph.');",
        "}",
        "if (!$node->hasTranslation('en')) {",
        "  $english = $node->addTranslation('en', [",
        "    'title' => 'AI & Drupal',",
        "    'status' => 1,",
        "    'field_home_components' => $refs,",
        "  ]);",
        "  $english->set('path', ['alias' => '/ai-drupal', 'pathauto' => 0]);",
        "  $english->save();",
        "}",
        "else {",
        "  $english = $node->getTranslation('en');",
        "  $existing_alias = (string) ($english->get('path')->alias ?? '');",
        "  if ($existing_alias !== '/ai-drupal') {",
        "    throw new \\RuntimeException('Existing English IA translation alias diverged.');",
        "  }",
        "}",
        "\\Drupal::service('path_alias.manager')->cacheClear($path);",
        "print 'R1473_EN_FIXTURE=PASS';",
      ].join('\n');
      const result = execFileSync('flock', [
        '-x', '-w', '60', lock, 'ddev', 'drush', 'php:eval', php,
      ], {
        cwd: process.cwd(), encoding: 'utf8', timeout: 80_000,
        stdio: ['ignore', 'pipe', 'pipe'],
      });
      expect(result).toContain('R1473_EN_FIXTURE=PASS');
    });

    test('FR/EN four-step flow emphasizes human approval without overflow', async ({
      page, audit,
    }, testInfo) => {
      const surfaces = [
        {
          lang: 'fr',
          path: '/fr/ia-drupal',
          home: '/fr',
          titles: ['Votre contenu', 'Assistance IA', 'Validation humaine', 'Publication'],
        },
        {
          lang: 'en',
          path: '/en/ai-drupal',
          home: '/en',
          titles: ['Your content', 'AI assistance', 'Human approval', 'Publication'],
        },
      ];

      for (const surface of surfaces) {
        const response = await page.goto(surface.path, { waitUntil: 'domcontentloaded' });
        expect(response).not.toBeNull();
        expect(response.status()).toBeLessThan(400);
        await expect(page.locator('html')).toHaveAttribute('lang', surface.lang);
        await expect(page.locator('h1')).toHaveCount(1);

        const flow = page.locator('section.ed-ai-flow');
        await expect(flow).toHaveCount(1);
        await expect(flow).toBeVisible();
        await expect(flow.locator('h2')).toBeVisible();
        const steps = flow.locator('li.ed-ai-flow__step');
        await expect(steps).toHaveCount(4);

        for (const [index, title] of surface.titles.entries()) {
          await expect(steps.nth(index).locator('.ed-ai-flow__step-title'))
            .toHaveText(title);
        }
        await expect(steps.nth(2)).toHaveClass(/ed-ai-flow__step--human/);
        const approvalBorder = await steps.nth(2).evaluate(element =>
          parseFloat(getComputedStyle(element).borderTopWidth),
        );
        expect(approvalBorder, 'Human approval requires a visible accent border.')
          .toBeGreaterThanOrEqual(2);
        await expect(page.locator('.ed-cards--ai')).toBeVisible();

        const overflow = await page.evaluate(() =>
          document.documentElement.scrollWidth > window.innerWidth + 1,
        );
        expect(overflow, 'The AI diagram must not overflow horizontally.').toBe(false);

        const screenshotDirectory = path.resolve('artifacts/browser-validation/screenshots');
        await mkdir(screenshotDirectory, { recursive: true });
        const fileName = `ai-journey-1473-${surface.lang}-${testInfo.project.name}.png`;
        const screenshotPath = path.join(screenshotDirectory, fileName);
        await page.screenshot({ path: screenshotPath, fullPage: true });
        await testInfo.attach(`ai-journey-${surface.lang}`, {
          path: screenshotPath,
          contentType: 'image/png',
        });
        audit.screenshot = `screenshots/${fileName}`;

        await page.goto(surface.home, { waitUntil: 'domcontentloaded' });
        await expect(page.locator('.ed-ai-flow')).toHaveCount(0);
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
    });
  });
}
