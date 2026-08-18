import { mkdir, writeFile } from 'node:fs/promises';
import { performance } from 'node:perf_hooks';
import path from 'node:path';
import { test, expect } from '@playwright/test';

const artifactRoot = path.resolve(
  'artifacts/browser-validation/ai-playwright-comparison',
);

const targets = [
  {
    id: 'fr',
    path: '/fr/mentions-legales',
    expectedTitle: 'Mentions légales | E-MERGING DIGITAL',
    expectedHeading: 'Mentions légales',
    expectedLang: 'fr',
  },
  {
    id: 'en',
    path: '/en/legal-notices',
    expectedTitle: 'Legal Notices | E-MERGING DIGITAL',
    expectedHeading: 'Legal Notices',
    expectedLang: 'en',
  },
];

function isSameOrigin(url, baseURL) {
  try {
    return new URL(url).origin === new URL(baseURL).origin;
  }
  catch {
    return false;
  }
}

function errorMessage(error) {
  return error instanceof Error ? error.message : String(error);
}

async function inspectTarget(page, baseURL, target, testInfo) {
  const telemetry = {
    console_errors: [],
    console_warnings: [],
    page_errors: [],
    unexpected_http_4xx: [],
    http_5xx: [],
    failed_requests: [],
  };

  const onConsole = (message) => {
    const entry = {
      type: message.type(),
      text: message.text(),
    };
    if (message.type() === 'error') {
      telemetry.console_errors.push(entry);
    }
    else if (message.type() === 'warning') {
      telemetry.console_warnings.push(entry);
    }
  };
  const onPageError = (error) => {
    telemetry.page_errors.push({
      name: error.name,
      message: error.message,
    });
  };
  const onResponse = (response) => {
    if (!isSameOrigin(response.url(), baseURL)) {
      return;
    }
    const entry = {
      status: response.status(),
      method: response.request().method(),
      resource_type: response.request().resourceType(),
      url: response.url(),
    };
    if (response.status() >= 500) {
      telemetry.http_5xx.push(entry);
    }
    else if (response.status() >= 400) {
      telemetry.unexpected_http_4xx.push(entry);
    }
  };
  const onRequestFailed = (request) => {
    if (!isSameOrigin(request.url(), baseURL)) {
      return;
    }
    telemetry.failed_requests.push({
      method: request.method(),
      resource_type: request.resourceType(),
      url: request.url(),
      error_text: request.failure()?.errorText ?? 'Unknown request failure',
    });
  };

  page.on('console', onConsole);
  page.on('pageerror', onPageError);
  page.on('response', onResponse);
  page.on('requestfailed', onRequestFailed);

  const failures = [];
  const started = performance.now();
  let gotoDurationMs = null;
  let responseStatus = null;
  let finalUrl = null;
  let title = null;
  let lang = null;
  let h1Count = null;
  let h1Visible = false;
  let visibleTextLength = null;
  let horizontalOverflow = null;
  let screenshot = null;

  try {
    const gotoStarted = performance.now();
    const response = await page.goto(target.path, {
      waitUntil: 'domcontentloaded',
    });
    gotoDurationMs = Math.round(performance.now() - gotoStarted);
    responseStatus = response?.status() ?? null;
    finalUrl = page.url();

    if (!response) {
      failures.push(`${target.id}: navigation returned no response`);
    }
    else if (response.status() >= 400) {
      failures.push(`${target.id}: HTTP ${response.status()} on ${target.path}`);
    }

    title = await page.title();
    lang = await page.locator('html').getAttribute('lang');
    h1Count = await page.locator('h1').count();
    h1Visible = await page.getByRole('heading', {
      name: target.expectedHeading,
      level: 1,
      exact: true,
    }).isVisible();

    const visibleText = await page.locator('body').innerText();
    visibleTextLength = visibleText.length;
    horizontalOverflow = await page.evaluate(() => (
      document.documentElement.scrollWidth > window.innerWidth + 1
    ));

    if (title !== target.expectedTitle) {
      failures.push(
        `${target.id}: title mismatch (${JSON.stringify(title)})`,
      );
    }
    if (lang !== target.expectedLang) {
      failures.push(`${target.id}: html lang mismatch (${JSON.stringify(lang)})`);
    }
    if (h1Count !== 1) {
      failures.push(`${target.id}: expected exactly one H1, got ${h1Count}`);
    }
    if (!h1Visible) {
      failures.push(`${target.id}: expected H1 is not visible`);
    }
    if (!visibleTextLength) {
      failures.push(`${target.id}: visible text is empty`);
    }
    if (horizontalOverflow) {
      failures.push(`${target.id}: horizontal overflow detected`);
    }

    const screenshotFile = `legal-${target.id}-${testInfo.project.name}.png`;
    const screenshotPath = path.join(artifactRoot, screenshotFile);
    await page.screenshot({
      path: screenshotPath,
      fullPage: true,
    });
    await testInfo.attach(`ai-playwright-comparison-${target.id}`, {
      path: screenshotPath,
      contentType: 'image/png',
    });
    screenshot = screenshotFile;
  }
  catch (error) {
    failures.push(`${target.id}: ${errorMessage(error)}`);
  }
  finally {
    page.off('console', onConsole);
    page.off('pageerror', onPageError);
    page.off('response', onResponse);
    page.off('requestfailed', onRequestFailed);
  }

  if (telemetry.console_errors.length > 0) {
    failures.push(`${target.id}: browser console errors detected`);
  }
  if (telemetry.page_errors.length > 0) {
    failures.push(`${target.id}: uncaught page errors detected`);
  }
  if (telemetry.unexpected_http_4xx.length > 0) {
    failures.push(`${target.id}: unexpected same-origin HTTP 4xx detected`);
  }
  if (telemetry.http_5xx.length > 0) {
    failures.push(`${target.id}: same-origin HTTP 5xx detected`);
  }
  if (telemetry.failed_requests.length > 0) {
    failures.push(`${target.id}: failed same-origin requests detected`);
  }

  return {
    evidence: {
      id: target.id,
      path: target.path,
      response_status: responseStatus,
      final_url: finalUrl,
      title,
      document_lang: lang,
      h1_count: h1Count,
      expected_h1_visible: h1Visible,
      visible_text_length: visibleTextLength,
      horizontal_overflow: horizontalOverflow,
      goto_duration_ms: gotoDurationMs,
      inspection_duration_ms: Math.round(performance.now() - started),
      screenshot,
      ...telemetry,
    },
    failures,
  };
}

test.describe('AI Playwright #456 independent comparison', () => {
  test('same legal pages are independently observable', async ({
    page,
    baseURL,
  }, testInfo) => {
    if (!baseURL) {
      throw new Error('Playwright baseURL is required for comparison proof.');
    }

    await mkdir(artifactRoot, { recursive: true });
    const failures = [];
    const pages = [];
    const started = performance.now();

    for (const target of targets) {
      const result = await inspectTarget(page, baseURL, target, testInfo);
      pages.push(result.evidence);
      failures.push(...result.failures);
    }

    const evidence = {
      schema_version: 1,
      comparison: 'ai-playwright-456',
      project: testInfo.project.name,
      viewport: page.viewportSize(),
      base_url: baseURL,
      pages,
      total_duration_ms: Math.round(performance.now() - started),
      result: failures.length === 0 ? 'PASS' : 'FAIL',
      failures,
      generated_at: new Date().toISOString(),
    };

    await writeFile(
      path.join(artifactRoot, `${testInfo.project.name}.json`),
      `${JSON.stringify(evidence, null, 2)}\n`,
      'utf8',
    );

    expect(
      failures,
      `Independent AI Playwright comparison failed:\n${failures.join('\n')}`,
    ).toEqual([]);
  });
});
