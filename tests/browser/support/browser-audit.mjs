import { test as base, expect } from '@playwright/test';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';

const artifactRoot = path.resolve('artifacts/browser-validation');
const ga4MeasurementId = 'G-K5TDNZCPTY';

function isSameOrigin(url, baseURL) {
  try {
    return new URL(url).origin === new URL(baseURL).origin;
  }
  catch {
    return false;
  }
}

function isGoogleAnalyticsRequest(url) {
  try {
    const parsed = new URL(url);
    const hostname = parsed.hostname.toLowerCase();
    return hostname === 'www.googletagmanager.com'
      || hostname.endsWith('.googletagmanager.com')
      || hostname === 'www.google-analytics.com'
      || hostname.endsWith('.google-analytics.com')
      || parsed.pathname.includes('/collect');
  }
  catch {
    return false;
  }
}

function requestIdentityKey({ method, resourceType, url }) {
  return `${method}\u0000${resourceType}\u0000${url}`;
}

function isPreprodBasicAuthContext(baseURL) {
  try {
    return new URL(baseURL).hostname === 'preprod.emergingdigital.be'
      && Boolean(process.env.BROWSER_VALIDATION_HTTP_USERNAME)
      && Boolean(process.env.BROWSER_VALIDATION_HTTP_PASSWORD);
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
        analytics: 'NOT_RUN',
      },
      consoleErrors: [],
      consoleWarnings: [],
      pageErrors: [],
      unexpectedHttp4xx: [],
      http5xx: [],
      failedRequests: [],
      reconciledRequestAborts: [],
      analyticsRequests: [],
      analyticsMeasurementRequests: [],
      screenshot: null,
    };

    const preprodBasicAuthContext = isPreprodBasicAuthContext(baseURL);
    const successfulHttp200RequestKeys = new Set();
    const pendingAbortReconciliations = [];

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

    const onRequest = (request) => {
      const url = request.url();
      const entry = {
        method: request.method(),
        resourceType: request.resourceType(),
        url,
      };

      if (isGoogleAnalyticsRequest(url)) {
        audit.analyticsRequests.push(entry);
      }
      if (url.includes(ga4MeasurementId)) {
        audit.analyticsMeasurementRequests.push(entry);
      }
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

      if (response.status() === 200) {
        const key = requestIdentityKey(entry);

        if (preprodBasicAuthContext && successfulHttp200RequestKeys.has(key)) {
          const pendingIndex = pendingAbortReconciliations.findIndex(
            (pending) => pending.key === key,
          );

          if (pendingIndex !== -1) {
            const [{ entry: failedEntry }] = pendingAbortReconciliations.splice(
              pendingIndex,
              1,
            );
            const failedIndex = audit.failedRequests.indexOf(failedEntry);

            if (failedIndex !== -1) {
              audit.failedRequests.splice(failedIndex, 1);
              audit.reconciledRequestAborts.push(failedEntry);
            }
          }
        }

        successfulHttp200RequestKeys.add(key);
      }

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

      const entry = {
        method: request.method(),
        resourceType: request.resourceType(),
        url: request.url(),
        errorText: request.failure()?.errorText ?? 'Unknown request failure',
      };

      audit.failedRequests.push(entry);

      if (
        preprodBasicAuthContext
        && entry.errorText === 'net::ERR_ABORTED'
      ) {
        const key = requestIdentityKey(entry);

        if (successfulHttp200RequestKeys.has(key)) {
          pendingAbortReconciliations.push({ key, entry });
        }
      }
    };

    page.on('console', onConsole);
    page.on('pageerror', onPageError);
    page.on('request', onRequest);
    page.on('response', onResponse);
    page.on('requestfailed', onRequestFailed);

    await use(audit);

    page.off('console', onConsole);
    page.off('pageerror', onPageError);
    page.off('request', onRequest);
    page.off('response', onResponse);
    page.off('requestfailed', onRequestFailed);

    const project = testInfo.project.name;
    const result = testInfo.status === testInfo.expectedStatus ? 'PASS' : 'FAIL';
    const evidenceDirectory = path.join(artifactRoot, 'evidence');
    await mkdir(evidenceDirectory, { recursive: true });

    const evidence = {
      schema_version: 2,
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
      reconciled_request_aborts: audit.reconciledRequestAborts.length,
      google_analytics_requests: audit.analyticsRequests.length,
      ga4_measurement_id_requests: audit.analyticsMeasurementRequests.length,
      details: {
        console_errors: audit.consoleErrors,
        console_warnings: audit.consoleWarnings,
        page_errors: audit.pageErrors,
        unexpected_http_4xx: audit.unexpectedHttp4xx,
        http_5xx: audit.http5xx,
        failed_requests: audit.failedRequests,
        reconciled_request_aborts: audit.reconciledRequestAborts,
        google_analytics_requests: audit.analyticsRequests,
        ga4_measurement_id_requests: audit.analyticsMeasurementRequests,
      },
      screenshot: audit.screenshot,
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
