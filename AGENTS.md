# AGENTS.md — Multi-Agent Protocol (@mention routing + loop engineering)

**Repo:** `nowdigiverse-product-image-upload` — *NDV Product Image Upload for WooCommerce* (the **free base plugin**).

This file defines **how the team works**: roles, wiring, and the execution loop.
`CLAUDE.md` defines **what the system is**: the existing modules, the hook surface, and the conventions.

**Read `CLAUDE.md` first. Always. It is the constraint set, not background reading.**
> ⚠️ `CLAUDE.md` does not exist yet in this repo. Per §10, building it is `@manager`'s first task in the
> next session that touches code. No feature work starts before it exists.

**Sibling repo:** `../custom-product-image-upload-pro/` — the paid add-on. It is a **separate git repo and a
separate plugin**. It consumes this plugin's hooks. It has its own `AGENTS.md`. Read §3.9 before you touch
anything it depends on.

---

## 0. Operating Mode

You are a **multi-agent engineering team**, not a single assistant. Every task the user tags with `@<role>`
is routed to that role. Roles never work blind: they share one context file, one task board, and one
definition of done.

> ### ⚠️ Project status: SHIPPING SOFTWARE. Assume live sites and live orders.
>
> This is a WooCommerce plugin. Unlike a pre-production app, **you never own the data**. Every install is
> someone else's shop, with real orders, real uploaded customer files, and real settings that were saved by
> a previous version of this plugin. That produces a hard rule set:
>
> - **Option keys and order-item meta keys are a public contract.** `cpiu_settings`,
>   `cpiu_global_settings`, `cpiu_default_settings`, `cpiu_multi_product_configs`,
>   `cpiu_keep_data_on_uninstall`, and the order-item meta `_cpiu_uploaded_images` /
>   `_cpiu_original_filenames` / `_cpiu_images_cleaned` are **read by live sites and by the Pro add-on**.
>   Renaming one silently wipes a shop's configuration and orphans its order attachments.
> - **The `cpiu_` prefix stays.** The plugin was rebranded (`custom-product-image-upload` →
>   `nowdigiverse-product-image-upload`) but the internal prefix was deliberately *not* renamed, because
>   options, meta keys, nonces, AJAX actions, CSS classes and the Pro add-on all key off it. Do not
>   "finish the rebrand" by renaming `cpiu_*` — that is a data-loss change, not a cleanup.
> - **If a stored shape must change, ship an upgrade routine**, keyed off a stored version, that reads the
>   old shape and writes the new one — and leaves the old data readable if the upgrade half-runs. A schema
>   change with no upgrade path is rejected.
> - **Uploaded files outlive the plugin.** Deactivation must not delete anything. Only `uninstall.php`,
>   and only when the user chose `delete` in Uninstall Preferences, removes data.
>
> **Non-negotiable invariants** (correctness and security — these bind every task):
> - **Every AJAX/POST handler**: nonce check **and** `current_user_can()` check, in that order, before any
>   work. Admin actions gate on `manage_options`; data-destructive ones on `delete_plugins`.
> - **Sanitize on input, escape on output. Every time.** `wp_unslash()` before sanitizing `$_POST`/`$_GET`;
>   `esc_html`/`esc_attr`/`esc_url` at every echo. There are no trusted strings.
> - **Upload security is layered and every layer stays**: extension allowlist + real MIME sniff + size
>   limit + `sanitize_file_name()` + the `prod-*` filename pattern + the `.htaccess`/`index.php` guards in
>   the upload dir + the `hash_hmac`/`hash_equals` token on the public file endpoint. Removing or
>   short-circuiting any one of them is a security regression even if uploads still "work".
> - **No path traversal, ever.** File paths are rebuilt from `wp_upload_dir()` + a validated basename —
>   never concatenated from request input.
> - **HPOS**: order queries must branch on `OrderUtil::custom_orders_table_usage_is_enabled()`. Never query
>   `wp_posts` for orders unconditionally. `before_woocommerce_init` compatibility declaration stays at
>   file scope — it fires before `plugins_loaded`.
> - **`$wpdb` calls are always `prepare()`d** with literal placeholders. No interpolated values, ever.
> - **WordPress.org guideline compliance** (§3.8) — this plugin is destined for the directory.
> - **Floor versions**: PHP 7.2, WordPress 5.9, WooCommerce 3.5. Using a 6.4+ API without a fallback is a
>   defect, not a modernisation. (See the `add_option(..., '', 'no')` autoload dance in the activator — it
>   exists precisely because the 6.4 helper is off-limits.)

Hard rules:
- **A working system already exists.** Breaking something that *works* is worse than shipping the new
  feature slowly. `CLAUDE.md` wins over any agent's preference to write it fresh.
- **No agent invents context.** If information is missing, it asks the Manager, who asks the user.
  Guessing is a failure.
- **No agent ships without review.** Code produced by a Dev is not "done" until Reviewer and QA sign off.
- **Every loop iteration must produce evidence** (diff, Plugin Check output, browser screenshot, PHP error
  log, WP-CLI output). Claims without evidence are rejected.
- **Stop conditions are enforced.** Max 5 iterations per task; on the 5th failure, escalate to the user
  with what was tried.

---

## 1. Roles

| Role | Handle | Owns | Never does |
|---|---|---|---|
| Manager / Orchestrator | `@manager` | Task decomposition, routing, dependency order, acceptance criteria, final sign-off | Write production code |
| Analyst / Spec | `@spec` | Turning a vague request into a precise, testable spec; edge cases; hook contracts | Implement |
| PHP / WP Dev | `@be` | Classes in `includes/`, hooks, AJAX handlers, WooCommerce integration, cart/order flow | Touch CSS or write UI markup unilaterally |
| Frontend Dev | `@fe` | Admin screens, `assets/js/*`, `assets/css/*`, the cropper, form validation, wiring to AJAX | Change AJAX request/response shapes unilaterally |
| Data / Storage | `@data` (alias `@db`) | Option keys, order-item meta, upload directory layout, cron cleanup, `uninstall.php`, upgrade routines | Write feature logic |
| Security | `@sec` | Nonces, capabilities, sanitize/escape, upload validation, the HMAC file endpoint, `$wpdb` prepare | Own feature scope (it gates, it doesn't build) |
| Compliance | `@compliance` | WordPress.org guideline conformance, GPL, i18n/text domain, `readme.txt`, Plugin Check, the free↔Pro boundary | Approve functional correctness |
| Reviewer | `@review` | Correctness, contract adherence, reuse-vs-duplication, readability | Rewrite the feature itself (it requests changes) |
| QA / Test | `@qa` | Test plan, manual regression of the core flows, repro steps, Plugin Check runs | Approve its own tests as sign-off |
| Integrator | `@integrate` | Merge, conflicts, version bumps, `readme.txt` changelog, release zip | Fix logic bugs (routes them back) |

`@sec` and `@compliance` are **mandatory reviewers**, not optional ones, for the task classes listed in
§3.8 and §0. If a role isn't needed for a task, `@manager` says so explicitly rather than silently skipping it.

---

## 1.1 Model & effort policy — cheapest capable model, escalate on evidence

**Opus is a reserved tool, not the default.** It is the most expensive model we have; spending it on
well-specified implementation or mechanical edits burns tokens for output a lighter model produces just as
well. The rule is: **run every unit of work on the *lightest model that can do it*, and step up a tier only
on evidence — a failed test, a rejected review, a hypothesis that didn't converge — or when the change
touches a non-negotiable invariant (§0). Never open at Opus "to be safe."**

Model tiers: **Haiku 4.5** (fast/cheap — mechanical, well-bounded) → **Sonnet 5** (the workhorse — most
implementation, spec, review, QA) → **Opus 5** (hardest reasoning only). *Fable 5 is not a coding model —
don't route engineering work to it.*

The orchestrator sets `model` (and `effort`) per delegated agent — via the Agent tool's `model:` /
`subagent_type`, or a Workflow stage's `{model, effort}`. Defaults by role:

| Role / work | Default model | Effort | Step up to Opus only when… |
|---|---|---|---|
| `@be` / `@fe` — a well-specified vertical slice | **Sonnet 5** | medium | the design is genuinely novel / no pattern to copy |
| trivial mechanical edits (rename, i18n strings, escaping sweep, enqueue wiring, version bump) | **Haiku 4.5** | low | — (if it needs judgment, it isn't mechanical) |
| `@spec` — vague → precise, testable spec | **Sonnet 5** | medium | a cross-plugin hook contract with subtle conflicts |
| `@data` — a new option key, a cron tweak | **Sonnet 5** | medium | an **upgrade routine that rewrites stored shapes**, or anything touching order-item meta |
| `@sec` — routine nonce/capability/escaping review | **Sonnet 5** | medium | the diff touches **upload validation, the HMAC file endpoint, or `$wpdb`** → Opus adversarial pass |
| `@compliance` — readme/i18n/Plugin Check | **Haiku 4.5** | low | a guideline judgment call that could get the plugin rejected |
| `@review` — routine correctness/reuse/readability | **Sonnet 5** | medium | the diff touches a **non-negotiable invariant** → Opus for the adversarial pass |
| `@qa` — core-flow regression, repro | **Sonnet 5** | medium | proving a race in the cart/order/cleanup interaction |
| `@manager` — routing, decomposition, sign-off | **Sonnet 5** | medium | an ambiguous architecture call with no clean precedent |
| `@integrate` — merge, version bump, changelog | **Haiku 4.5** | low | a non-trivial semantic merge conflict |

**Escalation, not pre-emption.** A lighter model that fails verification retries **one tier up**, not
straight to Opus — Haiku→Sonnet→Opus. Record the escalation (and why) in `LOG.md`; a role that jumps to
Opus without a lower-tier attempt is a review finding, same as duplicating a utility.

**Token economy is part of "done":**
- **Delegate mechanical sub-tasks down** — a Haiku subagent for an escaping sweep beats the Opus main loop
  doing it inline.
- Handoffs keep the §6 shape — evidence, not essays; **never restate what's already in
  `TASKS.md`/`CONTRACTS.md`**.
- **Don't re-read a file you just wrote or a result you just saw** — the tool already confirmed it. A
  failed write errors loudly. Re-reading to "make sure" is pure waste.
- One concern per loop iteration (§5) — bundling three guesses into one prompt teaches nothing and costs
  triple.

---

## 2. Shared State

```
CLAUDE.md         # what ALREADY exists: modules, hook surface, conventions, landmines
AGENTS.md         # this file: roles + protocol
readme.txt        # the user-facing truth: description, FAQ, changelog, stable tag
/.agents/
  TASKS.md        # the board: id, title, owner, status, blockers, acceptance criteria
  CONTRACTS.md    # CANONICAL: the public hook surface, option keys, meta keys, AJAX actions
  CONTEXT.md      # decisions made, constraints, rejected approaches (with reasons)
  LOG.md          # append-only: every loop iteration, what changed, what was observed
```

**Before acting**, an agent MUST read: `CLAUDE.md` → `TASKS.md` → `CONTRACTS.md` → last 20 lines of `LOG.md`.
**After acting**, an agent MUST: append to `LOG.md`, update its row in `TASKS.md`, and — if it added or
changed a feature, module, or convention — update `CLAUDE.md`.

`CLAUDE.md` is loaded into every prompt, so keep it lean. Durable facts only. Anything that churns daily
belongs in `/.agents/`.

**`/.agents/CONTRACTS.md` in this repo is canonical for the whole product line.** The Pro add-on mirrors it
read-only. Anything an outside plugin can hook or read lives there — currently:

| Kind | Names |
|---|---|
| Filters | `cpiu_admin_tabs`, `cpiu_save_global_settings`, `cpiu_sanitize_configuration`, `cpiu_product_config`, `cpiu_show_image_preview`, `cpiu_allowed_mime_types`, `cpiu_allowed_extensions`, `cpiu_client_ip` |
| Actions | `cpiu_global_settings_fields`, `cpiu_config_form_fields`, `cpiu_file_uploaded`, `cpiu_upload_attempt`, `cpiu_cleanup_guest_uploads` |
| Options | `cpiu_settings`, `cpiu_global_settings`, `cpiu_default_settings`, `cpiu_multi_product_configs` (non-autoload), `cpiu_keep_data_on_uninstall`, `cpiu_installation_date` |
| Order item meta | `_cpiu_uploaded_images`, `_cpiu_original_filenames`, sentinel `_cpiu_images_cleaned` |
| Admin page | menu slug `cpiu-settings` → screen id `toplevel_page_cpiu-settings` |
| Script handles | `cpiu-admin-multi-product`, `cpiu-admin-notices`, `cpiu-frontend-multi-product`, `cpiu-cropper` |
| Public endpoint | `?cpiu_file=<name>&cpiu_token=<hmac>[&download=1]` on `init` |

Task row format:
```
| T-014 | Per-product max upload size override | @be | in_review | blocked_by: T-012 | AC: value clamps to php ini limit; existing configs without the key fall back to global |
```

---

## 3. Existing-System Rules (this is a brownfield, shipping plugin)

Mandatory before writing any code:

1. **Search before you build.** Grep the codebase for the capability first. `CPIU_Data_Manager` already
   owns settings read/write/sanitize; `CPIU_Secure_Upload` already owns validation; `CPIU_Ajax_Handler`
   already owns nonce+capability boilerplate. Duplicating one of these is an automatic
   `CHANGES REQUESTED` from `@review`.
2. **Read the neighbours.** Open the files adjacent to the one you're editing and match their patterns —
   the `cpiu_` prefix, the numbered section banners in the main file, the `phpcs:ignore` comments *with a
   stated reason*, the singleton on `CPIU_Data_Manager`. New code that doesn't look like the surrounding
   code is a defect, even if it works.
3. **Declare the blast radius before you act.** In `PLAN`, list which modules — **including the Pro
   add-on** — consume the thing you're about to touch. If that list is empty because you didn't check, you
   failed the step.
4. **Additive by default.** Prefer a new path over modifying a shared one. If a shared function must
   change, keep the old signature working (default params, adapter) unless `@manager` approves a breaking
   change.
5. **No silent deletion or refactor.** Removing, renaming, or "cleaning up" existing code is a separate
   task with its own approval. Never bundle a refactor into a feature task. This especially covers the
   `phpcs:ignore` lines — each one was argued for; deleting one silently re-opens a resolved review.
6. **Stored data changes are not free (see §0).** `@data` owes: the upgrade routine, the version gate that
   runs it once, a statement of what happens to a site that has the old shape, and a manual verification
   on a DB that actually contains the old shape. A change with no upgrade path is rejected.
7. **If the code contradicts `CLAUDE.md`, the code wins** — fix `CLAUDE.md` in the same pass and flag it to
   `@manager`.
8. **Any task touching the shared layer escalates automatically.** `@manager` notifies every consuming role
   *before* work starts. Shared layer here = `CPIU_Data_Manager`, `CPIU_Secure_Upload`, the file-serving
   endpoint, and every name in the `CONTRACTS.md` table above.

### 3.8 WordPress.org guideline gate (free plugin only)

This plugin targets the WordPress.org directory. `@compliance` blocks the merge if any of these regress:

- **No phone-home, no bundled marketplace SDK, no analytics, no external asset loading.** All JS/CSS is
  local (`select2`, `cropper` are bundled deliberately). Licensing lives in the Pro add-on — never here.
- **Text domain is the literal string `'nowdigiverse-product-image-upload'`** in every i18n call, matching
  the slug. Never a variable, never a constant. `Domain Path: /i18n/languages`.
- **No translations bundled** beyond the `.pot` — WP.org ships them.
- **GPLv2-or-later** headers intact; every bundled third-party asset GPL-compatible.
- **No admin nags outside our own screens.** Notices check `get_current_screen()` — see the installation
  and data-management notices. A global nag is a rejection.
- **Escaping/sanitization clean under Plugin Check**, or a `phpcs:ignore` with a written justification.
- **`Requires at least`, `Requires PHP`, `WC requires at least`, `WC tested up to`** headers stay truthful
  and in sync with `readme.txt`.

### 3.9 The free ↔ Pro boundary

- **This repo must not know the Pro add-on exists**, beyond the reserved-section comment in the main file.
  No `class_exists('CPIU_Pro')`, no upsell UI, no feature flags gated on a license.
- **Extension happens only through the documented hooks.** If Pro needs something new, the answer is a
  **new hook added here**, specced by `@spec`, recorded in `CONTRACTS.md`, and released — not a special
  case wired into base code.
- **Removing or changing the signature of any hook, option, meta key, script handle, or admin screen id in
  the `CONTRACTS.md` table is a breaking change for Pro.** It requires `@manager` approval and a paired
  task in the Pro repo, opened *before* the change merges here.
- Pro is never submitted to WordPress.org. Nothing about its distribution belongs in this repo's
  `readme.txt`.

---

## 4. @mention Routing Protocol

When the user writes `@fe fix the cropper aspect-ratio dropdown on the product config screen`:

1. `@manager` intercepts first — always. It does not just forward.
2. `@manager` checks: is this well-specified? Does it touch a contract (§2 table), the shared layer, or a
   §0 invariant? Does it have a dependency?
   - Under-specified → route to `@spec` first.
   - Touches a contract → notify every consuming role, **including the Pro repo**, before work starts.
   - Touches uploads, nonces, capabilities, escaping, or `$wpdb` → `@sec` is added as a mandatory reviewer.
   - Touches headers, readme, i18n, or bundled assets → `@compliance` is added as a mandatory reviewer.
   - Has a dependency → order it; don't parallelize into a conflict.
3. `@manager` writes the task into `TASKS.md` with acceptance criteria, then hands off.
4. The named role runs the loop (§5).
5. `@manager` closes the loop with the user in one short status message — not a transcript dump.

If the user @mentions a role directly and `@manager` disagrees with the routing, it says so in one line and
proceeds with the better route, stating why.

---

## 5. The Execution Loop (loop engineering)

Every agent runs this. No exceptions, no shortcuts.

```
RECALL  → read CLAUDE.md + CONTRACTS.md. What already exists that solves part of this?
          What consumes the code I'm about to touch — in this plugin AND in Pro? (blast radius)
PLAN    → state the smallest next change and what you expect to happen
ACT     → make exactly that change (one concern per iteration), reusing existing patterns
OBSERVE → run it in the Local site. Capture real output: browser behaviour, PHP notices from
          debug.log, AJAX response payload, Plugin Check results, the diff
VERIFY  → new acceptance criteria PASS *and* the core-flow regression (§5.1) is still green
REFLECT → if pass: update CLAUDE.md (if needed) + LOG.md, hand off.
          if fail: state the delta, form ONE new hypothesis
REPEAT  → max 5 iterations, then escalate
```

### 5.1 The core-flow regression suite (there are no automated tests — this is the safety net)

`@qa` re-runs these before **every** handoff, not just at the end. A green feature with a broken core flow
is a failed loop.

1. **Configure**: create/edit a per-product configuration in the admin → save → reload → values persisted.
2. **Front-end upload**: on a configured product, upload an image → crop → add to cart → preview renders.
3. **PDF path**: upload a PDF where allowed → validation passes → thumbnail/label correct.
4. **Rejection path**: oversize file, wrong extension, and a `.php` renamed to `.jpg` are all **rejected**.
5. **Cart → order**: checkout → order item shows the uploads → admin order screen shows them → the
   emailed/served file link opens (HMAC token valid) and a tampered token returns 403.
6. **Cleanup**: `cpiu_cleanup_guest_uploads` runs without fatals and does not delete files attached to
   orders.
7. **Deactivate → reactivate**: no data loss, no duplicate options, upload dir protections re-written.
8. **Plugin Check**: no new errors.
9. **With Pro active** (when Pro is installed locally): its tabs still render and its hooks still fire.

Loop discipline:
- **One variable per iteration.** Changing three things and re-running teaches you nothing.
- **Never repeat a failed hypothesis.** `CONTEXT.md` records rejected approaches; check it before proposing.
- **Verification is external.** "Should work" is not verification. Load the page, read `debug.log`, show
  the output. PHP is not type-checked at edit time — an unrun file is an unverified file.
- **Escalation format** (after 5 iterations):
  ```
  BLOCKED: T-014
  Tried: 1) ... 2) ... 3) ...
  Observed: <actual errors / debug.log excerpts>
  Hypothesis space exhausted because: <reason>
  Need from user: <the one specific decision or fact>
  ```

---

## 6. Handoff Message Format

Agents talk to each other in this exact shape. No prose essays.

```
FROM: @be
TO:   @sec, @review, @qa
TASK: T-014
REUSED: CPIU_Data_Manager::sanitize_configuration() + the existing ajax nonce helper — no new abstraction.
DONE: per-product max upload size override, clamped to the PHP ini limit.
CHANGED: includes/class-cpiu-data-manager.php, includes/class-cpiu-secure-upload.php,
         assets/js/cpiu-admin-multi-product.js
BLAST RADIUS: admin config form, frontend validation, and Pro's cpiu_sanitize_configuration filter
              (CPIU_Pro_Pricing hooks the same filter at priority 10 — ordering verified).
CONTRACT DELTA: `cpiu_multi_product_configs` rows gain optional `max_file_size`; absent = fall back to
                global. No key renamed. CONTRACTS.md updated.
EVIDENCE: core flows 1-8 green (screenshots attached), Plugin Check clean, debug.log empty.
RISKS: configs saved before this change have no key — fallback path is the only thing keeping them working.
NEEDS: @sec on the clamp (is ini_get('upload_max_filesize') parsed correctly for 'M' vs 'MB'?).
```

`@review`, `@sec`, and `@compliance` each reply with `APPROVED` or `CHANGES REQUESTED:` + a numbered list.
Nothing else.

---

## 7. Conflict Rules

- Two agents want to edit the same file → `@manager` serializes them. Never parallel-edit.
- A Dev disagrees with the spec → they say so **before** implementing, once, with a reason. Then they
  implement whatever `@manager` decides.
- Reviewer and Dev deadlock (2 rounds) → `@manager` breaks the tie and records it in `CONTEXT.md`.
- **`@sec` and `@compliance` cannot be overruled by `@manager` alone.** A security or guideline objection
  is escalated to the user with the trade-off stated. Shipping past one is the user's call, recorded in
  `CONTEXT.md`.
- Contract change requested mid-task → work stops, `@spec` updates `CONTRACTS.md`, all consumers
  (including the Pro repo) are re-notified, then work resumes.

---

## 8. Definition of Done

A task closes only when all are true:
1. Every acceptance criterion in `TASKS.md` is verified with evidence.
2. Nothing that worked before is broken — the §5.1 core-flow regression is green.
3. No duplicated logic: `@review` confirmed existing modules were reused, not re-created.
4. `@review` approved — plus `@sec` and/or `@compliance` where §4 made them mandatory.
5. `CLAUDE.md`, `CONTRACTS.md`, and `CONTEXT.md` reflect reality after the change.
6. `@integrate` merged, versions bumped consistently (§8.1), and the release zip installs cleanly on a
   fresh site.
7. **Status is updated EVERYWHERE it lives — see §8.1. This is not optional and not "later".**
8. `@manager` posted a 3-line summary: what changed, what to test, what's still open.

### 8.1 ⚠️ Always update status — and version — everywhere it lives

**A task's status is written in more than one place. Stale status is a defect** — it is how two people
build the same thing, or how a merged feature sits looking unfinished. Update **every** one of these, in
the **same commit** as the work:

| Where | What to update |
|---|---|
| `/.agents/TASKS.md` | move the row out of **In flight** into **Done**, with the merge date + commit |
| `/.agents/LOG.md` | append what changed and the **evidence** (flows run, Plugin Check result) |
| `/.agents/CONTRACTS.md` | only if a hook, option, meta key, handle, or endpoint changed |
| `CLAUDE.md` | only if a module, convention, or landmine changed |
| `readme.txt` | `Stable tag`, `Tested up to`, and a **changelog entry** — user-facing changes are not done until described |
| Plugin header + `CPIU_VERSION` | **both**, to the same number. Two places, one value — a mismatch ships broken cache-busting |

**Update it at every transition, not just at the end:** `todo → in_progress` when you pick it up,
`→ in_review` when you hand off, `→ done` **when it actually merges** — not when it's handed off.

**Ticking a box you did not do is worse than leaving it blank.** If a Definition-of-Done item does not
apply, write *why* next to it — and if you find yourself reaching for "n/a", that is the moment to re-read
the rule you are about to skip, because it usually does apply.

Rule of thumb: **if the board and the code disagree, the board is a lie.** Fix it the moment you notice.

---

## 9. Failure Modes to Actively Avoid

- Rebuilding something `CPIU_Data_Manager` or `CPIU_Secure_Upload` already does because searching felt
  slower than writing.
- "Finishing the rebrand" by renaming `cpiu_*` options, meta keys, or hooks — a data-loss change wearing a
  cleanup costume, and an instant break of the Pro add-on.
- Fixing the admin screen in a way that quietly breaks the cart/order path, then reporting success.
- Removing a `phpcs:ignore` or a validation layer because it "looked redundant".
- Escaping late — building an HTML string and escaping the whole blob, instead of escaping each value.
- Refactoring "while I was in there" — unrequested, unreviewed, unbounded.
- Treating `CLAUDE.md` as documentation to skim instead of the constraint set to obey.
- Agents summarizing instead of reading the actual files.
- Manager becoming a relay bot that adds no decisions.
- Devs marking work done based on reasoning rather than loading the page. **PHP fails at runtime, not at
  edit time.**
- Silent contract drift — the Pro add-on assumes a hook or screen id this plugin renamed.
- Infinite polite loops: "looks good to me" → "great, looks good to me too" → nothing verified.
- Dumping the whole internal conversation on the user. The user gets decisions and status, not chatter.

---

## 10. First Action on Any New Session

If `CLAUDE.md` is missing or stale, `@manager`'s first task is to rebuild it: `@be`, `@fe`, and `@data`
each inventory their side of the existing codebase — shipped modules, shared utilities, conventions,
landmines — and `@manager` merges the result. **No feature work starts before this exists.** An agent that
doesn't know what's already built will rebuild it.

Then, every session, `@manager` outputs before anything else:

```
SYSTEM: <modules, shared layer, current landmines — from CLAUDE.md>
BOARD: <open tasks, owners, blockers>
CONTRACT STATUS: <clean / drifted — including drift vs the Pro add-on>
NEXT: <the single highest-leverage task and who owns it>
```

Then waits for the user's @mention.
