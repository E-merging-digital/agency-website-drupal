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
  await expect(
    page.getByRole('heading', {
      name: username,
      level: 1,
    }),
    'A successful Drupal login must land on the authenticated user page.',
  ).toBeVisible({ timeout: 15_000 });
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

function automatorTextSuggestionButton(page) {
  return page.getByRole('button', {
    name: /Automator Text Suggestion/i,
  }).first();
}

function automatorAltTextButton(page) {
  return page.getByRole('button', {
    name: /Automator Alt Text/i,
  }).first();
}

test.describe('Authenticated Article AI authoring proof', () => {
  test('Article exposes only explicit, human-triggered AI authoring controls', async ({
    page,
    baseURL,
  }, testInfo) => {
    test.setTimeout(90_000);
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

      const manualAutomatorButton = automatorTextSuggestionButton(page);
      await expect(
        manualAutomatorButton,
        'The Article short description must expose an explicit manual Automator action.',
      ).toBeVisible();
      await expect(
        editable,
        'The editor must remain manually editable without an AI provider.',
      ).toBeEditable();

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
      await page.keyboard.press('Escape');

      const imageInput = page.locator('input[type="file"]').first();
      await expect(imageInput).toBeVisible();
      await imageInput.setInputFiles({
        name: 'issue-381-alt-proof.png',
        mimeType: 'image/png',
        buffer: Buffer.from(
          'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZPpAAAAAASUVORK5CYII=',
          'base64',
        ),
      });

      const altInput = page.locator('input[name="field_feature_image[0][alt]"]');
      await expect(
        altInput,
        'Uploading the primary image must expose its editable alt-text field.',
      ).toBeVisible({ timeout: 15_000 });
      await expect(
        automatorAltTextButton(page),
        'The primary image must expose an explicit manual alt-text Automator action.',
      ).toBeVisible({ timeout: 15_000 });
      await expect(
        altInput,
        'Image alt text must remain manually editable without an AI provider.',
      ).toBeEditable();
      await altInput.fill('Illustration de preuve pour le texte alternatif.');

      const imageAltScreenshot = path.join(
        screenshotDirectory,
        'ai-automators-article-image-alt-desktop.png',
      );
      await page.screenshot({
        path: imageAltScreenshot,
        fullPage: true,
      });

      const title = `Agency AI authoring proof ${Date.now()}`;
      await page.locator('input[name="title[0][value]"]').fill(title);
      await editable.fill('Contenu éditorial saisi sans provider AI configuré.');
      await page.locator('#edit-submit').click();
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
        contract: 'article-authenticated-ai-authoring',
        issues: [380, 381],
        actor: 'ephemeral content_editor',
        negative_actor: 'ephemeral editor without use ai ckeditor',
        result: 'PASS',
        checks: {
          article_form: 'PASS',
          ckeditor_loaded: 'PASS',
          authorized_ai_button: 'PASS',
          authorized_menu_subset: 'PASS',
          unauthorized_ai_button_absent: 'PASS',
          manual_short_description_automator_action: 'PASS',
          manual_feature_image_alt_automator_action: 'PASS',
          manual_editing_without_provider: 'PASS',
          manual_image_alt_without_provider: 'PASS',
          normal_save_without_provider: 'PASS',
          console: 'PASS',
          network: 'PASS',
        },
        screenshots: [
          'screenshots/ai-ckeditor-article-no-permission-desktop.png',
          'screenshots/ai-ckeditor-article-authorized-menu-desktop.png',
          'screenshots/ai-automators-article-image-alt-desktop.png',
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
