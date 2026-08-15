import { execFileSync, spawnSync } from 'node:child_process';
import { mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import http from 'node:http';
import https from 'node:https';
import path from 'node:path';
import process from 'node:process';

const artifactRoot = path.resolve('artifacts/browser-validation');
const resultPath = path.join(artifactRoot, 'result.json');
const contractPath = path.resolve(
  process.env.BROWSER_VALIDATION_CONTRACT
    ?? 'tests/browser/contracts/public-blog.json',
);
const expectedProjects = ['desktop', 'mobile'];

function findUrl(value) {
  if (typeof value === 'string') {
    return /^https?:\/\//.test(value) ? value : null;
  }

  if (Array.isArray(value)) {
    const urls = value.map(findUrl).filter(Boolean);
    return urls.find((url) => url.startsWith('https://')) ?? urls[0] ?? null;
  }

  if (!value || typeof value !== 'object') {
    return null;
  }

  for (const [key, entry] of Object.entries(value)) {
    const normalized = key.toLowerCase().replaceAll('-', '_');
    if (
      ['primary_url', 'primaryurl'].includes(normalized)
      && typeof entry === 'string'
      && /^https?:\/\//.test(entry)
    ) {
      return entry;
    }
  }

  for (const entry of Object.values(value)) {
    const found = findUrl(entry);
    if (found) {
      return found;
    }
  }

  return null;
}

function detectDdevUrl() {
  const explicit =
    process.env.BROWSER_VALIDATION_BASE_URL
    ?? process.env.PLAYWRIGHT_BASE_URL
    ?? process.env.DDEV_PRIMARY_URL;

  if (explicit) {
    return explicit.replace(/\/+$/, '');
  }

  let output;
  try {
    output = execFileSync('ddev', ['describe', '-j'], {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    });
  }
  catch (error) {
    const stderr = error?.stderr?.toString().trim();
    throw new Error(
      [
        'Impossible de détecter l’URL DDEV.',
        'Exécutez `ddev start`, ou définissez BROWSER_VALIDATION_BASE_URL.',
        stderr ? `DDEV: ${stderr}` : null,
      ].filter(Boolean).join('\n'),
    );
  }

  let parsed;
  try {
    parsed = JSON.parse(output);
  }
  catch {
    const match = output.match(/https?:\/\/[^\s"'\\]+/);
    if (match) {
      return match[0].replace(/\/+$/, '');
    }

    throw new Error('La sortie JSON de `ddev describe -j` est illisible.');
  }

  const url = findUrl(parsed);
  if (!url) {
    throw new Error(
      'Aucune URL HTTP(S) n’a été trouvée dans `ddev describe -j`.',
    );
  }

  return url.replace(/\/+$/, '');
}

function requestStatus(url) {
  return new Promise((resolve, reject) => {
    const target = new URL(url);
    const client = target.protocol === 'https:' ? https : http;
    const request = client.get(
      target,
      {
        headers: {
          'user-agent': 'agency-browser-validation-readiness/1',
        },
        rejectUnauthorized: false,
      },
      (response) => {
        response.resume();
        resolve(response.statusCode ?? 0);
      },
    );

    request.setTimeout(5_000, () => {
      request.destroy(new Error('Readiness request timed out.'));
    });
    request.on('error', reject);
  });
}

async function waitUntilReady(baseURL, targetPath, timeoutMs = 60_000) {
  const deadline = Date.now() + timeoutMs;
  const url = new URL(targetPath, `${baseURL}/`).toString();
  let lastError = null;

  while (Date.now() < deadline) {
    try {
      const status = await requestStatus(url);
      if (status >= 200 && status < 400) {
        return;
      }
      lastError = new Error(`HTTP ${status} pour ${url}`);
    }
    catch (error) {
      lastError = error;
    }

    await new Promise((resolve) => setTimeout(resolve, 1_000));
  }

  throw new Error(
    `Drupal n’est pas prêt après ${timeoutMs / 1000}s: ${lastError?.message ?? url}`,
  );
}

function playwrightExecutable() {
  return process.platform === 'win32'
    ? path.resolve('node_modules/.bin/playwright.cmd')
    : path.resolve('node_modules/.bin/playwright');
}

async function readJsonIfPresent(filePath) {
  try {
    return JSON.parse(await readFile(filePath, 'utf8'));
  }
  catch {
    return null;
  }
}

function combineCheck(evidence, check) {
  if (evidence.length === 0) {
    return 'NOT_RUN';
  }

  const values = evidence.map((entry) => entry?.checks?.[check] ?? 'NOT_RUN');
  if (values.includes('FAIL')) {
    return 'FAIL';
  }
  if (values.every((value) => value === 'PASS')) {
    return 'PASS';
  }
  return 'NOT_RUN';
}

async function writeNotRun(contract, message) {
  await mkdir(artifactRoot, { recursive: true });
  const result = {
    schema_version: 1,
    contract: contract.id,
    target: contract.target,
    result: 'NOT_RUN',
    reason: message,
    generated_at: new Date().toISOString(),
  };
  await writeFile(resultPath, `${JSON.stringify(result, null, 2)}\n`, 'utf8');
  console.error(JSON.stringify(result, null, 2));
}

const contract = JSON.parse(await readFile(contractPath, 'utf8'));
await rm(artifactRoot, { recursive: true, force: true });
await mkdir(artifactRoot, { recursive: true });

let baseURL;
try {
  baseURL = detectDdevUrl();
  await waitUntilReady(baseURL, contract.target);
}
catch (error) {
  await writeNotRun(contract, error.message);
  process.exit(2);
}

const executable = playwrightExecutable();
const extraArgs = process.argv.slice(2);
const child = spawnSync(
  executable,
  ['test', '--config=playwright.config.mjs', ...extraArgs],
  {
    env: {
      ...process.env,
      PLAYWRIGHT_BASE_URL: baseURL,
      BROWSER_VALIDATION_CONTRACT: contractPath,
    },
    stdio: 'inherit',
    shell: process.platform === 'win32',
  },
);

if (child.error) {
  await writeNotRun(
    contract,
    [
      child.error.message,
      'Exécutez `npm install` puis `npm run browser:install` avant la validation.',
    ].join('\n'),
  );
  process.exit(2);
}

const evidence = [];
for (const project of expectedProjects) {
  const entry = await readJsonIfPresent(
    path.join(artifactRoot, 'evidence', `${project}.json`),
  );
  if (entry) {
    evidence.push(entry);
  }
}

const ranProjects = new Set(evidence.map((entry) => entry.project));
const missingProjects = expectedProjects.filter(
  (project) => !ranProjects.has(project),
);
const overallResult =
  child.status === 0
  && evidence.length > 0
  && evidence.every((entry) => entry.result === 'PASS')
    ? 'PASS'
    : evidence.length === 0
      ? 'NOT_RUN'
      : 'FAIL';

const result = {
  schema_version: 1,
  contract: contract.id,
  target: contract.target,
  actor: contract.actor,
  base_url: baseURL,
  result: overallResult,
  functional: combineCheck(evidence, 'functional'),
  dom: combineCheck(evidence, 'dom'),
  visual_desktop:
    evidence.find((entry) => entry.project === 'desktop')?.checks?.visual
    ?? 'NOT_RUN',
  visual_mobile:
    evidence.find((entry) => entry.project === 'mobile')?.checks?.visual
    ?? 'NOT_RUN',
  console_errors: evidence.reduce(
    (total, entry) => total + entry.console_errors,
    0,
  ),
  console_warnings: evidence.reduce(
    (total, entry) => total + entry.console_warnings,
    0,
  ),
  page_errors: evidence.reduce(
    (total, entry) => total + entry.page_errors,
    0,
  ),
  unexpected_http_4xx: evidence.reduce(
    (total, entry) => total + entry.unexpected_http_4xx,
    0,
  ),
  http_5xx: evidence.reduce(
    (total, entry) => total + entry.http_5xx,
    0,
  ),
  failed_requests: evidence.reduce(
    (total, entry) => total + entry.failed_requests,
    0,
  ),
  missing_projects: missingProjects,
  projects: evidence,
  generated_at: new Date().toISOString(),
};

await writeFile(resultPath, `${JSON.stringify(result, null, 2)}\n`, 'utf8');
console.log(`\nBrowser validation result: ${resultPath}`);
console.log(JSON.stringify(result, null, 2));

process.exit(overallResult === 'PASS' ? 0 : 1);
