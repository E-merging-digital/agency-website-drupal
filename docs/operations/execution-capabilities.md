# Agency execution capabilities

Status: **AUTHORITATIVE CURRENT CAPABILITY REGISTRY**
Repository: `E-merging-digital/agency-website-drupal`
Registry owner: #421
Current lifecycle index: `docs/operations/agency-environment-data-lifecycle.md`
Last materialized: 2026-09-02

## 1. Purpose and interpretation

This registry answers which useful capabilities currently exist, where they execute and which route owns authority.

```text
operator-surface capability != project-executor capability
registered capability != currently online executor
command dispatch != execution authority
implementation authority != execution authority
```

Status vocabulary includes `DESIGN_ONLY`, `SOURCE_IMPLEMENTED`, `SYNTHETICALLY_PROVEN`, `PROVISIONED`, `EXECUTABLE`, `REAL_EXECUTION_PROVEN` and `EXECUTION_PENDING`.

## 2. Current command dispatcher (#922 completed)

#922 is **COMPLETED**. `.github/workflows/agency-command-dispatch.yml` is the single top-level `issue_comment` listener. It classifies exact syntax only; the selected reusable workflow owns authorization.

Current reusable capabilities are exactly seven:

1. `.github/workflows/promote-production.yml`
2. `.github/workflows/production-scheduler-change.yml`
3. `.github/workflows/trusted-editorial-publication.yml`
4. `.github/workflows/trusted-editorial-preprod-candidate.yml`
5. `.github/workflows/trusted-editorial-feature-image.yml`
6. `.github/workflows/preprod-914-governed-successor.yml`
7. `.github/workflows/development-seed-publish.yml`

## 3. Current operational capability index

| Capability | Owner | Status | Current execution surface | Mutation/data boundary |
| --- | --- | --- | --- | --- |
| Immutable code/config build | release system | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | GitHub-hosted | Artifact creation only. |
| Exact PREPROD deploy | release system | `PROVISIONED` / `REAL_EXECUTION_PROVEN` | GitHub-hosted control -> PREPROD | PREPROD code/config only. |
| Single command dispatcher | #922 | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | GitHub-hosted | Syntax routing only. |
| Same-artifact PROD promotion | promotion route | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | GitHub-hosted control -> PROD | Exact approved artifact only. |
| Production scheduler change | scheduler governance | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | GitHub-hosted control -> PROD | Bounded scheduler transition. |
| Governed Article publication | #576 | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | GitHub-hosted control -> PROD Drupal | Bounded Article Entity API mutation. |
| Editorial Candidate PREPROD | #959 / parent #872 | `SOURCE_IMPLEMENTED` / `EXECUTION_PENDING` | GitHub-hosted control -> PREPROD Drupal | Reuses #576 Article contract; FR+EN PREPROD review materialization only; `PROD_WRITE=NONE`; first real #958 proof pending. |
| Governed Article feature image | #584 | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | GitHub-hosted control -> PROD Drupal | Bounded existing Article image mutation. |
| PROD -> PREPROD sanitized DB refresh | #914 / completed #816 | `SOURCE_IMPLEMENTED` / `SYNTHETICALLY_PROVEN` / `REAL_EXECUTION_PROVEN` | PLAN hosted; APPLY hosted control -> direct PROD/PREPROD server route | Raw never on GitHub-hosted; PREPROD activation receives sanitized SQL only. |
| GitHub-hosted metadata-only PLAN | #927 / real proof #937 | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | GitHub-hosted `ubuntu-24.04` | `PLAN_RESULT=PASS`; no DB content/snapshot/transfer/mutation. |
| Controlled server-to-server APPLY | #914 / terminal proof #953 | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | GitHub-hosted control -> PREPROD worker -> direct read-only PROD stream | `COMMITTED`; raw PROD route direct to isolated PREPROD staging; sanitized-only activation. |
| Trusted self-hosted Agency executor | runner capability | `PROVISIONED` | `self-hosted`, `linux`, `x64`, `agency`, `ddev`, `browser` | Authorized trusted DDEV/raw-data surface; availability is a live fact. |
| Development Seed DDEV consumer | #873 | `SOURCE_IMPLEMENTED` / `SYNTHETICALLY_PROVEN` | Local/trusted DDEV consumer | Pull-only; native snapshot/import/convergence; no push. |
| Development Seed publisher/distribution | #956 | `SOURCE_IMPLEMENTED` / `SYNTHETICALLY_PROVEN` / `EXECUTION_PENDING` | GitHub-hosted authority validation -> trusted self-hosted Agency/DDEV -> PREPROD read-only source + fixed seed storage | No PROD; no PREPROD runtime DB write; raw PREPROD never GitHub-hosted; real proof still pending. |

## 4. Self-hosted Agency executor

Agency has a registered runner capability with labels:

```text
self-hosted, linux, x64, agency, ddev, browser
```

Registration/provisioning is durable; availability is dynamic. A conversation must reload live state before relying on current runner availability. The registered surface does not authorize raw PROD or raw PREPROD database material on GitHub-hosted infrastructure.

Current #914 split:

```text
validate-authority = GitHub-hosted ubuntu-24.04
PLAN               = GitHub-hosted ubuntu-24.04 / REAL_EXECUTION_PROVEN
APPLY control plane= GitHub-hosted ubuntu-24.04 / REAL_EXECUTION_PROVEN
APPLY raw route    = CONTROLLED_SERVER_TO_SERVER / PROD -> PREPROD DIRECT
self-hosted runner = AUTHORIZED ALTERNATIVE
```

Current #956 implementation split, pending first real proof:

```text
authority validation = GitHub-hosted ubuntu-24.04 / metadata only
seed generation       = self-hosted, linux, x64, agency, ddev
source                 = CURRENT SANITIZED PREPROD / READ ONLY
storage                = /var/www/agency-preprod/shared/development-seeds
consumer               = ddev pull agency
raw PREPROD on hosted  = NONE
```

Both remain `EXTEND_EXISTING`, not permanent multi-provider architectures.

## 5. Browser, DDEV and developer execution

| Capability | Status | Primary use |
| --- | --- | --- |
| Playwright Test | AVAILABLE on registered executor | Deterministic browser validation/evidence |
| Chromium | AVAILABLE on registered executor | Browser validation |
| Playwright MCP | AVAILABLE on registered executor | Interactive inspection when transport exists |
| Chrome DevTools MCP | AVAILABLE on registered executor | DevTools diagnosis |
| DDEV | AVAILABLE on registered executor/local hosts | Drupal runtime/dependency/Development Seed isolated execution |
| Docker | AVAILABLE on registered executor | DDEV/container execution |

A conversation without direct MCP transport must not conclude that the registered project capability does not exist.

## 6. Governed PROD -> PREPROD refresh (#914 / completed #816)

```text
DECISION = EXTEND_EXISTING
CURRENT_IMPLEMENTATION = #914 governed successor + controlled server-to-server APPLY
PARENT_PROGRAM_816 = CLOSED / COMPLETED
REAL_PLAN = PASS
REAL_END_TO_END_REFRESH = REAL_EXECUTION_PROVEN
REAL_APPLY = COMMITTED / #953
PREPROD_RUNTIME = SANITIZED_DATABASE_ACTIVE_AND_VALIDATED
PROD_ACCESS = READ_ONLY_REQUEST_SCOPED_TRANSIENT
PROD_WRITE = NONE
RAW_PROD_ON_GITHUB_HOSTED = NONE
RAW_PROD_ROUTE = PROD_TO_PREPROD_DIRECT
SANITIZED_ONLY_ACTIVATION = PASS
HUMAN_RECOVERY_REQUIRED = NO
```

### Current PLAN

Authority validation and PLAN run on GitHub-hosted `ubuntu-24.04`. JIT revalidates live main, exact authority and hosted runner environment before transient SSH identities are materialized.

The real PLAN contract remains:

```text
PLAN_RESULT = PASS
PROD_DB_CONTENT_READ = NONE
PROD_SNAPSHOT = NOT_PERFORMED
PROD_DATA_TRANSFER = NONE
PROD_WRITE = NONE
PREPROD_DB_MUTATION = NONE
DATA_ACTIVATION_AUTHORITY = DISABLED
APPLY = SKIPPED
```

### Current controlled APPLY

The GitHub-hosted APPLY job is only a control plane. It stages fixed scripts/identities to PREPROD after JIT and launches a detached request-scoped PREPROD worker. Raw PROD bytes never transit or materialize on GitHub-hosted infrastructure.

```text
GitHub-hosted control plane
-> PREPROD detached root preparation worker
-> pinned read-only PROD snapshot route
-> PROD -> PREPROD direct raw stream
-> request-derived PREPROD staging DB
-> existing root-owned sanitizer + single policy
-> sanitization assertions
-> sanitized SQL
-> raw staging cleanup + absence proof
-> PROD identity/root-stage cleanup + absence proof
-> existing #914 remote-apply-worker.sh
-> backup / maintenance / activation / rollback
-> validation
-> COMMITTED or fail-closed terminal outcome
```

Terminal #953 proves the happy path reaches:

```text
PREPROD_WORKER_OUTCOME = COMMITTED
PREPROD_WORKER_DETAIL = SANITIZED_DATABASE_ACTIVE_AND_VALIDATED
RAW_PROD_ON_GITHUB_HOSTED = NONE
RAW_PROD_ROUTE = PROD_TO_PREPROD_DIRECT
PROD_WRITE = NONE
PROD_READ_IDENTITY = READ_ONLY_REQUEST_SCOPED_TRANSIENT
SANITIZED_ONLY_ACTIVATION = PASS
```

Pre-activation cleanup remains fail-closed. After activation begins, the unchanged #914 worker owns backup and rollback. Proven rollback => `ROLLED_BACK`; unproven rollback => `HUMAN_RECOVERY_REQUIRED` with maintenance ON.

Hard boundaries:

```text
PROD_WRITE = NONE
RAW PROD DATA ON GITHUB-HOSTED = FORBIDDEN
RAW_PROD_IN_GITHUB_ARTIFACT/LOG/TEMP = NONE
PREPROD_LIVE_RUNTIME_RAW_ACCESS = FORBIDDEN
SANITIZATION_POLICY = EXISTING SINGLE POLICY
PREPROD_RECEIVES_FOR_ACTIVATION = SANITIZED_SQL_ONLY
PERSISTENT_DATA_ACTIVATION_AUTHORITY = DISABLED
```

No `RECOVER_ABORT`, `RECOVER_CURRENT`, transaction registry, historical target reconstruction or #915 state machine is current.

Primary sources:

- `.github/workflows/agency-command-dispatch.yml`
- `.github/workflows/preprod-914-governed-successor.yml`
- `docs/operations/preproduction-refresh-governed-successor.md`
- `docs/operations/preproduction-data-refresh.md`
- `scripts/preproduction-refresh/governed-successor/profile.json`
- `scripts/preproduction-refresh/governed-successor/validate-execution-authority.py`
- `scripts/preproduction-refresh/governed-successor/run-plan.sh`
- `scripts/preproduction-refresh/governed-successor/run-server-to-server-apply.sh`
- `scripts/preproduction-refresh/governed-successor/remote-server-to-server-worker.py`
- `scripts/preproduction-refresh/governed-successor/remote-apply-worker.sh`

## 7. Development Seed -> DDEV pull-only (#873 / #956)

#816 is terminal and no longer blocks Development Seed work. The repository/DDEV consumer from #873 remains complete and #956 adds the smallest publisher/storage/reader route needed for a first real proof.

```text
#873_BLOCKED_BY_816 = NO
REPOSITORY_DDEV_IMPLEMENTATION = COMPLETE
SYNTHETIC_PROOF = COMPLETE
DDEV_PROVIDER = .ddev/providers/agency.yaml
LOCAL_UX = ddev pull agency
PULL_ONLY = YES
PUBLISHER_SOURCE_IMPLEMENTED = YES
FIXED_STORAGE_CONTRACT_IMPLEMENTED = YES
RESTRICTED_READER_CONTRACT_IMPLEMENTED = YES
REAL_PREPROD_SEED_GENERATION = PENDING
REAL_STORAGE_PROVISIONING = PENDING
REAL_SEED_DISTRIBUTION = PENDING
REAL_DDEV_PULL = PENDING
DDEV_PUSH = NONE
```

### Publisher route

`.github/workflows/development-seed-publish.yml` is called only by the existing dispatcher for the exact #956 command. GitHub-hosted performs owner/live-main authority validation only. The real data path is restricted to the trusted self-hosted DDEV runner.

The publisher JIT proves the current #914 `COMMITTED / SANITIZED_DATABASE_ACTIVE_AND_VALIDATED` source refresh and current PREPROD release before a fixed read-only Drush dump. No PROD secret/path is part of the route and the PREPROD runtime DB is never a mutation target.

The isolated generation path reuses:

```text
DDEV native database import
-> Drush sql:sanitize
-> existing #914 agency-sanitize.php
-> existing Development Seed sanitizer/assertions
-> database.sql.gz + seed.json
-> existing metadata/SHA verifier
```

### Storage and reader

Fixed server-owned storage is:

```text
/var/www/agency-preprod/shared/development-seeds/
  immutable/<seed-id>/{database.sql.gz,seed.json}
  current -> immutable/<seed-id>
```

`current` switches only after verification. An ephemeral proof reader key is installed on the existing `agency-preprod` account with `restrict` plus a forced SCP read command. It can read only the two files under `current`, has no upload/general-shell/PTY/forwarding path, and is removed terminally. Long-lived human reader keys remain a small separate onboarding operation, not a registration service.

### DDEV consumer

The provider uses repository-pinned PREPROD host trust, fixed remote storage and standard OpenSSH legacy SCP read mode required by the forced command. It has no caller-controlled remote directory and no push stanza. `ddev pull agency` preserves DDEV snapshot/import/restore and post-pull Drupal convergence.

Until the first post-merge #956 execution proves publication and a real `ddev pull agency`, this capability remains `EXECUTION_PENDING`; source implementation is not real execution evidence.

Primary sources:

- `.github/workflows/agency-command-dispatch.yml`
- `.github/workflows/development-seed-publish.yml`
- `.ddev/providers/agency.yaml`
- `.ddev/config.development-seed.yaml`
- `docs/operations/development-seed.md`
- `scripts/development-seed/validate-publish-authority.py`
- `scripts/development-seed/remote-readonly-preprod-source.sh`
- `scripts/development-seed/run-publish.sh`
- `scripts/development-seed/remote-storage.sh`
- `scripts/development-seed/remote-reader-key.sh`
- `scripts/development-seed/remote-read-only-scp.sh`
- `scripts/development-seed/sanitization-policy.json`
- `scripts/development-seed/verify-seed.php`

## 8. Editorial Candidate -> PREPROD review (#959 / #872)

#959 materializes the smallest V1 required by #872. The durable candidate is not a new service or database: it is the existing owner-authored #576 Article payload comment. PREPROD Drupal is only the disposable review rendering surface.

```text
ARTICLE_CONTRACT = #576 REUSED
CANDIDATE_STORE = GITHUB_ISSUE_COMMENT
CANDIDATE_ID = agency-article-<issue_number>
CANDIDATE_REVISION = PAYLOAD_COMMENT_ID
PAYLOAD_HASH = #576 CANONICAL SHA-256
FR = REQUIRED
EN = REQUIRED
CATEGORY = EXISTING blog_categories TERM ONLY
PREPROD_TARGET = FIXED
PROD_ACCESS = NONE
PROD_WRITE = NONE
REAL_PREPROD_#958 = PENDING
```

The existing dispatcher recognizes only `inspect`, `dry-run` and `apply` under `/agency-editorial-candidate`. The reusable workflow receives only PREPROD host/key secrets and JIT revalidates live main plus the latest exact candidate revision/hash before the PREPROD key is materialized.

The execution script fixes `agency-preprod@<configured host>` and `/var/www/agency-preprod/current`, uses repository-pinned PREPROD SSH trust and runs the existing PREPROD runtime side-effect validator before and after mutation. It exposes no caller shell, Drush command, path, Unix user or PROD target.

Initial create and exact replay reuse `AgencyEditorialPublication`; same hash is idempotent. A changed hash may revise only the already-mapped PREPROD Article through the Article-specific helper after #576 validation. The PROD #576 route remains unchanged and continues to fail closed on a changed issue/hash mapping.

Image is not part of V1. #584 remains the existing bounded image primitive and is not duplicated here.

Primary sources:

- `.github/workflows/agency-command-dispatch.yml`
- `.github/workflows/trusted-editorial-preprod-candidate.yml`
- `.github/workflows/trusted-editorial-publication.yml`
- `docs/operations/editorial-candidate.md`
- `scripts/runner/editorial-publication.php`
- `scripts/runner/editorial-preprod-candidate.php`
- `scripts/runner/editorial-preprod-candidate-runner.php`
- `scripts/runner/run-editorial-preprod-candidate.sh`
- `scripts/preproduction/validate-runtime.sh`

Until the first post-merge #958 execution proves FR/EN rendering in real PREPROD, this capability remains `EXECUTION_PENDING`.

## 9. Reload rule

Before saying a route, workflow, runner, seed or command exists today:

1. reload `main`;
2. reload the dispatcher;
3. reload the selected reusable workflow and route-specific docs;
4. reload governing issue/authority and exact execution evidence;
5. for Development Seed real-use claims, reload the latest successful #956 proof and current PREPROD source identity;
6. for Editorial Candidate real-use claims, reload the latest exact candidate comment/hash and #958 PREPROD execution evidence;
7. then classify status.

If this registry and current repository differ, current repository wins and this registry must be corrected.
