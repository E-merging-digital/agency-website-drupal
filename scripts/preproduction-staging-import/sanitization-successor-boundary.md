# #855 successor boundary for the #834 staging import route

The real #834 staging-import capability remains unchanged by #855.

- `scripts/preproduction-staging-import/run-apply.sh` still reports `sanitization_path=NONE` and `activation_path=NONE`.
- `/usr/local/sbin/agency-preprod-staging-db` and its #849/#851 least-privilege provisioning contract are not modified by #855.
- No #855 code may invoke the #834 APPLY route, create a staging database from PROD, or reuse a consumed #834 request ID.
- #855 proves sanitization only against isolated synthetic in-memory fixtures on GitHub-hosted validation infrastructure.
- Any future real sanitization route requires a separate Project Lead-authorized runtime tranche and must re-evaluate the minimum privileged capability before mutation.

This file intentionally lives under `scripts/preproduction-staging-import/` so any #855 change that carries this boundary re-runs the existing #834 staging-import security regression without widening the #834 execution surface.
