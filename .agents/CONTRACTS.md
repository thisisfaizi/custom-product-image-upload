# CONTRACTS — NDV Product Image Upload (CANONICAL)

> **This file is canonical for the whole product line.** The Pro add-on
> (`../custom-product-image-upload-pro/.agents/CONTRACTS.md`) mirrors it read-only. If the two disagree,
> this file wins.
>
> **This plugin is live on WordPress.org.** Everything below is effectively **frozen public API**: live
> shops and the paid add-on depend on it. Removing or changing the signature of any entry requires
> `@manager` approval and a paired task in the Pro repo, opened *before* the change merges here.

**Base version:** 1.0.0 · **Last audited:** 2026-07-26 (read from source) · **Verified against the
published WP.org zip:** 2026-07-26, v1.0.0 (T-004) — every filter, action, option, order-item meta key,
constant, script/style handle, cron hook and the public endpoint confirmed present at matching locations.
Zero discrepancies. The working copy's 1.1.0 additions (`disable_quantity`) are pending release, not yet
in the published zip — expected, not a discrepancy.

---

## Filters

| Filter | Args | Purpose | Source |
|---|---|---|---|
| `cpiu_admin_tabs` | `$tabs` | Register additional admin tabs | `class-cpiu-admin-interface.php:94` |
| `cpiu_save_global_settings` | `$sanitized, $raw` | Extend/adjust global settings on save | `class-cpiu-data-manager.php:251` |
| `cpiu_sanitize_configuration` | `$sanitized, $config` | Extend per-product config sanitization | `class-cpiu-data-manager.php:727` |
| `cpiu_product_config` | `$config, $product_id` | Override the resolved per-product config | `class-cpiu-frontend-manager.php:622` |
| `cpiu_show_image_preview` | `true, $product_id, $config` | Suppress the default front-end preview | `class-cpiu-frontend-manager.php:625` |
| `cpiu_allowed_mime_types` | `$default_mime_types` | Extend the upload MIME allowlist | `class-cpiu-secure-upload.php:93` |
| `cpiu_allowed_extensions` | `$default_extensions` | Extend the upload extension allowlist | `class-cpiu-secure-upload.php:94` |
| `cpiu_client_ip` | `$ip` | Adjust client IP resolution (proxies/CDN) | `class-cpiu-ajax-handler.php:1031` |

⚠️ `cpiu_allowed_mime_types` and `cpiu_allowed_extensions` are **security-relevant**. Anything that widens
them is an `@sec` review, not a feature.

## Actions

| Action | Args | Purpose | Source |
|---|---|---|---|
| `cpiu_global_settings_fields` | `$global_settings` | Render extra fields on the global settings screen | `class-cpiu-admin-interface.php:385` |
| `cpiu_config_form_fields` | `array('context' => 'add'\|'edit', …)` | Render extra fields on the per-product config form | `class-cpiu-admin-interface.php:808, 1239` |
| `cpiu_file_uploaded` | `$upload_result, $product_id, $user_id` | Fires on each successful upload (3 call sites) | `class-cpiu-secure-upload.php:253, 332, 710` |
| `cpiu_upload_attempt` | `$log_entry` | Fires on every attempt, success or failure — the audit hook | `class-cpiu-secure-upload.php:745` |
| `cpiu_cleanup_guest_uploads` | — | Daily cron event; two core callbacks already attached | main file `:303-304` |

## Options

| Option | Autoload | Notes |
|---|---|---|
| `cpiu_settings` | yes | Legacy single-product settings (`CPIU_OPTIONS_NAME`) |
| `cpiu_global_settings` | yes | Global plugin settings |
| `cpiu_default_settings` | yes | Defaults for new per-product configs — **must mirror `CPIU_Data_Manager::$default_settings`** |
| `cpiu_multi_product_configs` | **no** | Per-product configs. Grows with every configured product — the non-autoload flag is deliberate (main file `:153, 160-162`). Do not re-add it autoloading |
| `cpiu_keep_data_on_uninstall` | yes | `keep` \| `delete` — the only thing that authorises data removal |
| `cpiu_installation_date` | yes | Set on fresh install |
| `cpiu_show_installation_notice` | yes | Cleared on deactivate |
| `cpiu_data_notice_dismissed` | yes | |

## Order item meta

| Key | Notes |
|---|---|
| `_cpiu_uploaded_images` | The uploaded file URLs for a line item |
| `_cpiu_original_filenames` | Original client-side filenames |
| `_cpiu_images_cleaned` | **Sentinel value** written into `_cpiu_uploaded_images` after cleanup — not a separate key |

## Admin surface

| Thing | Value |
|---|---|
| Menu slug | `cpiu-settings` |
| Screen id | `toplevel_page_cpiu-settings` |
| Settings URL | `admin.php?page=cpiu-settings` |

## Script / style handles

`cpiu-admin-multi-product` · `cpiu-admin-notices` · `cpiu-frontend-multi-product` · `cpiu-cropper`
(plus bundled `select2`, `cropper.min.js`)

## Public endpoints

| Endpoint | Auth |
|---|---|
| `?cpiu_file=<name>&cpiu_token=<hmac>[&download=1]` on `init` priority 1 | **HMAC only** — `hash_hmac('sha256', $filename, wp_salt('auth'))` verified with `hash_equals()`. Not a nonce, deliberately, so guest/email links work. Filename must match `/^prod-.*?\.(jpg\|jpeg\|png\|gif\|webp\|pdf)$/i` |

## AJAX actions (core)

`cpiu_dismiss_installation_notice` · `cpiu_set_data_preference` · `cpiu_dismiss_data_notice`
(plus the handlers registered in `CPIU_Ajax_Handler`)

## Cron

| Hook | Schedule | Callbacks |
|---|---|---|
| `cpiu_cleanup_guest_uploads` | daily | `cpiu_cleanup_abandoned_guest_uploads`, `cpiu_cleanup_completed_order_images` |

## Constants

`CPIU_PLUGIN_PATH` · `CPIU_PLUGIN_URL` · `CPIU_VERSION` · `CPIU_OPTIONS_GROUP` ·
`CPIU_OPTIONS_NAME` (`cpiu_settings`) · `CPIU_UPLOAD_DIR_NAME` (`custom_product_images`)

⚠️ `CPIU_UPLOAD_DIR_NAME` is `custom_product_images` — pre-rebrand naming, on disk, in live installs,
holding customers' files. **It is frozen.** Renaming it orphans every uploaded file on every existing site.

---

## Known consumers

| Consumer | What it uses |
|---|---|
| `custom-product-image-upload-pro` | `cpiu_admin_tabs`, `cpiu_global_settings_fields`, `cpiu_save_global_settings`, `cpiu_sanitize_configuration`, `cpiu_upload_attempt`, `cpiu_show_image_preview` (prio 20), the `cpiu-admin-multi-product` handle, and the `toplevel_page_cpiu-settings` screen id |
