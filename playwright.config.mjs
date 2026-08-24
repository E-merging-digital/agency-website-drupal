import { defineConfig } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const httpUsername = process.env.PLAYWRIGHT_HTTP_USERNAME;
const httpPassword = process.env.PLAYWRIGHT_HTTP_PASSWORD;
const protectedTarget = Boolean(httpUsername || httpPassword);

if (!baseURL) {
  throw new Error(
    'PLAYWRIGHT_BASE_URL is required. Use `npm run browser:validate` to detect DDEV automatically.',
  );
}

if ((httpUsername && !httpPassword) || (!httpUsername && httpPassword)) {
  throw new Error(
    'PLAYWRIGHT_HTTP_USERNAME and PLAYWRIGHT_HTTP_PASSWORD must be supplied together.',
  );
}

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
    ignoreHTTPSErrors: true,
    httpCredentials: protectedTarget
      ? {
          username: httpUsername,
          password: httpPassword,
        }
      : undefined,
    screenshot: 'only-on-failure',
    // A Playwright trace can contain request headers. Never retain one for a
    // Basic-Auth-protected target because Authorization is sensitive.
    trace: protectedTarget ? 'off' : 'retain-on-failure',
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
