import { execFileSync } from 'node:child_process';
import { readFile, mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { test, expect } from './support/browser-audit.mjs';

const contract = JSON.parse(
  await readFile(new URL('./contracts/public-blog.json', import.meta.url), 'utf8'),
);
const browserBaseUrl = process.env.BROWSER_VALIDATION_BASE_URL
  ?? process.env.PLAYWRIGHT_BASE_URL
  ?? 'http://127.0.0.1';
// Fixture writes are permitted only against an isolated DDEV browser target.
// PROD and arbitrary remote CI targets must exercise public pages read-only.
const canPrepareIsolatedDdevFixtures = process.env.CI === 'true'
  && new URL(browserBaseUrl).hostname.toLowerCase().endsWith('.ddev.site');
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
 * #1470: verify actual homepage motion in the governed PREPROD browser lane.
 * A green Blog screenshot alone is not evidence that homepage animation runs.
 */
if (process.env.BROWSER_VALIDATION_BASE_URL === 'https://preprod.emergingdigital.be') {
  test.describe('Agency #1470 homepage automatic motion PREPROD FR/EN', () => {
    test('short CSS story, scroll reveals and reduced-motion fallback work without a play button', async ({ page }, testInfo) => {
      test.setTimeout(120_000);

      for (const lang of ['fr', 'en']) {
        await page.emulateMedia({ reducedMotion: 'no-preference' });
        const response = await page.goto('/' + lang, { waitUntil: 'domcontentloaded' });
        expect(response?.status()).toBeLessThan(400);
        const hero = page.locator('.page-main--front .ed-section--hero-home');
        const heading = hero.locator('h1');
        await expect(heading).toBeVisible();
        await expect(hero.locator('.ed-home-kinetic__controls')).toHaveCount(0);
        await expect(hero.getByRole('button', { name: /Voir la présentation|Watch the presentation/ })).toHaveCount(0);

        // The H1 animates directly from CSS, not from a class installed by JS.
        const entrance = await heading.evaluate(element => {
          const animation = element.getAnimations().find(item =>
            item.animationName === 'agency-v2-hero-enter');
          if (!animation) return null;
          const previousTime = animation.currentTime;
          const wasRunning = animation.playState === 'running';
          animation.pause();
          animation.currentTime = 0;
          const from = {
            opacity: Number(getComputedStyle(element).opacity),
            transform: getComputedStyle(element).transform,
          };
          animation.currentTime = animation.effect.getComputedTiming().endTime;
          const to = {
            opacity: Number(getComputedStyle(element).opacity),
            transform: getComputedStyle(element).transform,
          };
          if (previousTime !== null) animation.currentTime = previousTime;
          if (wasRunning) animation.play();
          return { from, to };
        });
        expect(entrance).not.toBeNull();
        expect(entrance.from.opacity).toBeLessThan(0.1);
        expect(entrance.to.opacity).toBeGreaterThan(0.95);
        expect(entrance.from.transform).not.toBe(entrance.to.transform);

        const words = hero.locator('.agency-v2-motion-words span');
        await expect(words).toHaveCount(3);
        const firstWord = words.first();
        await expect(firstWord).toHaveText(lang === 'fr' ? 'CRÉER' : 'CREATE');
        if (testInfo.project.name === 'mobile') {
          const banner = page.locator('#ed-cookie-banner');
          if (await banner.isVisible()) {
            // The mobile hero is initially covered by consent UI. The story
            // must not run behind the banner and must start after dismissal.
            await expect(firstWord).toHaveCSS('animation-name', 'none');
            await banner.getByRole('button', { name: 'Refuser' }).click();
          }
          const media = hero.locator('.agency-v2-hero-media');
          await media.scrollIntoViewIfNeeded();
          await expect(media).toHaveClass(/agency-v2-story-started/);
        }
        await expect.poll(() => firstWord.evaluate(element =>
          Number(getComputedStyle(element).opacity)), { timeout: 3000 }).toBeGreaterThan(0.4);
        const visibleWordOpacity = await firstWord.evaluate(element =>
          Number(getComputedStyle(element).opacity));
        const screenshotDir = path.resolve('artifacts/browser-validation/screenshots');
        await mkdir(screenshotDir, { recursive: true });
        const proofName = 'home-motion-' + lang + '-' + testInfo.project.name;
        const screenshotPath = path.join(screenshotDir, proofName + '.png');
        await hero.screenshot({ path: screenshotPath });
        await testInfo.attach(proofName, { path: screenshotPath, contentType: 'image/png' });

        const pathCard = page.locator('.agency-v2-path').first();
        await pathCard.scrollIntoViewIfNeeded();
        await expect(pathCard).toHaveClass(/agency-v2-is-visible/);
        await expect.poll(() => pathCard.evaluate(element =>
          Number(getComputedStyle(element).opacity))).toBeGreaterThan(0.95);
        const demo = page.locator('.agency-v2-demo__flow');
        await demo.scrollIntoViewIfNeeded();
        await expect(demo).toHaveClass(/agency-v2-is-visible/);
        const demoAnimation = await demo.locator('li').first().evaluate(element =>
          getComputedStyle(element).animationName);
        expect(demoAnimation).toContain('agency-v2-flow-step');

        const evidenceDir = path.resolve('artifacts/browser-validation/evidence');
        await mkdir(evidenceDir, { recursive: true });
        await writeFile(path.join(evidenceDir, proofName + '.json'), JSON.stringify({
          lang, viewport: testInfo.project.name, entrance, demoAnimation,
          firstWord: await firstWord.textContent(), visibleWordOpacity,
          controls: 0, screenshot: 'screenshots/' + proofName + '.png',
        }, null, 2));

        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.reload({ waitUntil: 'domcontentloaded' });
        await expect(page.locator('h1')).toBeVisible();
        const reducedWords = page.locator('.agency-v2-motion-words span');
        await expect(reducedWords).toHaveCount(3);
        expect(await reducedWords.first().evaluate(element =>
          getComputedStyle(element).animationName)).toBe('none');
        expect(await reducedWords.last().evaluate(element =>
          Number(getComputedStyle(element).opacity))).toBe(1);
        await expect(page.locator('.ed-home-kinetic__controls')).toHaveCount(0);
      }
    });
  });
}

/*
 * Agency #1418 R8: homepage proof on the existing trusted public-blog
 * browser lane. Its trusted main workflow executes this exact spec.
 * No change to .github/, Playwright config, runner or control surface.
 */
// Do not register the candidate-only test when the unrelated PREPROD
// analytics workflow points this shared spec at the currently deployed site.
// #1418's manual-playback contract was superseded by #1470 automatic motion.
// PREPROD validates the actual CSS animation and absence of playback controls.

/*
 * #1471: focused visual proof for the existing Services hub on fresh DDEV.
 * Keep the shared PREPROD Blog contract independent of this candidate.
 */
if (process.env.BROWSER_VALIDATION_BASE_URL !== 'https://preprod.emergingdigital.be') {
  test.describe('Agency #1471 graphical Services hub', () => {
    // #1471: released Services content is editor-owned, so the fresh DDEV
    // bootstrap intentionally imports only its historical FR Default Content.
    // Make an ephemeral EN translation solely for the isolated CI browser proof.
    test.beforeAll(async () => {
      if (!canPrepareIsolatedDdevFixtures) {
        return;
      }

      test.setTimeout(90_000);
      const directory = path.resolve('artifacts/browser-validation');
      await mkdir(directory, { recursive: true });
      const lock = path.join(directory, 'services-1471-fixture.lock');
      const php = [
      "$storage = \\Drupal::entityTypeManager()->getStorage('node');",
      "$matches = $storage->loadByProperties(['uuid' => 'd6e1876f-8e32-4f75-b6bb-946de5f5afdb']);",
      "if (count($matches) !== 1) {",
      "  throw new \\RuntimeException('The exact Services fixture node must exist once.');",
      "}",
      "$node = reset($matches);",
      "if ($node->bundle() !== 'page' || $node->language()->getId() !== 'fr' || $node->label() !== 'Services') {",
      "  throw new \\RuntimeException('Services fixture identity or source language diverged.');",
      "}",
      "$path = '/node/' . $node->id();",
      "$alias_storage = \\Drupal::entityTypeManager()->getStorage('path_alias');",
      "foreach ($alias_storage->loadByProperties(['alias' => '/services', 'langcode' => 'en']) as $alias) {",
      "  if ($alias->get('path')->value !== $path) {",
      "    throw new \\RuntimeException('English Services alias belongs to another node.');",
      "  }",
      "}",
      "$refs = [];",
      "$services = 0;",
      "foreach ($node->getTranslation('fr')->get('field_home_components') as $item) {",
      "  $paragraph = $item->entity;",
      "  if ($paragraph === NULL) {",
      "    throw new \\RuntimeException('Services fixture has a missing Paragraph reference.');",
      "  }",
      "  if ($paragraph->bundle() === 'services') {",
      "    $services++;",
      "    if (!$paragraph->hasTranslation('en')) {",
      "      $values = [];",
      "      foreach (['field_heading', 'field_text', 'field_items'] as $field) {",
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
      "if ($services !== 1) {",
      "  throw new \\RuntimeException('Expected exactly one Services Paragraph.');",
      "}",
      "if (!$node->hasTranslation('en')) {",
      "  $english = $node->addTranslation('en', [",
      "    'title' => 'Services',",
      "    'status' => 1,",
      "    'field_home_components' => $refs,",
      "  ]);",
      "  $english->set('path', ['alias' => '/services', 'pathauto' => 0]);",
      "  $english->save();",
      "}",
      "else {",
      "  $english = $node->getTranslation('en');",
      "  $existing_alias = (string) ($english->get('path')->alias ?? '');",
      "  if ($existing_alias !== '/services') {",
      "    throw new \\RuntimeException('Existing English Services translation alias diverged.');",
      "  }",
      "}",
      "\\Drupal::service('path_alias.manager')->cacheClear($path);",
      "print 'R1471_EN_FIXTURE=PASS';",
      ].join('\n');
      const result = execFileSync('flock', [
        '-x', '-w', '60', lock, 'ddev', 'drush', 'php:eval', php,
      ], {
        cwd: process.cwd(),
        encoding: 'utf8',
        timeout: 80_000,
        stdio: ['ignore', 'pipe', 'pipe'],
      });
      expect(result).toContain('R1471_EN_FIXTURE=PASS');
    });

    test('FR/EN choices lead to real groups, and homepage stays unchanged', async ({
      page, audit,
    }, testInfo) => {
      const surfaces = [
        {
          lang: 'fr',
          path: '/fr/services',
          home: '/fr',
          navigation: 'Explorer les services selon votre besoin',
          labels: [['Créer', 'business'], ['Améliorer', 'drupal'], ['Automatiser', 'ai']],
        },
        {
          lang: 'en',
          path: '/en/services',
          home: '/en',
          navigation: 'Explore services by need',
          labels: [['Create', 'business'], ['Improve', 'drupal'], ['Automate', 'ai']],
        },
      ];

      for (const surface of surfaces) {
        const response = await page.goto(surface.path, { waitUntil: 'domcontentloaded' });
        expect(response).not.toBeNull();
        expect(response.status()).toBeLessThan(400);
        await expect(page.locator('html')).toHaveAttribute('lang', surface.lang);
        await expect(page.locator('h1')).toHaveCount(1);

        const navigation = page.getByRole('navigation', { name: surface.navigation });
        await expect(navigation).toBeVisible();
        await expect(navigation.locator('.ed-services-paths__link')).toHaveCount(3);

        for (const [label, family] of surface.labels) {
          const choice = navigation.getByRole('link', { name: new RegExp('^' + label) });
          await expect(choice).toBeVisible();
          const anchor = '#service-family-' + family;
          await expect(choice).toHaveAttribute('href', anchor);
          await expect(page.locator(anchor)).toHaveCount(1);
          await expect(page.locator(anchor)).toBeVisible();
          await choice.click();
          expect(new URL(page.url()).hash).toBe(anchor);
        }

        const overflow = await page.evaluate(() =>
          document.documentElement.scrollWidth > window.innerWidth + 1,
        );
        expect(overflow, 'The Services layout must not overflow horizontally.').toBe(false);

        const screenshotDirectory = path.resolve('artifacts/browser-validation/screenshots');
        await mkdir(screenshotDirectory, { recursive: true });
        const fileName = `services-1471-${surface.lang}-${testInfo.project.name}.png`;
        const screenshotPath = path.join(screenshotDirectory, fileName);
        await page.screenshot({ path: screenshotPath, fullPage: true });
        await testInfo.attach(`services-${surface.lang}`, {
          path: screenshotPath,
          contentType: 'image/png',
        });
        audit.screenshot = `screenshots/${fileName}`;

        await page.goto(surface.home, { waitUntil: 'domcontentloaded' });
        await expect(page.locator('.ed-services-paths')).toHaveCount(0);
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
/*
 * #1473: controlled AI journey visual proof in French and English.
 * The existing editorial AI feature cards remain below the diagram.
 */
if (process.env.BROWSER_VALIDATION_BASE_URL !== 'https://preprod.emergingdigital.be') {
  test.describe('Agency #1473 IA & Drupal governed journey', () => {
    // The editor-owned IA page has only a historical FR Default Content seed.
    // Add an EN translation for fresh isolated CI DDEV without publishing content.
    test.beforeAll(async () => {
      if (!canPrepareIsolatedDdevFixtures) {
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

/*
 * #1480: inner-page visual parity needs real FR/EN desktop/mobile evidence.
 * This runs only in the existing governed PREPROD browser-validation lane.
 */
if (process.env.BROWSER_VALIDATION_BASE_URL === 'https://preprod.emergingdigital.be') {
  test.describe('Agency #1480 automatic inner-page visual parity', () => {
    test('shared V2 hero, illustrations, flow and motion stay usable FR/EN', async ({ page, audit }, testInfo) => {
      test.setTimeout(180_000);

      const surfaces = {
        fr: ['services', 'ia-drupal', 'cas-clients', 'equipe', 'contact'],
        en: ['services', 'ai-drupal', 'case-studies', 'team', 'contact'],
      };
      const screenshotDirectory = path.resolve('artifacts/browser-validation/screenshots');
      await mkdir(screenshotDirectory, { recursive: true });
      await page.emulateMedia({ reducedMotion: 'no-preference' });

      for (const [lang, slugs] of Object.entries(surfaces)) {
        for (const slug of slugs) {
          const response = await page.goto('/' + lang + '/' + slug, {
            waitUntil: 'domcontentloaded',
          });
          expect(response?.status(), 'Inner page must resolve: ' + lang + '/' + slug).toBeLessThan(400);

          const reject = page.locator('[data-ed-cookie-action="reject"]');
          if (await reject.isVisible()) {
            await reject.click();
          }

          const hero = page.locator('.page-main:not(.page-main--front) .ed-section--hero');
          await expect(hero, 'Inner pages must reuse the existing Drupal hero.').toBeVisible();
          await expect(hero.locator('h2')).toBeVisible();
          await expect(page.locator('h1'), 'Drupal page heading must remain present.').toHaveCount(1);

          const illustration = hero.locator('.ed-section__hero-illustration').first();
          await expect(illustration, 'The hero illustration must be visible in each viewport.').toBeVisible();
          const visual = await hero.evaluate(element => {
            const image = element.querySelector('img.ed-section__hero-illustration');
            return {
              background: getComputedStyle(element).backgroundColor,
              imageLoaded: Boolean(image?.naturalWidth),
              width: document.documentElement.scrollWidth,
              viewport: window.innerWidth,
            };
          });
          expect(visual.background).toBe('rgb(17, 24, 39)');
          expect(visual.imageLoaded, 'The existing SVG must load visibly.').toBe(true);
          expect(visual.width, 'No horizontal overflow on ' + lang + '/' + slug)
            .toBeLessThanOrEqual(visual.viewport + 1);

          if (slug === 'services') {
            const section = page.locator('.ed-section--services-grid');
            await section.scrollIntoViewIfNeeded();
            await expect(section, 'Motion must start automatically when scrolled into view.')
              .toHaveClass(/ed-site-is-visible/);
          }

          // Save the actual first screen, not a mock-up, for owner visual review.
          await hero.scrollIntoViewIfNeeded();
          const fileName = 'inner-v2-' + lang + '-' + slug + '-' + testInfo.project.name + '.png';
          const screenshotPath = path.join(screenshotDirectory, fileName);
          await page.screenshot({ path: screenshotPath, fullPage: false });
          await testInfo.attach('inner-v2-' + lang + '-' + slug, {
            path: screenshotPath,
            contentType: 'image/png',
          });
        }
      }

      await page.emulateMedia({ reducedMotion: 'reduce' });
      await page.goto('/fr/services', { waitUntil: 'domcontentloaded' });
      await expect(page.locator('.page-main')).not.toHaveClass(/ed-site-motion-ready/);
      await expect(page.locator('.ed-section--services-grid')).toBeVisible();
      audit.checks.functional = 'PASS';
      audit.checks.dom = 'PASS';
      audit.checks.visual = 'PASS';
    });
  });
}
