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
| T-006 | Pro's bulk save silently resets `enable_shape_cropping`/`resolution_validation`/dimension limits/**`disable_quantity`** to defaults on every product it touches (posts only its own fields; base sanitizer defaults everything absent) | `@sec` | `todo` | — | Same failure class already fixed once for Pro's pricing carry-forward. Decide: (a) widen Pro's bulk form, or (b) base sanitizer treats absent-and-previously-set as unchanged for booleans/dimensions. Recorded in `CONTEXT.md` before implementing |
| T-007 | Browser click-through confirmation for T-005 | user | `todo` | — | In the admin: tick "Lock quantity to 1" on a product, save, reload, still ticked; untick, save, reload, still unticked. On the front end: product page quantity input is fixed at 1; cart page shows plain `1`, no input. This is the one surface T-005's automated checks (headless PHP against the real DB) could not exercise — see `LOG.md` |
| T-008 | `readme.txt` advertises "CDN cache management" as a Pro feature; no such feature exists in the Pro codebase | `@compliance` | `todo` | — | Either build it in Pro, or remove the claim from the free plugin's published `readme.txt`. Found in passing during T-005's review pass, not fixed as a drive-by |

## Blocked

| ID | Title | Owner | Status | Blockers | Acceptance criteria |
|---|---|---|---|---|---|
| T-010 | Any new hook Pro turns out to need | `@spec` | `blocked` | Pro Phase 1 findings | Specced here, released here, then consumed there. Never the reverse |

## Done

| ID | Title | Owner | Status | Merged | Acceptance criteria |
|---|---|---|---|---|---|
| T-001 | Build `CLAUDE.md` | `@manager` | `done` | 2026-07-26, `4be653f` | Modules, shared layer, hook surface pointer, conventions and landmines documented |
| T-005 | "Lock quantity to 1" per-configuration setting | `@be` | `done` | 2026-07-26 (pending commit) | `disable_quantity` boolean added at all 10 touch points; enforced via `woocommerce_is_sold_individually` (never overrides an existing `true`); default `false`. **Verified live** against the real DB (port discovered via Local's `sites.json`, not the on-disk `wp-config.php`): schema/sanitizer round-trip correct, `get_frontend_configuration()` carries the field, filter registered exactly once at priority 10, filter logic correct via reflection stubs (no-config/already-true/malformed-input), a real simple product (276) toggled true→false→reverted cleanly, and a real **variable product (80)** locked on one variation (266) only with siblings (267-269) and parent confirmed unaffected, incl. `get_available_variations()`'s `is_sold_individually` flag — then reverted. `debug.log` shows no new errors. **Review pass:** found and fixed a `readme.txt` FAQ overclaim (Default Settings does not retroactively apply to existing products — see `LOG.md`). **Not covered by any of the above:** the actual browser form — checkbox rendering, real POST through `admin-ajax.php`, the rendered quantity input on a live page. Tracked as **T-007** |
