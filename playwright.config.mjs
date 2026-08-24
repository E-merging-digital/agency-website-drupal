import { defineConfig } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const httpUsername = process.env.BROWSER_VALIDATION_HTTP_USERNAME ?? '';
const httpPassword = process.env.BROWSER_VALIDATION_HTTP_PASSWORD ?? '';

if (!baseURL) {
  throw new Error(
    'PLAYWRIGHT_BASE_URL is required. Use `npm run browser:validate` to detect DDEV automatically.',
  );
}

if ((httpUsername && !httpPassword) || (!httpUsername && httpPassword)) {
  throw new Error(
    'BROWSER_VALIDATION_HTTP_USERNAME and BROWSER_VALIDATION_HTTP_PASSWORD must be provided together.',
  );
}

const httpCredentials = httpUsername && httpPassword
  ? {
      username: httpUsername,
      password: httpPassword,
    }
  : undefined;

export default defineConfig({
  testDir: './tests/browser',
  timeout: 30_000,
  expect: {
    timeout: 5_000,
  },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  outputDir: 'artifacts/browser-validation/test-results',
  reporter: [['line']],
  use: {
    baseURL,
    httpCredentials,
    ignoreHTTPSErrors: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    video: 'off',
  },
  projects: [
    {
      name: 'desktop',
      use: {
        browserName: 'chromium',
        viewport: {
          width: 1440,
          height: 900,
        },
      },
    },
    {
      name: 'mobile',
      use: {
        browserName: 'chromium',
        viewport: {
          width: 390,
          height: 844,
        },
        isMobile: true,
        hasTouch: true,
      },
    },
  ],
});
