# #859 one-shot root-owned sanitization bundle boundary

`agency-preprod-refresh-v1` remains the sole sanitization policy authority at `scripts/preproduction-refresh/sanitization-policy.json`.

#859 does not create a second policy. A future governed provisioning tranche may install a byte-identical root-owned copy at `/usr/local/lib/agency-preprod-staging/sanitization-policy.json`, but only when its SHA-256 is exactly `cf98b09b6f2c038aed0f82bd9a61553bff9c9cba4fee14d56eaf233cc3da98cb`.

The repository helper bundle is prepared for one fixed `IMPORT_SANITIZE_PROVE` action. It imports exact bounded stdin into the request-derived staging database, sanitizes and asserts within the same helper invocation, and destroys the derived staging database/account before returning on success or failure.

This issue performs no real provisioning, no real PROD access, no real PREPROD mutation, no runtime DB switch and no activation. The existing #855/#857 deterministic/idempotent/fail-closed proofs remain mandatory regressions.
