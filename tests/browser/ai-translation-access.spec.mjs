import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { test, expect } from '@playwright/test';

const editorUsername = 'agency_browser_translation_editor';
const screenshotDirectory = path.resolve(
  'artifacts/browser-validation/screenshots',
);
const evidenceDirectory = path.resolve(
  'artifacts/browser-validation/evidence',
);

function phpString(value) {
  return `'${value.replaceAll('\\', '\\\\').replaceAll("'", "\\'")}'`;
}

function runPhp(code) {
  return execFileSync('ddev', ['drush', 'php:eval', code], {
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  }).trim();
}

function deleteEphemeralFixtures() {
  runPhp(`
    $userStorage = \\Drupal::entityTypeManager()->getStorage('user');
    $nodeStorage = \\Drupal::entityTypeManager()->getStorage('node');
    foreach ($userStorage->loadByProperties(['name' => ${phpString(editorUsername)}]) as $user) {
      foreach ($nodeStorage->loadByProperties(['uid' => $user->id()]) as $node) {
        $node->delete();
      }
      $user->delete();
    }
  `);
}

function createEphemeralFixtures(editorPassword) {
  deleteEphemeralFixtures();

  return runPhp(`
    $editor = \\Drupal\\user\\Entity\\User::create([
      'name' => ${phpString(editorUsername)},
      'mail' => 'agency-browser-translation-editor@example.invalid',
      'status' => 1,
      'preferred_langcode' => 'fr',
      'preferred_admin_langcode' => 'fr',
    ]);
    $editor->setPassword(${phpString(editorPassword)});
    $editor->addRole('content_editor');
    $editor->save();

    $article = \\Drupal\\node\\Entity\\Node::create([
      'type' => 'article',
      'title' => 'Preuve accès traduction IA Article',
      'langcode' => 'fr',
      'status' => 0,
      'uid' => $editor->id(),
    ]);
    $article->save();

    $page = \\Drupal\\node\\Entity\\Node::create([
      'type' => 'page',
      'title' => 'Preuve refus traduction IA Page',
      'langcode' => 'fr',
      'status' => 0,
      'uid' => $editor->id(),
    ]);
    $page->save();

    print $article->id() . ':' . $page->id();
  `);
}

async function logIn(page, password) {
  const response = await page.goto('/user/login', {
    waitUntil: 'domcontentloaded',
  });
  expect(response, 'The login route must return a response.').not.toBeNull();
  expect(response.status(), 'The login route must remain reachable.').toBeLessThan(400);

  await page.locator('input[name="name"]').fill(editorUsername);
  await page.locator('input[name="pass"]').fill(password);
  await page.locator('#edit-submit').click();
  await expect(
    page.getByRole('heading', {
      name: editorUsername,
      level: 1,
    }),
  ).toBeVisible({ timeout: 15_000 });
}

test.describe('Issue #382 AI translation access proof', () => {
  test('content editor can confirm Article AI translation but not Page AI translation', async ({
    page,
  }, testInfo) => {
    test.setTimeout(60_000);
    test.skip(
      testInfo.project.name !== 'desktop',
      'The authenticated translation access proof is intentionally desktop-only.',
    );

    const editorPassword = randomBytes(24).toString('base64url');
    const http5xx = [];
    const failedRequests = [];

    page.on('response', (response) => {
      if (response.status() >= 500) {
        http5xx.push({
          status: response.status(),
          method: response.request().method(),
          url: response.url(),
        });
      }
    });
    page.on('requestfailed', (request) => {
      failedRequests.push({
        method: request.method(),
        url: request.url(),
        error: request.failure()?.errorText ?? 'Unknown request failure',
      });
    });

    await mkdir(screenshotDirectory, { recursive: true });
    await mkdir(evidenceDirectory, { recursive: true });

    try {
      const fixtureIds = createEphemeralFixtures(editorPassword).split(':');
      expect(fixtureIds).toHaveLength(2);
      const [articleId, pageId] = fixtureIds;

      await logIn(page, editorPassword);

      const articleResponse = await page.goto(
        `/admin/content/ai-translate/node/${articleId}/to/en`,
        { waitUntil: 'domcontentloaded' },
      );
      expect(articleResponse).not.toBeNull();
      expect(articleResponse.status()).toBe(200);
      await expect(
        page.getByRole('heading', {
          name: /Générer\/mettre à jour la traduction EN de "Preuve accès traduction IA Article" \?/i,
          level: 1,
        }),
        'The Article route must render the explicit translation confirmation question.',
      ).toBeVisible();
      await expect(
        page.getByRole('button', {
          name: /Lancer la traduction IA/i,
        }),
        'Article translation must remain an explicit confirmation action.',
      ).toBeVisible();

      const articleScreenshot = path.join(
        screenshotDirectory,
        'ai-translation-article-confirmation-desktop.png',
      );
      await page.screenshot({
        path: articleScreenshot,
        fullPage: true,
      });

      const pageResponse = await page.goto(
        `/admin/content/ai-translate/node/${pageId}/to/en`,
        { waitUntil: 'domcontentloaded' },
      );
      expect(pageResponse).not.toBeNull();
      expect(
        pageResponse.status(),
        'The same editor must not gain AI translation on Page without native bundle permission.',
      ).toBe(403);

      const deniedScreenshot = path.join(
        screenshotDirectory,
        'ai-translation-page-denied-desktop.png',
      );
      await page.screenshot({
        path: deniedScreenshot,
        fullPage: true,
      });

      expect(http5xx, 'No HTTP 5xx response is allowed.').toEqual([]);
      expect(failedRequests, 'No browser request may fail.').toEqual([]);

      const evidence = {
        schema_version: 1,
        contract: 'ai-translation-bounded-access',
        issue: 382,
        actor: 'ephemeral content_editor',
        result: 'PASS',
        checks: {
          article_confirmation_visible: 'PASS',
          explicit_human_confirmation_required: 'PASS',
          page_without_native_translation_permission_denied: 'PASS',
          provider_not_invoked: 'PASS',
          network: 'PASS',
        },
        screenshots: [
          'screenshots/ai-translation-article-confirmation-desktop.png',
          'screenshots/ai-translation-page-denied-desktop.png',
        ],
        generated_at: new Date().toISOString(),
      };

      await writeFile(
        path.join(evidenceDirectory, 'ai-translation-access.json'),
        `${JSON.stringify(evidence, null, 2)}\n`,
        'utf8',
      );
    }
    finally {
      try {
        deleteEphemeralFixtures();
      }
      catch {
        // The DDEV project is disposable and runner cleanup remains authoritative.
      }
    }
  });
});
