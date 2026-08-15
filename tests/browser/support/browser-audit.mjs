import { test as base, expect } from '@playwright/test';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';

const artifactRoot = path.resolve('artifacts/browser-validation');

function isSameOrigin(url, baseURL) {
  try {
    return new URL(url).origin === new URL(baseURL).origin;
  }
  catch {
    return false;
  }
}

export const test = base.extend({
  audit: async ({ page, baseURL }, use, testInfo) => {
    if (!baseURL) {
      throw new Error('Playwright baseURL is required for browser auditing.');
    }

    const audit = {
      checks: {
        functional: 'NOT_RUN',
        dom: 'NOT_RUN',
        visual: 'NOT_RUN',
        console: 'NOT_RUN',
        network: 'NOT_RUN',
      },
      consoleErrors: [],
      consoleWarnings: [],
      pageErrors: [],
      unexpectedHttp4xx: [],
      http5xx: [],
      failedRequests: [],
    };

    const onConsole = (message) => {
      const entry = {
        type: message.type(),
        text: message.text(),
      };

      if (message.type() === 'error') {
        audit.consoleErrors.push(entry);
      }
      else if (message.type() === 'warning') {
        audit.consoleWarnings.push(entry);
      }
    };

    const onPageError = (error) => {
      audit.pageErrors.push({
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
        resourceType: response.request().resourceType(),
        url: response.url(),
      };

      if (response.status() >= 500) {
        audit.http5xx.push(entry);
      }
      else if (response.status() >= 400) {
        audit.unexpectedHttp4xx.push(entry);
      }
    };

    const onRequestFailed = (request) => {
      if (!isSameOrigin(request.url(), baseURL)) {
        return;
      }

      audit.failedRequests.push({
        method: request.method(),
        resourceType: request.resourceType(),
        url: request.url(),
        errorText: request.failure()?.errorText ?? 'Unknown request failure',
      });
    };

    page.on('console', onConsole);
    page.on('pageerror', onPageError);
    page.on('response', onResponse);
    page.on('requestfailed', onRequestFailed);

    await use(audit);

    page.off('console', onConsole);
    page.off('pageerror', onPageError);
    page.off('response', onResponse);
    page.off('requestfailed', onRequestFailed);

    const project = testInfo.project.name;
    const result = testInfo.status === testInfo.expectedStatus ? 'PASS' : 'FAIL';
    const evidenceDirectory = path.join(artifactRoot, 'evidence');
    await mkdir(evidenceDirectory, { recursive: true });

    const evidence = {
      schema_version: 1,
      project,
      result,
      status: testInfo.status,
      base_url: baseURL,
      checks: audit.checks,
      console_errors: audit.consoleErrors.length,
      console_warnings: audit.consoleWarnings.length,
      page_errors: audit.pageErrors.length,
      unexpected_http_4xx: audit.unexpectedHttp4xx.length,
      http_5xx: audit.http5xx.length,
      failed_requests: audit.failedRequests.length,
      details: {
        console_errors: audit.consoleErrors,
        console_warnings: audit.consoleWarnings,
        page_errors: audit.pageErrors,
        unexpected_http_4xx: audit.unexpectedHttp4xx,
        http_5xx: audit.http5xx,
        failed_requests: audit.failedRequests,
      },
      screenshot: `screenshots/public-blog-${project}.png`,
      trace: result === 'FAIL' ? 'See test-results trace.zip for this project.' : null,
    };

    await writeFile(
      path.join(evidenceDirectory, `${project}.json`),
      `${JSON.stringify(evidence, null, 2)}\n`,
      'utf8',
    );
  },
});

export { expect };
