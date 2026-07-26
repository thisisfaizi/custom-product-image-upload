# TASKS — NDV Product Image Upload (free base plugin)

Statuses: `todo` → `in_progress` → `in_review` → `done` / `blocked`.
Update the row **at every transition**, not just at the end (`AGENTS.md` §8.1).

> **Note:** the current programme of work is the **Pro rebrand**, tracked in
> `../custom-product-image-upload-pro/.agents/TASKS.md`. This board holds only what the base plugin itself
> owes. Keep it that way — Pro's problems are not fixed here (`AGENTS.md` §3.9).

---

## In flight

*(nothing)*

## Todo

| ID | Title | Owner | Status | Blockers | Acceptance criteria |
|---|---|---|---|---|---|
| T-002 | Answer Q-001 — was this plugin previously live on WP.org under the old slug? | user | `todo` | — | Yes/no recorded in `CONTEXT.md`. If yes: open a task for a migration path + a pointer on the old listing |
| T-003 | Refresh compatibility headers — `readme.txt` `Tested up to` vs the plugin header's `WC tested up to` | `@compliance` | `todo` | — | Both truthful against current WP/WooCommerce; a recurring cadence agreed and recorded |
| T-004 | Confirm the hook surface in `CONTRACTS.md` matches the **published** zip, not just the working copy | `@manager` | `todo` | — | Every filter/action/option in `CONTRACTS.md` verified present in the WP.org release; discrepancies logged |
| T-008 | `readme.txt` advertises "CDN cache management" as a Pro feature; no such feature exists in the Pro codebase | `@compliance` | `todo` | — | Either build it in Pro, or remove the claim from the free plugin's published `readme.txt`. Found in passing during T-005's review pass, not fixed as a drive-by |

## Blocked

| ID | Title | Owner | Status | Blockers | Acceptance criteria |
|---|---|---|---|---|---|
| T-010 | Any new hook Pro turns out to need | `@spec` | `blocked` | Pro Phase 1 findings | Specced here, released here, then consumed there. Never the reverse |

## Done

| ID | Title | Owner | Status | Merged | Acceptance criteria |
|---|---|---|---|---|---|
| T-001 | Build `CLAUDE.md` | `@manager` | `done` | 2026-07-26, `4be653f` | Modules, shared layer, hook surface pointer, conventions and landmines documented |
| T-005 | "Lock quantity to 1" per-configuration setting | `@be` | `done` | 2026-07-26 (pending commit) | `disable_quantity` boolean added at all 10 touch points; enforced via `woocommerce_is_sold_individually` (never overrides an existing `true`); default `false`. **Verified live** against the real DB (port discovered via Local's `sites.json`, not the on-disk `wp-config.php`): schema/sanitizer round-trip correct, `get_frontend_configuration()` carries the field, filter registered exactly once at priority 10, filter logic correct via reflection stubs (no-config/already-true/malformed-input), a real simple product (276) toggled true→false→reverted cleanly, and a real **variable product (80)** locked on one variation (266) only with siblings (267-269) and parent confirmed unaffected, incl. `get_available_variations()`'s `is_sold_individually` flag — then reverted. `debug.log` shows no new errors. **Review pass:** found and fixed a `readme.txt` FAQ overclaim (Default Settings does not retroactively apply to existing products — see `LOG.md`). Browser click-through completed as **T-007** |
| T-006 | Pro's bulk save silently forced `disable_quantity` to `false` on every product it touches — the field had no control in the Bulk Operations form at all | `@sec` | `done` | 2026-07-26, Pro repo `146769f` | **Correction recorded during investigation:** the original framing (that `enable_shape_cropping`/`resolution_validation`/dimensions were *also* silently reset) was wrong — `cpiu-admin-pro.js:87-93` force-sets all of those into `FormData` on every submit regardless of checkbox state; they're deliberately overwritten, not silently reset. Only `disable_quantity` was genuinely missing. Fixed in the **Pro repo** (option (a): widened the bulk form — `class-cpiu-pro-bulk.php` + `cpiu-admin-pro.js`; decision recorded in this repo's `CONTEXT.md` and in the Pro repo's `CONTEXT.md` D-007). **Browser-verified:** the checkbox renders correctly on both the Default Settings and Bulk Operations tabs (screenshots, `1.1.0`). The actual bulk-save AJAX round-trip through the Select2 product picker was **not** exercised — that specific widget resisted this session's browser-automation tooling (clicks timed out on an element Playwright's actionability check considered non-visible despite rendering correctly on screen) — but the code path it calls (`sanitize_configuration()` + `save_product_configuration()`) is the identical, already-proven-correct function pair T-005 verified directly, and the new JS line mirrors an already-working adjacent pattern exactly. See `LOG.md` |
| T-007 | Browser click-through confirmation for T-005 | user | `done` | 2026-07-26 | **Verified live** (credentials supplied via Local's auto-login). Admin: ticked "Lock quantity to 1" on product 276, saved (AJAX 200, no console errors), reloaded the page fresh, still ticked; unticked, saved, reloaded fresh, still unticked — the classic checkbox-persistence bug does not occur in either direction. Front end: with the lock on, the product page renders **no quantity input at all** next to Add to Cart (WooCommerce's own behavior for `is_sold_individually() === true` — matches what T-005's reflection tests already predicted). **Not exercised:** the actual add-to-cart → cart-page flow, which is gated by this product's own separate upload-requirement JS (needs a real file upload, unrelated to this feature) — not attempted as it's a disproportionate automation effort for a code path WooCommerce's own core template (`wc-template-functions.php`) already governs off the same `is_sold_individually()` value just confirmed true. All test changes (the lock toggle, a temporary `image_count` change made to probe the add-to-cart gate) were reverted; product 276 confirmed back to its original state |
