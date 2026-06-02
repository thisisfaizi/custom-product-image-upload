/**
 * WooCommerce Blocks Integration for Custom Product Image Upload
 * Simplified version that shows confirmation messages instead of images
 */

(function() {
    'use strict';

    // Configuration
    const config = {
        selectors: {
            cartItems: '.wc-block-cart-items__row, .wc-block-cart-item, .cart_item',
            checkoutItems: '.wc-block-checkout-order-summary__item, .wc-block-order-summary-item, .checkout-product'
        },
        retryLimit: 5,
        retryDelay: 1000
    };

    // State management
    const state = {
        initialized: false,
        retryCount: 0,
        processedItems: new Set(),
        cartData: null
    };

    /**
     * Get cart data from multiple sources
     */
    function getCartDataFromMultipleSources() {
        // Source 1: Direct PHP output
        if (window.cpiuCartData) {
            return window.cpiuCartData;
        }

        // Source 2: WooCommerce settings
        if (window.wc && window.wc.wcSettings) {
            const cartData = window.wc.wcSettings.getSetting('cpiu_cart_data');
            if (cartData) {
                return cartData;
            }
        }

        return null;
    }

    /**
     * Create confirmation element
     */
    function createConfirmationElement(message) {
        const container = document.createElement('div');
        container.className = 'cpiu-cart-confirmation';
        container.style.cssText = 'display: flex; align-items: center; color: #28a745; font-weight: 500; margin-top: 8px;';
        
        const checkmark = document.createElement('span');
        checkmark.textContent = '✓';
        checkmark.style.cssText = 'margin-right: 8px; font-size: 16px;';
        
        const text = document.createElement('span');
        text.textContent = message || 'Your images are uploaded';
        
        container.appendChild(checkmark);
        container.appendChild(text);
        
        return container;
    }

    /**
     * Extract product ID from cart item element
     */
    function extractProductId(itemElement) {
        // Try various methods to get product ID
        const productLink = itemElement.querySelector('a[href*="product"]');
        if (productLink) {
            const href = productLink.getAttribute('href');
            const match = href.match(/product[\/\-]([^\/\?]+)/);
            if (match) {
                return match[1];
            }
        }

        // Try data attributes
        const dataProduct = itemElement.getAttribute('data-product-id') || 
                           itemElement.getAttribute('data-product') ||
                           itemElement.querySelector('[data-product-id]')?.getAttribute('data-product-id');
        
        if (dataProduct) {
            return dataProduct;
        }

        return null;
    }

    /**
     * Display confirmation in cart/checkout item
     */
    function displayConfirmationInItem(itemElement, confirmationData) {
        // Check if already processed
        const itemId = itemElement.getAttribute('data-cpiu-processed');
        if (itemId) {
            return;
        }

        // Find insertion point
        let insertionPoint = itemElement.querySelector('.wc-block-cart-item__product, .product-name, .cart-item-info');
        if (!insertionPoint) {
            insertionPoint = itemElement;
        }

        // Create and insert confirmation element
        const confirmationElement = createConfirmationElement(confirmationData.message);
        
        if (insertionPoint === itemElement) {
            insertionPoint.appendChild(confirmationElement);
        } else {
            insertionPoint.parentNode.insertBefore(confirmationElement, insertionPoint.nextSibling);
        }

        // Mark as processed
        itemElement.setAttribute('data-cpiu-processed', 'true');
        state.processedItems.add(itemElement);
    }

    /**
     * Process a single cart item
     */
    function processCartItem(itemElement) {
        const productId = extractProductId(itemElement);
        if (!productId || !state.cartData) {
            return;
        }

        // Check if this product has uploaded images
        for (const [cartItemKey, cartItemData] of Object.entries(state.cartData)) {
            if (cartItemData.product_id == productId || cartItemData.product_id === productId) {
                if (cartItemData.has_uploaded_images) {
                    displayConfirmationInItem(itemElement, cartItemData);
                    break;
                }
            }
        }
    }

    /**
     * Process all cart and checkout items
     */
    function processAllItems() {
        const allSelectors = Object.values(config.selectors).join(', ');
        const items = document.querySelectorAll(allSelectors);
        
        items.forEach(item => {
            if (!state.processedItems.has(item)) {
                processCartItem(item);
            }
        });
    }

    /**
     * Initialize the integration
     */
    function initialize() {
        if (state.initialized) {
            return;
        }

        // Get cart data
        state.cartData = getCartDataFromMultipleSources();
        
        if (!state.cartData && state.retryCount < config.retryLimit) {
            state.retryCount++;
            setTimeout(initialize, config.retryDelay);
            return;
        }

        if (!state.cartData) {
            return; // No cart data available
        }

        state.initialized = true;
        processAllItems();
        setupObservers();
    }

    /**
     * Setup mutation observers for dynamic content
     */
    function setupObservers() {
        const observer = new MutationObserver(function(mutations) {
            let shouldProcess = false;
            
            mutations.forEach(function(mutation) {
                if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                    for (let node of mutation.addedNodes) {
                        if (node.nodeType === Node.ELEMENT_NODE) {
                            const allSelectors = Object.values(config.selectors).join(', ');
                            if (node.matches && node.matches(allSelectors)) {
                                shouldProcess = true;
                                break;
                            }
                            if (node.querySelector && node.querySelector(allSelectors)) {
                                shouldProcess = true;
                                break;
                            }
                        }
                    }
                }
            });
            
            if (shouldProcess) {
                setTimeout(processAllItems, 100);
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    /**
     * Handle page-specific initialization
     */
    function handlePageSpecificInit() {
        // Cart page
        if (document.body.classList.contains('woocommerce-cart') || 
            document.querySelector('.wc-block-cart')) {
            initialize();
            return;
        }

        // Checkout page
        if (document.body.classList.contains('woocommerce-checkout') || 
            document.querySelector('.wc-block-checkout')) {
            initialize();
            return;
        }

        // Generic WooCommerce page with cart/checkout blocks
        if (document.querySelector('.wc-block-cart-items, .wc-block-checkout-order-summary')) {
            initialize();
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', handlePageSpecificInit);
    } else {
        handlePageSpecificInit();
    }

    // Also initialize on window load as fallback
    window.addEventListener('load', function() {
        if (!state.initialized) {
            setTimeout(handlePageSpecificInit, 500);
        }
    });

})();