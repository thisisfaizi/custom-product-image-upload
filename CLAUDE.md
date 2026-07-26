# CLAUDE.md — NDV Product Image Upload for WooCommerce

What this system **is**. For how the team works, see `AGENTS.md`. For current tasks/decisions/log, see
`/.agents/`. This file is loaded into every prompt — durable facts only; anything that churns daily belongs
in `/.agents/`.

## What it does

Lets WooCommerce customers (guest or logged-in) upload and crop images/PDFs on a product page before
add-to-cart, per-product rules (file count, size, type, resolution, cropping shape/ratio). Free plugin,
**live on WordPress.org**. A paid add-on (`../custom-product-image-upload-pro/`) extends it via hooks only.

## Identity

| | |
|---|---|
| Slug (permanent, WP.org) | `nowdigiverse-product-image-upload` |
| Display name | NDV Product Image Upload for WooCommerce |
| Internal prefix (permanent, see below) | `cpiu_` |
| Version | 1.0.0 (main file + `readme.txt` `Stable tag` — must match, `AGENTS.md` §8.1) |
| Floors | PHP 7.2 · WP 5.9 · WooCommerce 3.5 |
| Upload dir on disk | `wp-content/uploads/custom_product_images/` (constant `CPIU_UPLOAD_DIR_NAME`) |

## Modules (`includes/`)

| Class | File | Owns |
|---|---|---|
| `CPIU_Data_Manager` | `class-cpiu-data-manager.php` | Singleton (`::instance()`). All settings/config read, write, sanitize. `$default_config` / `$default_settings` are the schema. |
| `CPIU_Secure_Upload` | `class-cpiu-secure-upload.php` | Upload validation and storage: extension allowlist, MIME sniff, size limit, filename generation, the layered security model. |
| `CPIU_Ajax_Handler` | `class-cpiu-ajax-handler.php` | All `wp_ajax_*` endpoints. Nonce + capability boilerplate lives here — reuse it, don't reinvent per-handler. |
| `CPIU_Admin_Interface` | `class-cpiu-admin-interface.php` | The `cpiu-settings` admin page: tabs, forms, order-screen upload display. |
| `CPIU_Frontend_Manager` | `class-cpiu-frontend-manager.php` | Product-page upload UI, cart/checkout integration (classic + WooCommerce Blocks), order meta. |

Main file (`nowdigiverse-product-image-upload.php`) also owns: activation/deactivation, the cleanup cron,
the HMAC-authenticated public file-serving endpoint, and default-options helpers used by the activator.

**Before touching any of these:** read `CPIU_Data_Manager` and `CPIU_Secure_Upload` first — most new
feature work is either a new sanitized field (Data Manager) or a new validation rule (Secure Upload), not
a new module.

## Hook surface, options, meta keys

Canonical list with file:line references lives in **`/.agents/CONTRACTS.md`** — read it before adding,
removing, or renaming anything hookable. Summary: 8 filters, 5 actions, 8 options
(`cpiu_multi_product_configs` is **non-autoload** — it grows with every configured product), 3 order-item
meta keys, 1 HMAC-token public endpoint, 1 daily cleanup cron.

**This surface is frozen public API.** It's read by live shops and consumed by the Pro add-on. Changing a
name here is a breaking change requiring a paired task in the Pro repo (`AGENTS.md` §3.9).

## Conventions

- **Singleton** on `CPIU_Data_Manager` — always `::instance()`, never `new`.
- **Numbered section banners** (`// === N. Title ===`) structure the main plugin file — match the pattern
  when adding to it.
- **`phpcs:ignore` always carries a stated reason** in the comment. Each one was argued for in review;
  deleting one silently re-opens that argument.
- **Sanitize-then-`wp_parse_args`-against-defaults** is the shape of every sanitizer in `CPIU_Data_Manager`
  — build the sanitized array field-by-field, then merge over the class's default array so missing/legacy
  fields get a value. `apply_filters('cpiu_sanitize_configuration', $sanitized, $config)` fires at the end,
  which is how the Pro add-on attaches its own nested keys (e.g. `pricing`).
- **AJAX handlers**: nonce check, then `current_user_can()`, then work — every handler, that order.
- **File paths are always rebuilt** from `wp_upload_dir()` + a validated basename. Never concatenate
  request input into a path.
- **i18n**: the text domain is the literal string `'nowdigiverse-product-image-upload'` in every call —
  never a variable, per WP.org guidelines.

## Landmines

- **`cpiu_` prefix is permanent**, independent of the plugin's display branding. It was deliberately *not*
  renamed in the `custom-product-image-upload` → `nowdigiverse-product-image-upload` rebrand, because
  options, order-item meta, hooks, nonces and the Pro add-on all key off it. "Finishing the rebrand" by
  renaming it is a data-loss change, not a cleanup (`.agents/CONTEXT.md` D-001).
- **`CPIU_UPLOAD_DIR_NAME` (`custom_product_images`) is permanent** for the same reason — it's a directory
  on disk in every live install, referenced by URLs already stored in order-item meta and already sent in
  order emails (D-004).
- **This repo must never reference the Pro add-on.** No `class_exists('CPIU_Pro')`, no upsell UI, no
  license-gated behaviour. WordPress.org guidelines + the free/paid separation both require it (D-003). If
  Pro needs new capability, the answer is a new hook added *here*, not a special case.
- **The Pro add-on has drifted post-rebrand** (old menu slug, old dependency name) — that's the Pro repo's
  problem to fix, tracked there. Don't "helpfully" patch it from this repo.
- **`cpiu_multi_product_configs` must stay non-autoload.** It grows with every configured product; the
  activator re-adds it with autoload explicitly disabled on every activation, including upgrades from a
  version that had it autoloading.
- **No WP.org bundled translations, no external assets, no phone-home** — this plugin is in the directory;
  Plugin Check violations here block the merge, not just get flagged.
- **No automated test suite.** The safety net is the manual core-flow regression in `AGENTS.md` §5.1 —
  treat an unrun change as an unverified one.
