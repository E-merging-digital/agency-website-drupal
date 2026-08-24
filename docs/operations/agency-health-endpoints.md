# Agency Drupal health endpoints

Issue: #759

Agency consumes the provider-neutral Drupal health contract v1 defined by `E-merging-digital/platform-ops/contracts/drupal-health.yaml`. The application implementation deliberately has no dependency on Better Stack or any other monitoring provider.

## Public probes

| Probe | Route | Required checks | Success | Unavailable | External timeout |
| --- | --- | --- | --- | --- | --- |
| Liveness | `GET /health/live` | application runtime + minimal Drupal bootstrap | `200 {"status":"ok"}` | `503 {"status":"unavailable"}` when Drupal can still build the response; otherwise any 5xx/timeout/network failure is unhealthy | 3 s |
| Readiness | `GET /health/ready` | minimal Drupal bootstrap + required database connectivity | `200 {"status":"ok"}` | `503 {"status":"unavailable"}` | 5 s |

Both responses use `Content-Type: application/json` and `Cache-Control: no-store`. Unsupported HTTP methods are rejected by Drupal routing.

Liveness is allowed through Drupal maintenance mode so that the monitoring layer can distinguish a live runtime/bootstrap from traffic readiness. Readiness is not maintenance-access enabled and therefore must not claim the application is ready while normal Drupal traffic is intentionally unavailable.

## Readiness dependency policy

For Agency v1, the database is the only application-specific dependency that gates readiness. The probe executes a minimal `SELECT 1` through Drupal's existing database connection service. Any database exception or indeterminate result fails closed to the same public `503 {"status":"unavailable"}` response.

Mail, AI providers, analytics, search indexing and other optional integrations do not gate readiness because Agency can still serve normal public traffic without them. They require separate operational signals if they later become critical.

## Public security boundary

The public payload is intentionally binary. It must never expose component versions, stack traces, database names, private hosts, connection strings, credentials, tokens, filesystem paths, infrastructure internals, customer data or detailed failure causes.

Rich diagnostics remain separate from these public routes and must use an authenticated/operator surface where needed. The existing production diagnostic workflows are not part of the public health contract.

## Monitoring integration handoff

The current production monitoring in `E-merging-digital/platform-ops` already owns:

- `agency-prod-http`: homepage HTTPS/content/TLS monitor;
- `agency-prod-dns`: DNS monitor.

Those monitors must remain in place. Health monitoring is additive, not a replacement for homepage/content or DNS coverage.

After the endpoint implementation is merged and deployed, `platform-ops` should add provider-adapter monitors for:

- `https://emergingdigital.be/health/live`, expecting HTTP 200 and the healthy payload, with an external timeout no greater than 3 seconds;
- `https://emergingdigital.be/health/ready`, expecting HTTP 200 and the healthy payload, with an external timeout no greater than 5 seconds.

The exact provider representation, alert routing and credentials remain owned by `platform-ops`. No Better Stack token or provider-specific configuration belongs in Agency Drupal.

A future PREPROD may consume the same two routes after #758 creates a real environment. This document does not create, configure or promote PREPROD.
