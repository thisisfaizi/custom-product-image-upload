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
| T-004 | Confirm the hook surface in `CONTRACTS.md` matches the **published** 1.0.0 zip, not just the working copy | `@manager` | `todo` | — | Every filter/action/option in `CONTRACTS.md` verified present in the WP.org release; discrepancies logged |
| T-005 | "Lock quantity to 1" per-configuration setting — see plan `~/.claude/plans/plan-the-feature-of-sprightly-hamming.md` | `@be` | `todo` | — | New `disable_quantity` boolean added at all 10 touch points listed in the plan; enforced via `woocommerce_is_sold_individually` (return early if already true); default `false`; core-flow regression green; the pre-existing Pro-bulk-save field-reset bug logged as a separate finding (T-006), not fixed as a drive-by |
| T-006 | Pro's bulk save silently resets `enable_shape_cropping`/`resolution_validation`/dimension limits to defaults on every product it touches (posts only its own fields; base sanitizer defaults everything absent) | `@sec` | `todo` | — | Same failure class already fixed once for Pro's pricing carry-forward. Decide: (a) widen Pro's bulk form, or (b) base sanitizer treats absent-and-previously-set as unchanged for booleans/dimensions. Recorded in `CONTEXT.md` before implementing |

## Blocked

| ID | Title | Owner | Status | Blockers | Acceptance criteria |
|---|---|---|---|---|---|
| T-010 | Any new hook Pro turns out to need | `@spec` | `blocked` | Pro Phase 1 findings | Specced here, released here, then consumed there. Never the reverse |

## Done

*(nothing yet — this board was created 2026-07-26)*
