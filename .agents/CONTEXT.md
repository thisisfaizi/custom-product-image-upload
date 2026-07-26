# CONTEXT — NDV Product Image Upload (free base plugin)

Decisions made, constraints, and rejected approaches. Append; don't rewrite history.
See `AGENTS.md` for the protocol.

---

## Where we are

- This plugin was rebranded `custom-product-image-upload` → `nowdigiverse-product-image-upload`
  ("NDV Product Image Upload for WooCommerce") in commit `4b6d93e`, together with a WP.org compliance pass
  and a cropper rebuild.
- **It is approved and live on WordPress.org.** The slug is permanent — WP.org slugs cannot be changed.
- The paid add-on (`../custom-product-image-upload-pro/`) was **not** rebranded and has drifted. That work
  is tracked in the Pro repo, not here.

---

## Decisions

### D-001 — The `cpiu_` internal prefix is permanent. (2026-07-26)
The rebrand changed the **slug, plugin name, and text domain**. It deliberately did **not** change the
internal `cpiu_` prefix: option keys, order-item meta, hook names, nonces, AJAX actions, CSS classes,
constants, and the on-disk upload directory `custom_product_images`.
**Why:** the plugin is live. Those names are read by real shops (settings, order attachments) and by the
paid add-on. Renaming them destroys shop configuration and orphans customers' uploaded files.
**Consequence:** anyone who proposes "finishing the rebrand" by sweeping `cpiu_` → `ndv_` is proposing data
loss. Reject on sight; point them here.

### D-002 — The hook surface is frozen public API. (2026-07-26)
Now that the plugin is published, `CONTRACTS.md` is not internal documentation — it is a contract with
live installs and with the Pro add-on. Changes to it are breaking changes with a cross-repo process
(`AGENTS.md` §3.9).

### D-003 — This repo does not know the Pro add-on exists. (2026-07-26)
No `class_exists('CPIU_Pro')`, no upsell UI, no license-gated code paths, no bundled SDK, no phone-home.
Required by the WordPress.org guidelines and by the free/paid separation.
**When Pro needs something:** a **new hook is added here**, specced and released — never a special case.

### D-004 — `CPIU_UPLOAD_DIR_NAME` stays `custom_product_images`. (2026-07-26)
It is a directory on disk in every live install, containing customers' uploaded files, referenced by URLs
already stored in order-item meta and already sent in order emails. Frozen for the same reason as D-001.

---

## Decisions (cross-repo)

### T-006 — Pro's bulk-save gap fixed in the Pro repo, not here. (2026-07-26)
`disable_quantity` had no control in Pro's Bulk Operations form, so it was always absent from that form's
POST and silently forced to `false` by this repo's own `sanitize_configuration()` default-fill on every
product a bulk save touched. Fixed by widening Pro's bulk form (that repo's P-034), **not** by changing
`sanitize_configuration()`'s absent-field handling here — full reasoning in the Pro repo's `CONTEXT.md`
D-007. No base-repo code changed; recorded here only because the finding originated during this repo's
T-005 work and the decision needed a base-repo paper trail too.

## Open questions

| # | Question | Owner | Status |
|---|---|---|---|
| Q-001 | Was this plugin previously live on WP.org under the **old** slug `custom-product-image-upload`? | user | **closed 2026-07-26 — no.** The old slug was never published on WP.org; only the rebranded `nowdigiverse-product-image-upload` has ever been live. No stranded users, no migration path needed. |
| Q-002 | `readme.txt` says `Tested up to: 7.0` while the plugin header says `WC tested up to: 9.5`. Both need a routine refresh cadence — who owns it and when? | `@compliance` | **closed 2026-07-26 — T-003.** WP line was already accurate. WC line was stale, bumped to 10.9 after actually running the §5.1 regression against the real installed 10.9.4, not just on the strength of "nothing broke." Cadence recorded below. |

---

## Compatibility refresh cadence (T-003)

Re-run the `AGENTS.md` §5.1 core-flow regression and refresh both `readme.txt`'s `Tested up to` (WP)
and the plugin header's `WC tested up to` whenever either of these happens:
- The WooCommerce version installed on the reference dev site changes to a new minor version.
- At minimum, once per plugin release — never bump either number without having actually run the
  regression against that version first. "It didn't break during unrelated testing" is not evidence;
  the number is a compatibility claim merchants act on.

## Constraints

- **Live on WordPress.org** — every release goes through the directory; guideline compliance
  (`AGENTS.md` §3.8) is a merge gate, not a nice-to-have.
- **Live shops with real orders** — no destructive migrations, no key renames, deactivation never deletes.
- **Floor versions**: PHP 7.2, WordPress 5.9, WooCommerce 3.5. A 6.4+ API without a fallback is a defect.
  (See the `add_option(..., '', 'no')` autoload handling in the activator — it exists precisely because the
  6.4 autoload helper is off-limits.)
- **HPOS** — order queries branch on `OrderUtil::custom_orders_table_usage_is_enabled()`. Both storage
  paths must stay correct.
- **No automated test suite.** The safety net is the manual core-flow regression in `AGENTS.md` §5.1.
- **A downstream paid consumer exists.** Blast radius always includes the Pro repo.

---

## Rejected approaches

- **Sweeping `cpiu_` → `ndv_` to "finish the rebrand"** — data loss (D-001).
- **Renaming the upload directory to match the new branding** — orphans every uploaded file (D-004).
- **Adding an upsell notice / Pro feature teaser to the free plugin** — WP.org guideline risk and violates
  the free/paid separation (D-003).
- **Bundling the WPBay SDK here so both editions share one updater** — would disqualify the plugin from the
  directory. Licensing lives in the Pro repo only.
