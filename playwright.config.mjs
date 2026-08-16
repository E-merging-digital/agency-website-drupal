import { defineConfig } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;

if (!baseURL) {
  throw new Error(
    'PLAYWRIGHT_BASE_URL is required. Use `npm run browser:validate` to detect DDEV automatically.',
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
