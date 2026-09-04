# #857 successor boundary for the #834 staging import route

The real #834 staging-import capability remains unchanged by #857.

- `scripts/preproduction-staging-import/run-apply.sh` still reports `sanitization_path=NONE` and `activation_path=NONE`.
- `/usr/local/sbin/agency-preprod-staging-db`, its repository digest, the #849 capability contract and the #851 provisioning contract are not modified by #857.
- The installed helper still exposes only `PRECHECK`, `IMPORT`, `CLEANUP`, and `VERIFY_ABSENCE`; #857 adds no real `SANITIZE` action and performs no provisioning.
- No #857 code may invoke the #834 APPLY route, create a staging database from PROD, connect to real PREPROD, or reuse a consumed #834/#851 request ID.
- #857 proves the same `agency-preprod-refresh-v1` sanitization semantics only against an ephemeral synthetic MariaDB 11.8 service on GitHub-hosted validation infrastructure.
- Synthetic staging database names are derived internally from fixed proof identities; the runtime DB `agency_preprod` is explicitly rejected and cannot be supplied by a caller.
- Any future real sanitization route remains a separate Project Lead-authorized runtime tranche and must re-evaluate the minimum privileged capability before any helper/sudoers provisioning mutation.

This file intentionally lives under `scripts/preproduction-staging-import/` so #857 re-runs the existing #834 staging-import and #849 least-privilege security regressions without widening either execution surface.
