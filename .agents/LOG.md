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

---

## 2026-07-26 — T-001: CLAUDE.md built

**Who:** `@manager` · **Task:** T-001, per `AGENTS.md` §10 — done before any feature work

**Did:** Wrote `CLAUDE.md`: identity/versions, the 5 modules in `includes/` and what each owns, a pointer
to `CONTRACTS.md` for the hook surface (kept out of CLAUDE.md itself — that table churns, this file
shouldn't), conventions (singleton pattern, section banners, `phpcs:ignore` reasons, the
sanitize-then-`wp_parse_args` shape every config sanitizer follows), and the landmines already known from
this session's work: the permanent `cpiu_` prefix, the permanent upload directory name, the free↔Pro
boundary, Pro's post-rebrand drift (not this repo's job to fix), non-autoload requirement on
`cpiu_multi_product_configs`, and the no-test-suite reality.

**Evidence:** built by reading the actual module files (class lists, headers, `readme.txt`), not from
memory of the rebrand commit. Matches `.agents/CONTRACTS.md`'s existing hook inventory — no new hooks
found, none missing.

**Board:** T-005 opened — "Lock quantity to 1" per-configuration setting, spec already written as
`~/.claude/plans/plan-the-feature-of-sprightly-hamming.md` (10 touch points identified, enforcement via
`woocommerce_is_sold_individually`). T-006 opened alongside it: Pro's bulk save already silently resets
several boolean/dimension fields to defaults on every product it touches — found while tracing where a new
boolean field would need to be threaded, not yet fixed, tracked separately per §3 rule 5 (no drive-by
fixes bundled into a feature task).

**Next:** T-005.

---

## 2026-07-26 — T-005: "Lock quantity to 1" per-configuration setting

**Who:** `@be` · **Task:** T-005 · Spec: `~/.claude/plans/plan-the-feature-of-sprightly-hamming.md`

**Did:** Added `disable_quantity` (bool, default `false`) at all 10 touch points identified in the plan —
schema/defaults in `CPIU_Data_Manager` ($default_config + $default_settings), both sanitizers
(`sanitize_default_settings`, `sanitize_configuration`), the AJAX save whitelist in
`CPIU_Ajax_Handler::save_configuration()`, the main file's `cpiu_get_default_multi_product_options()`,
three admin form checkboxes (Defaults tab, Add form, Edit form — mirroring the existing
`enable_shape_cropping` pattern exactly), five JS collect/populate points in
`cpiu-admin-multi-product.js`, and `get_frontend_configuration()`'s frontend-safe projection (needed so
the new field actually reaches the enforcement filter).

**Enforcement:** new `CPIU_Frontend_Manager::maybe_disable_quantity()` on `woocommerce_is_sold_individually`
(priority 10). Reuses WooCommerce's own mechanism rather than patching the quantity input, cart display,
and add-to-cart merge separately — confirmed via `abstract-wc-product.php:1713` and
`wc-template-functions.php:582,726` that this one filter covers all three surfaces. Resolves
variation → parent via the existing `get_frontend_configuration($product_id, $variation_id)` fallback,
so a variation's own lock wins, falling back to the parent. **Never overrides an existing `true` back to
`false`** — a merchant's own "Sold individually" checkbox, or another plugin's lock, is never undone.

**Not fixed here (logged separately, T-006):** while adding this field I confirmed the plan's predicted
bug — `sanitize_configuration()` rebuilds from scratch and merges defaults for any absent key, so Pro's
bulk save (which posts only its own fields) already silently resets `enable_shape_cropping`,
`resolution_validation`, and the dimension limits on every product it touches. `disable_quantity` joins
that list. Per `AGENTS.md` §3 rule 5, not fixed as a drive-by inside this task — T-006 covers it with its
own decision (widen Pro's bulk form vs. fix the base sanitizer's absent-field handling).

**Version:** bumped to 1.1.0 (main file header + `CPIU_VERSION` + `readme.txt` `Stable tag` — all three,
per `AGENTS.md` §8.1). Changelog entry added; new feature bullet and FAQ entry added to `readme.txt`.
`CLAUDE.md`'s Identity table no longer pins a specific version number — it churns every release, which
contradicted the file's own "durable facts only" principle; now points at the header/readme instead.

**Evidence — this is the interesting part.** No `wp` CLI was reachable from this shell (`wp.bat` exists on
PATH but its bundled `php` can't reach Local's MySQL — `wp-config.php`'s `DB_HOST` is bare `localhost`
because Local's own PHP process injects the real port at runtime; that injection doesn't happen for a
standalone `php.exe` invocation). Found the actual port in Local's `AppData/Roaming/Local/sites.json`
(`10011` for this site) and connected directly with `mysqli` — confirmed reachable before relying on it.

Booted a real WordPress request (`wp-load.php`) against the live DB, with `WP_HTTP_BLOCK_EXTERNAL` and
`DISABLE_WP_CRON` set — the first attempt with neither set hung past 120s, almost certainly on an
outbound HTTP call (`wp_version_check`/license-style request) somewhere in the loaded plugin set. Ran two
scripts:

1. **Schema/logic check:** all four classes load, `CPIU_VERSION` reads `1.1.0`, `default_settings` carries
   the new key, `sanitize_configuration()` round-trips true/false correctly, `get_frontend_configuration()`
   on a real configured product (276) carries the key, a nonexistent product returns `false` cleanly (no
   fatal), the filter is registered exactly once at priority 10, and — via `ReflectionMethod` on the real
   class with fake product stubs — the filter's three decision branches (no config, already-true never
   overridden, malformed input) all matched expectations.
2. **Live end-to-end check, real DB writes, reverted after:** on product 276, `save_product_configuration()`
   with `disable_quantity = true` → **real** `WC_Product::is_sold_individually()` returns `true`; set back
   to `false` → returns `false`; reverted to the exact original stored config afterward. The revert
   compared unequal on first check — `updated_at` had changed, which is `save_product_configuration()`
   setting a fresh timestamp on every real save, not data loss. Confirmed every other field, including the
   nested `pricing` config from the earlier Pro pricing test session on this same product, survived all
   three writes untouched (`wp_parse_args` preserves keys absent from `$default_config`).

`debug.log`'s tail showed no new entries from either run — all lines present pre-date this session
(a stale Elementor control-redeclare warning from July, and this session's own earlier failed standalone
DB connection attempts before the correct port was found — both unrelated to this change).

**NOT verified — the one gap, tracked as T-007:** no browser was opened. The checkbox markup, the real
`admin-ajax.php` POST cycle through the JS I wrote, and the rendered quantity input on an actual page are
unconfirmed. The AJAX whitelist code is a mechanical mirror of the already-working
`enable_shape_cropping` pattern (low risk), but per `AGENTS.md` §5 "an unrun file is an unverified file" —
this file has been run, at the data and enforcement-logic layer, but not through the actual UI a merchant
would click. Flagging rather than quietly calling this fully done.

**Next:** T-007 (user click-through) whenever convenient; T-006 (the Pro bulk-save reset bug) is
independent and can proceed without waiting on it.
