/**
 * NDV Product Image Upload — block cart/checkout renderer.
 *
 * The WooCommerce block cart/checkout strips <img>/<div> from cart item_data
 * on the client, so uploaded-file thumbnails cannot be shown through the normal
 * item_data channel. This script renders them into each block line item using
 * the data provided in window.cpiuCartData (secured URLs + product permalink).
 *
 * Designed to be theme/builder agnostic: it targets the rendered DOM with a
 * broad set of selectors and re-runs on DOM mutations (block cart/checkout
 * re-render on quantity changes, coupons, etc.).
 */
(function () {
    'use strict';

    var PROCESSED_ATTR = 'data-cpiu-thumbs';

    // Line-item containers across classic + block cart/checkout and common builders.
    var ITEM_SELECTORS = [
        '.wc-block-cart-items__row',
        '.wc-block-cart-item',
        '.wc-block-components-order-summary-item',
        '.wc-block-order-summary-item',
        '.cart_item',
        '.mini_cart_item'
    ].join(', ');

    function getCartData() {
        return (window.cpiuCartData && typeof window.cpiuCartData === 'object') ? window.cpiuCartData : null;
    }

    /**
     * Build the thumbnail grid element for one cart item's files.
     * Inline styles are used so it renders even if the stylesheet is absent.
     */
    function buildThumbs(itemData) {
        var wrap = document.createElement('div');
        wrap.className = 'cpiu-cart-images-wrapper';
        wrap.style.cssText = 'margin-top:6px;width:100%;';

        if (itemData.heading) {
            var heading = document.createElement('div');
            heading.className = 'cpiu-cart-images-heading';
            heading.textContent = itemData.heading;
            heading.style.cssText = 'font-size:12px;font-weight:600;margin-bottom:4px;opacity:.85;';
            wrap.appendChild(heading);
        }

        var grid = document.createElement('div');
        grid.className = 'cpiu-cart-images-container';
        grid.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fill,minmax(48px,1fr));gap:6px;max-width:260px;';

        (itemData.files || []).forEach(function (file) {
            var cell;
            if (file.is_pdf) {
                cell = document.createElement('a');
                cell.href = file.url;
                cell.target = '_blank';
                cell.rel = 'noopener noreferrer';
                cell.textContent = '📄';
                cell.style.cssText = 'display:flex;align-items:center;justify-content:center;width:100%;max-width:60px;aspect-ratio:1/1;background:#f6f7f7;border:1px solid #ddd;border-radius:4px;font-size:22px;text-decoration:none;';
            } else {
                cell = document.createElement('a');
                cell.href = file.url;
                cell.target = '_blank';
                cell.rel = 'noopener noreferrer';
                cell.style.cssText = 'display:block;width:100%;max-width:60px;aspect-ratio:1/1;border:1px solid #ddd;border-radius:4px;overflow:hidden;background:#f6f7f7;';
                var img = document.createElement('img');
                img.src = file.url;
                img.loading = 'lazy';
                img.alt = '';
                img.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;';
                cell.appendChild(img);
            }
            grid.appendChild(cell);
        });

        wrap.appendChild(grid);
        return wrap;
    }

    /**
     * Match a rendered line item to its cart data.
     * Primary signal: an anchor whose href path matches the product permalink.
     * Fallback: if there is exactly one uploaded-file item in the cart, use it.
     */
    function matchItemData(itemEl, cartData) {
        var keys = Object.keys(cartData);

        // 1) Permalink match (robust in the cart block, which links product names).
        var anchors = itemEl.querySelectorAll('a[href]');
        for (var k = 0; k < keys.length; k++) {
            var data = cartData[keys[k]];
            if (!data.permalink) {
                continue;
            }
            for (var a = 0; a < anchors.length; a++) {
                var href = anchors[a].getAttribute('href') || '';
                if (href.indexOf(data.permalink) !== -1) {
                    return data;
                }
            }
        }

        // 2) Product-name match (checkout order summary often has no product link).
        var text = (itemEl.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
        if (text) {
            for (var n = 0; n < keys.length; n++) {
                var nd = cartData[keys[n]];
                if (nd.name && text.indexOf(nd.name.toLowerCase()) !== -1) {
                    return nd;
                }
            }
        }

        // 3) Fallback: single uploaded-file item in the whole cart.
        if (keys.length === 1) {
            return cartData[keys[0]];
        }

        return null;
    }

    function processItems() {
        var cartData = getCartData();
        if (!cartData || !Object.keys(cartData).length) {
            return;
        }

        var items = document.querySelectorAll(ITEM_SELECTORS);
        items.forEach(function (itemEl) {
            if (itemEl.getAttribute(PROCESSED_ATTR)) {
                return;
            }
            // Skip items that already show server-rendered thumbnails (classic
            // cart / mini-cart via woocommerce_get_item_data) to avoid duplicates.
            if (itemEl.querySelector('.cpiu-cart-images-container')) {
                itemEl.setAttribute(PROCESSED_ATTR, '1');
                return;
            }
            var data = matchItemData(itemEl, cartData);
            if (!data) {
                return;
            }
            itemEl.setAttribute(PROCESSED_ATTR, '1');

            // Prefer inserting after the product name/info block; fall back to the item itself.
            var host = itemEl.querySelector(
                '.wc-block-cart-item__product, .wc-block-components-order-summary-item__description, .product-name, .cart-item__name'
            ) || itemEl;
            host.appendChild(buildThumbs(data));
        });
    }

    var scheduled = false;
    function schedule() {
        if (scheduled) {
            return;
        }
        scheduled = true;
        setTimeout(function () {
            scheduled = false;
            processItems();
        }, 120);
    }

    function start() {
        processItems();

        // Block cart/checkout re-render on interaction; re-run on DOM changes.
        if (window.MutationObserver) {
            var observer = new MutationObserver(function () {
                schedule();
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }

    window.addEventListener('load', schedule);
})();
