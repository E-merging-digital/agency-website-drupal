import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { test, expect } from '@playwright/test';

const editorUsername = 'agency_browser_editor';
const plainEditorUsername = 'agency_browser_plain_editor';
const plainEditorRole = 'agency_browser_plain_editor';
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
    $storage = \\Drupal::entityTypeManager()->getStorage('user');
    foreach ([${phpString(editorUsername)}, ${phpString(plainEditorUsername)}] as $name) {
      foreach ($storage->loadByProperties(['name' => $name]) as $user) {
        $user->delete();
      }
    }
    if ($role = \\Drupal\\user\\Entity\\Role::load(${phpString(plainEditorRole)})) {
      $role->delete();
    }
  `);
}

function createEphemeralFixtures(editorPassword, plainEditorPassword) {
  deleteEphemeralFixtures();

  runPhp(`
    $role = \\Drupal\\user\\Entity\\Role::create([
      'id' => ${phpString(plainEditorRole)},
      'label' => 'Agency browser plain editor',
    ]);
    $role->grantPermission('create article content');
    $role->save();

    $editor = \\Drupal\\user\\Entity\\User::create([
      'name' => ${phpString(editorUsername)},
      'mail' => 'agency-browser-editor@example.invalid',
      'status' => 1,
      'preferred_langcode' => 'fr',
      'preferred_admin_langcode' => 'fr',
    ]);
    $editor->setPassword(${phpString(editorPassword)});
    $editor->addRole('content_editor');
    $editor->save();

    $plain = \\Drupal\\user\\Entity\\User::create([
      'name' => ${phpString(plainEditorUsername)},
      'mail' => 'agency-browser-plain-editor@example.invalid',
      'status' => 1,
      'preferred_langcode' => 'fr',
      'preferred_admin_langcode' => 'fr',
    ]);
    $plain->setPassword(${phpString(plainEditorPassword)});
    $plain->addRole(${phpString(plainEditorRole)});
    $plain->save();
  `);
}

async function logIn(page, username, password) {
  const response = await page.goto('/user/login', {
    waitUntil: 'domcontentloaded',
  });
  expect(response, 'The login route must return a response.').not.toBeNull();
  expect(response.status(), 'The login route must remain reachable.').toBeLessThan(400);

  await page.locator('input[name="name"]').fill(username);
  await page.locator('input[name="pass"]').fill(password);
  await page.locator('#edit-submit').click();
  await page.waitForLoadState('domcontentloaded');
}

async function openArticleForm(page) {
  const response = await page.goto('/node/add/article', {
    waitUntil: 'domcontentloaded',
  });
  expect(response, 'The Article form must return a response.').not.toBeNull();
  expect(response.status(), 'The Article form must remain reachable.').toBeLessThan(400);

  const editable = page.locator('.ck-editor__editable[contenteditable="true"]').first();
  await expect(editable).toBeVisible({ timeout: 15_000 });
  return editable;
}

function aiAssistantButton(page) {
  return page.getByRole('button', {
    name: /AI Assistant|Assistant IA/i,
  }).first();
}

test.describe('Issue #380 authenticated AI CKEditor proof', () => {
  test('Article exposes the guarded AI menu only to an authorized editor', async ({
    page,
    baseURL,
  }, testInfo) => {
    test.skip(
      testInfo.project.name !== 'desktop',
      'The authenticated admin proof is intentionally desktop-only.',
    );

    if (!baseURL) {
      throw new Error('Playwright baseURL is required.');
    }

    const editorPassword = randomBytes(24).toString('base64url');
    const plainEditorPassword = randomBytes(24).toString('base64url');
    const consoleErrors = [];
    const pageErrors = [];
    const http5xx = [];
    const failedRequests = [];

    page.on('console', (message) => {
      if (message.type() === 'error') {
        consoleErrors.push(message.text());
      }
    });
    page.on('pageerror', (error) => {
      pageErrors.push(error.message);
    });
    page.on('response', (response) => {
      if (
        new URL(response.url()).origin === new URL(baseURL).origin
        && response.status() >= 500
      ) {
        http5xx.push({
          status: response.status(),
          method: response.request().method(),
          url: response.url(),
        });
      }
    });
    page.on('requestfailed', (request) => {
      if (new URL(request.url()).origin === new URL(baseURL).origin) {
        failedRequests.push({
          method: request.method(),
          url: request.url(),
          error: request.failure()?.errorText ?? 'Unknown request failure',
        });
      }
    });

    await mkdir(screenshotDirectory, { recursive: true });
    await mkdir(evidenceDirectory, { recursive: true });

    try {
      createEphemeralFixtures(editorPassword, plainEditorPassword);

      await logIn(page, plainEditorUsername, plainEditorPassword);
      await openArticleForm(page);
      await expect(aiAssistantButton(page)).toHaveCount(0);

      const noPermissionScreenshot = path.join(
        screenshotDirectory,
        'ai-ckeditor-article-no-permission-desktop.png',
      );
      await page.screenshot({
        path: noPermissionScreenshot,
        fullPage: true,
      });

      await page.context().clearCookies();
      await logIn(page, editorUsername, editorPassword);
      const editable = await openArticleForm(page);

      const aiButton = aiAssistantButton(page);
      await expect(aiButton).toBeVisible();
      await aiButton.click();

      const panel = page.locator('.ck-dropdown__panel:visible').last();
      await expect(panel).toBeVisible();
      const menuText = await panel.innerText();

      expect(menuText).toMatch(/Generate with AI|Génér.+IA/i);
      expect(menuText).toMatch(/Modify with a prompt|Modifier.+(?:prompt|invite)/i);
      expect(menuText).toMatch(/Fix spelling|Corriger.+orthograph/i);
      expect(menuText).toMatch(/Summarize|Résum/i);
      expect(menuText).not.toMatch(/Reformat HTML|Reformater HTML/i);
      expect(menuText).not.toMatch(/(?:^|\n)\s*(?:Tone|Ton)\s*(?:\n|$)/i);
      expect(menuText).not.toMatch(/(?:^|\n)\s*(?:Translate|Traduire)\s*(?:\n|$)/i);
      expect(menuText).not.toMatch(/(?:^|\n)\s*(?:Help|Aide)\s*(?:\n|$)/i);

      const menuScreenshot = path.join(
        screenshotDirectory,
        'ai-ckeditor-article-authorized-menu-desktop.png',
      );
      await page.screenshot({
        path: menuScreenshot,
        fullPage: true,
      });

      const title = `Agency AI CKEditor proof ${Date.now()}`;
      await page.locator('input[name="title[0][value]"]').fill(title);
      await editable.fill('Contenu éditorial saisi sans provider AI configuré.');
      await page.locator('#edit-submit').click();
      await page.waitForLoadState('domcontentloaded');
      await expect(
        page.getByRole('heading', {
          name: title,
          level: 1,
        }),
      ).toBeVisible({ timeout: 15_000 });

      const savedScreenshot = path.join(
        screenshotDirectory,
        'ai-ckeditor-article-normal-save-desktop.png',
      );
      await page.screenshot({
        path: savedScreenshot,
        fullPage: true,
      });

      expect(consoleErrors, 'No browser console error is allowed.').toEqual([]);
      expect(pageErrors, 'No uncaught browser page error is allowed.').toEqual([]);
      expect(http5xx, 'No same-origin HTTP 5xx response is allowed.').toEqual([]);
      expect(failedRequests, 'No same-origin browser request may fail.').toEqual([]);

      const evidence = {
        schema_version: 1,
        contract: 'ai-ckeditor-article-authenticated',
        issue: 380,
        actor: 'ephemeral content_editor',
        negative_actor: 'ephemeral editor without use ai ckeditor',
        result: 'PASS',
        checks: {
          article_form: 'PASS',
          ckeditor_loaded: 'PASS',
          authorized_ai_button: 'PASS',
          authorized_menu_subset: 'PASS',
          unauthorized_ai_button_absent: 'PASS',
          normal_save_without_provider: 'PASS',
          console: 'PASS',
          network: 'PASS',
        },
        screenshots: [
          'screenshots/ai-ckeditor-article-no-permission-desktop.png',
          'screenshots/ai-ckeditor-article-authorized-menu-desktop.png',
          'screenshots/ai-ckeditor-article-normal-save-desktop.png',
        ],
        generated_at: new Date().toISOString(),
      };

      await writeFile(
        path.join(evidenceDirectory, 'ai-ckeditor-authenticated.json'),
        `${JSON.stringify(evidence, null, 2)}\n`,
        'utf8',
      );
    }
    finally {
      try {
        deleteEphemeralFixtures();
      }
      catch {
        // The DDEV project is disposable and the runner cleanup remains authoritative.
      }
    }
  });
});
