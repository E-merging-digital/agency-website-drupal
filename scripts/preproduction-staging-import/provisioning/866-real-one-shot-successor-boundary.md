# #866 — installed one-shot capability successor boundary

This repository-only note binds the #859/#861 regressions to the #866 successor route without changing provisioning semantics.

#866 reuses the already installed root-owned bundle exactly:

- helper SHA-256: `a3eaf545abc448004f7c1136bf4e19a5728b1e16784c700ffca24e91e2e82b71`;
- sanitizer SHA-256: `fcdb1e42b8fd50db8e8190dea61eca66544149dc53a762affdb33bf96d2d481f`;
- policy SHA-256: `cf98b09b6f2c038aed0f82bd9a61553bff9c9cba4fee14d56eaf233cc3da98cb`;
- sudoers authority: fixed helper path only.

No reprovisioning is performed under #866. The future separately authorized data transaction may invoke only the existing fixed action `IMPORT_SANITIZE_PROVE`; detached `IMPORT` plus caller-side sanitization is forbidden.

Current Delivery execution remains PLAN-only and mutation-free. No real PROD data or real PREPROD mutation is authorized by this note.
