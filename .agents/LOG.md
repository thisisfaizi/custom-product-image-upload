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

**Not fixed here (logged separately, T-006):** while adding this field I suspected the plan's predicted
bug — that `sanitize_configuration()` rebuilds from scratch and merges defaults for any absent key, so
Pro's bulk save (which posts only its own fields) would silently reset `enable_shape_cropping`,
`resolution_validation`, and the dimension limits on every product it touches, with `disable_quantity`
joining that list. **This was only half right — corrected during the T-006 investigation below:** only
`disable_quantity` has this problem. The other fields are explicitly force-set into `FormData` by
`cpiu-admin-pro.js` on every bulk submit regardless of checkbox state, so they're deliberately overwritten
by design, not silently reset. See the T-006 entry for the actual root cause and fix.

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

---

## 2026-07-26 — T-005: review pass (before marking Done)

**Who:** `@review` · **Task:** T-005 · per `AGENTS.md` §8 nothing ships without a review pass

Two findings from an adversarial read of the diff and the published copy:

**1. Fixed — `readme.txt` FAQ overstated what "Default Settings" does.** The FAQ said enabling the lock in
Default Settings applies it "broadly" to existing products. Traced `get_frontend_configuration()`
(`class-cpiu-data-manager.php:775`): unlike `button_text`/`button_color` two lines above (which fall back
to `get_default_settings()` with `?:`), `disable_quantity` has **no such fallback** — it reads straight off
the per-product `$config`, which is `wp_parse_args()`'d against the class's hardcoded `default_config`
(always `false`), never against the Defaults tab. So the Defaults checkbox only pre-fills the Add-form
checkbox for configurations created afterward; it has zero runtime effect on products already configured.
This matches what the original plan's own verification item #3 said ("create a new config → inherits the
default") — the readme copy just claimed more than that. **Fixed** by rewording the FAQ to say it pre-fills
new configurations rather than applying retroactively. Not treated as a code bug (matching `button_text`'s
behavior here would be a real behavior change with its own blast radius, out of scope for a copy fix).

**2. Verified, not just claimed — variable-product / per-variation locking.** The plan's verification item
#7 ("lock one variation, siblings unaffected") had never actually been run — product 276, the only product
exercised so far, is simple. Found a real variable product on the site (id 80, variations 266/267/268/269)
and ran a live test: locked `disable_quantity` on variation 266 only, confirmed via fresh `wc_get_product()`
reads that 267/268/269 and the parent (80) all still read `is_sold_individually() === false`, then reverted
all five configs and confirmed byte-for-byte match against the originals. Also checked
`get_available_variations()` — what WooCommerce's own variation-selection JS reads to refresh the quantity
field after a customer picks a variation — and its `is_sold_individually` flag correctly read `'yes'` for
266 only, `'no'` for the other three. The variation → parent fallback in
`get_frontend_configuration($product_id, $variation_id)` behaves correctly for this feature; no code change
needed. (One inherent, pre-existing WooCommerce behavior noted for the record, not a gap this feature
introduces: on first page load before a variation is selected, the quantity input reflects the *parent's*
lock state, since `woocommerce_quantity_input()` fires on the parent object before JS re-renders it against
the selected variation's `is_sold_individually` flag — identical to how WooCommerce's own per-variation
"Sold individually" checkbox already behaves, with or without this plugin.)

**Also logged, not fixed (unrelated to T-005, found in passing):** `readme.txt` line 48 lists "CDN cache
management" as a Pro feature. No such feature exists anywhere in the Pro codebase searched so far this
session — a false claim in the free plugin's published readme, predating this work. Not fixed here (§3
rule 5); needs its own task to either build it in Pro or remove the claim.

**Evidence:** `t005-variation-test.php` (scratch, not committed) — locked variation 266, read back all five
products' `is_sold_individually()` and the parent's `get_available_variations()`, reverted, re-read all five
to confirm exact match. Output captured in this session's transcript.

**Board:** T-005 confirmed for **Done** (readme fix applied, variable-product criterion verified live).
Opened T-008 for the "CDN cache management" false-claim cleanup.

**Next:** T-007 (browser click-through) and T-006 (Pro bulk-save reset) remain open, independent of each
other.

---

## 2026-07-26 — T-006: investigated and fixed (in the Pro repo)

**Who:** `@sec` · **Task:** T-006

Re-investigated the claim in this file's earlier T-005 entry that Pro's bulk save silently resets
`enable_shape_cropping`/`resolution_validation`/dimensions in addition to `disable_quantity` — **that part
was wrong**, corrected above and in `TASKS.md`. Reading `custom-product-image-upload-pro/assets/js/cpiu-admin-pro.js:87-93`
shows those fields are explicitly force-set into the bulk-save `FormData` on every submit regardless of
checkbox state; bulk save deliberately overwrites them, which is correct behavior for a bulk-apply form.
Only `disable_quantity` had no representation in the form at all.

**Decision:** widen Pro's Bulk Operations form (option (a)) rather than give this repo's
`sanitize_configuration()` previous-value awareness (option (b)) — full reasoning in
`custom-product-image-upload-pro/.agents/CONTEXT.md` D-007. Fix lives entirely in the Pro repo
(`class-cpiu-pro-bulk.php`, `cpiu-admin-pro.js`), tracked there as P-034. No base-repo code changed.

**Also found, not fixed:** the bulk form has no partial-update semantics at all — any bulk save overwrites
cropping/resolution/dimensions with the form's current values on every selected product, whether the
merchant meant to touch them or not. Opened as the Pro repo's P-035; out of scope for T-006.

**Not verified live:** the fix is pure HTML/JS, so headless PHP can't exercise it. A real browser session
hit `wp-login.php` — no authenticated session available in this environment. Blocked on credentials,
jointly with T-007.

**Next:** T-007 and T-006's browser verification both need either login credentials or the user's own
click-through.
