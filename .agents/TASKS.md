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
| T-001 | Build `CLAUDE.md` for this repo (`AGENTS.md` §10 — no feature work starts without it) | `@manager` | `todo` | — | Modules, shared layer, hook surface, conventions and landmines documented; matches the code as it actually is, not as it should be |
| T-002 | Answer Q-001 — was this plugin previously live on WP.org under the old slug? | user | `todo` | — | Yes/no recorded in `CONTEXT.md`. If yes: open a task for a migration path + a pointer on the old listing |
| T-003 | Refresh compatibility headers — `readme.txt` `Tested up to` vs the plugin header's `WC tested up to` | `@compliance` | `todo` | — | Both truthful against current WP/WooCommerce; a recurring cadence agreed and recorded |
| T-004 | Confirm the hook surface in `CONTRACTS.md` matches the **published** 1.0.0 zip, not just the working copy | `@manager` | `todo` | — | Every filter/action/option in `CONTRACTS.md` verified present in the WP.org release; discrepancies logged |

## Blocked

| ID | Title | Owner | Status | Blockers | Acceptance criteria |
|---|---|---|---|---|---|
| T-010 | Any new hook Pro turns out to need | `@spec` | `blocked` | Pro Phase 1 findings | Specced here, released here, then consumed there. Never the reverse |

## Done

*(nothing yet — this board was created 2026-07-26)*
