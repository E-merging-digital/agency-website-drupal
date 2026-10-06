import { expect, test } from '@playwright/test';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';

const enabled = process.env.CANVAS_AI_PROVIDER_PROOF === '1';
const adminLoginUrl = process.env.CANVAS_AI_ADMIN_LOGIN_URL;
const editorPath = '/canvas/editor/canvas_page/52600000-0000-4000-8000-000000000001';
const prompt = 'Using the page builder tool, place the existing approved Hero, Trust list and CTA components at the bottom of the page in that order. Do not create any new component.';
const approved = [
  'emerging_digital:hero',
  'emerging_digital:trust-list',
  'emerging_digital:cta',
];
const artifactDir = path.resolve('artifacts/canvas-ai-provider-proof');

async function findPreviewFrame(page) {
  for (const frame of page.frames()) {
    const count = await frame.locator('[data-ed-component]').count().catch(() => 0);
    if (count >= 3) {
      return frame;
    }
  }
  return null;
}

async function componentIds(frame) {
  return frame.locator('[data-ed-component]').evaluateAll((elements) =>
    elements.map((element) => element.getAttribute('data-ed-component')),
  );
}

async function openAiChat(page) {
  let chat = page.locator('deep-chat');
  if (await chat.count()) {
    return chat.first();
  }

  const candidates = [
    page.getByRole('button', { name: /AI|assistant/i }),
    page.locator('button[aria-label*="AI" i]'),
    page.locator('button[title*="AI" i]'),
  ];
  for (const candidate of candidates) {
    if (await candidate.count()) {
      await candidate.first().click();
      chat = page.locator('deep-chat');
      await expect(chat.first()).toBeVisible({ timeout: 10_000 });
      return chat.first();
    }
  }

  throw new Error('Canvas AI chat trigger/deep-chat element is unavailable.');
}

test.describe('bounded Canvas AI provider proof', () => {
  test.skip(!enabled, 'Provider-backed proof runs only through the trusted #530 route.');

  test('uses only approved SDCs through the Page Builder path', async ({ page }) => {
    test.setTimeout(180_000);
    await mkdir(artifactDir, { recursive: true });

    const evidence = {
      status: 'FAIL',
      failure_phase: 'bootstrap',
      prompt,
      expected_order: approved,
      canvas_endpoint_requests: 0,
      canvas_endpoint_status: null,
      initial_component_ids: [],
      final_component_ids: [],
      added_component_ids: [],
      forbidden_agent_signal: false,
      console_errors: [],
      page_errors: [],
    };

    page.on('console', (message) => {
      if (message.type() === 'error') {
        evidence.console_errors.push(message.text());
      }
    });
    page.on('pageerror', (error) => {
      evidence.page_errors.push(error.message);
    });
    page.on('request', (request) => {
      if (
        request.method() === 'POST'
        && new URL(request.url()).pathname === '/admin/api/canvas/ai'
      ) {
        evidence.canvas_endpoint_requests += 1;
      }
    });

    try {
      if (!adminLoginUrl) {
        throw new Error('CANVAS_AI_ADMIN_LOGIN_URL is required by the trusted route.');
      }

      evidence.failure_phase = 'authentication';
      const loginResponse = await page.goto(adminLoginUrl, { waitUntil: 'domcontentloaded' });
      expect(loginResponse?.status()).toBeLessThan(400);

      evidence.failure_phase = 'editor';
      const editorResponse = await page.goto(editorPath, { waitUntil: 'domcontentloaded' });
      expect(editorResponse?.status()).toBe(200);

      await expect.poll(async () => Boolean(await findPreviewFrame(page)), {
        timeout: 30_000,
      }).toBe(true);
      const preview = await findPreviewFrame(page);
      expect(preview).not.toBeNull();

      evidence.initial_component_ids = await componentIds(preview);
      expect(evidence.initial_component_ids).toEqual(approved);

      evidence.failure_phase = 'ai_chat';
      const chat = await openAiChat(page);
      await expect(chat).toBeVisible();

      const aiResponsePromise = page.waitForResponse(
        (response) => response.request().method() === 'POST'
          && new URL(response.url()).pathname === '/admin/api/canvas/ai',
        { timeout: 120_000 },
      );

      const submitted = await chat.evaluate((element, message) => {
        if (typeof element.submitUserMessage !== 'function') {
          return false;
        }
        element.submitUserMessage({ text: message });
        return true;
      }, prompt);
      expect(submitted).toBe(true);

      const aiResponse = await aiResponsePromise;
      evidence.canvas_endpoint_status = aiResponse.status();
      expect(aiResponse.status()).toBe(200);

      evidence.failure_phase = 'composition';
      await expect.poll(async () => {
        const livePreview = await findPreviewFrame(page);
        return livePreview ? (await componentIds(livePreview)).length : 0;
      }, {
        timeout: 120_000,
        intervals: [1_000, 2_000, 5_000],
      }).toBe(6);

      const finalPreview = await findPreviewFrame(page);
      expect(finalPreview).not.toBeNull();
      evidence.final_component_ids = await componentIds(finalPreview);
      evidence.added_component_ids = evidence.final_component_ids.slice(
        evidence.initial_component_ids.length,
      );

      expect(evidence.added_component_ids).toEqual(approved);
      expect(
        evidence.final_component_ids.every((component) => approved.includes(component)),
      ).toBe(true);
      expect(evidence.canvas_endpoint_requests).toBe(1);

      const chatMessages = await chat.evaluate((element) =>
        typeof element.getMessages === 'function' ? element.getMessages() : [],
      );
      const chatText = JSON.stringify(chatMessages);
      evidence.forbidden_agent_signal = /Component Agent|Generate a component|Code Component/i.test(chatText);
      expect(evidence.forbidden_agent_signal).toBe(false);
      expect(evidence.console_errors).toEqual([]);
      expect(evidence.page_errors).toEqual([]);

      evidence.failure_phase = 'visual';
      await page.screenshot({
        path: path.join(artifactDir, 'canvas-ai-provider-desktop.png'),
        fullPage: true,
      });
      await page.setViewportSize({ width: 390, height: 844 });
      await page.screenshot({
        path: path.join(artifactDir, 'canvas-ai-provider-mobile.png'),
        fullPage: true,
      });

      evidence.status = 'PASS';
      evidence.failure_phase = 'none';
    }
    catch (error) {
      evidence.error = error instanceof Error ? error.message : String(error);
      throw error;
    }
    finally {
      await writeFile(
        path.join(artifactDir, 'result.json'),
        `${JSON.stringify(evidence, null, 2)}\n`,
        'utf8',
      );
    }
  });
});
