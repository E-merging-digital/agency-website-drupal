# #866 — real one-shot sanitization successor boundary

This file is a repository-only regression trigger and durable boundary note for #866.

The deterministic sanitization authority remains exactly `agency-preprod-refresh-v1` with policy SHA-256 `cf98b09b6f2c038aed0f82bd9a61553bff9c9cba4fee14d56eaf233cc3da98cb`.

#866 does not introduce a second sanitizer or policy. Its future real-data route composes the already-reviewed `IMPORT_SANITIZE_PROVE` capability from #859/#861 so sanitization and assertions occur inside the fixed helper lifecycle before caller control can return.

During the current Delivery tranche:

- real PROD data read: NONE;
- real PROD snapshot: NOT PERFORMED;
- real PROD data transfer: NONE;
- real PREPROD mutation: NONE;
- real sanitization: NOT PERFORMED;
- runtime DB switch: NONE;
- activation: NOT PERFORMED.

The #855 and #857 synthetic sanitization regressions remain authoritative for deterministic policy semantics and MariaDB compatibility.
