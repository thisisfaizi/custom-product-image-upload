# LOG — NDV Product Image Upload (free base plugin)

Append-only. Newest at the bottom. One entry per loop iteration or session milestone.
Evidence, not essays.

---

## 2026-07-26 — Session: protocol + context bootstrap

**Who:** `@manager` (session bootstrap, no production code changed)

**Did:**
- Created `AGENTS.md` — the multi-agent protocol, adapted from the Super Ledger template to this domain.
  The template's §0 ("pre-production, schema is free") was **inverted**: this plugin is live on
  WordPress.org with real shops, so §0 here is "you never own the data" — option keys and order-item meta
  are a public contract, and the `cpiu_` prefix is permanent.
- Created `/.agents/` with `TASKS.md`, `CONTRACTS.md` (canonical for the product line), `CONTEXT.md`,
  and this file.

**Observed (read-only audit of the hook surface):**
- 8 filters, 5 actions, 8 options, 3 order-item meta keys, 1 HMAC-authenticated public endpoint, 1 daily
  cron, 6 constants — all catalogued in `CONTRACTS.md` with file:line references.
- The paid add-on consumes 6 of those hooks plus the `cpiu-admin-multi-product` script handle and the
  `toplevel_page_cpiu-settings` screen id.
- **The Pro add-on has drifted** against the rebrand: it still targets the old menu slug
  (`custom-image-upload-addon`) and declares the old dependency slug. Those are Pro's tasks, tracked in the
  Pro repo — recorded here only because they prove `CONTRACTS.md` had no owner before today.

**Evidence:** file reads only — no code executed, no site loaded, nothing changed in this plugin.
`CONTRACTS.md` was built from the **working copy**, not from the published WP.org zip; verifying that they
match is task T-004.

**Next:** T-001 (`CLAUDE.md`), then T-002/T-004. Feature work waits on T-001.
