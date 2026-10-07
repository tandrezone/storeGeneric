/**
 * Storefront interactions. Talks to /api/cart via fetch.
 *
 * Everything uses event delegation on `document` (or re-inits on the
 * `ajax:load` event from ajax-nav.js), because <main> is swapped in place
 * when navigating without a page reload.
 */
(function () {
    const CART_ENDPOINT = '/api/cart';

    /** Translated text (i18n.js, loaded by the layout); the English text if it is missing. */
    function __(key, params) {
        if (window.StoreI18n) return window.StoreI18n.t(key, params);
        return key.replace(/\{(\w+)\}/g, (m, name) => (params && name in params ? String(params[name]) : m));
    }

    function __n(key, count, params) {
        if (window.StoreI18n) return window.StoreI18n.tn(key, count, params);
        return __(key, Object.assign({ count: count }, params || {}));
    }

    function notify(message, type) {
        if (window.AjaxNav) window.AjaxNav.toast(message, type);
        else if (type === 'error') alert(message);
    }

    /** Polite screen-reader announcement (live region shared with ajax-nav.js). */
    function announce(message) {
        if (window.AjaxNav && window.AjaxNav.announce) {
            window.AjaxNav.announce(message);
            return;
        }
        let el = document.getElementById('a11y-announcer');
        if (!el) {
            el = document.createElement('div');
            el.id = 'a11y-announcer';
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            el.style.cssText = 'position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap;';
            document.body.appendChild(el);
        }
        el.textContent = message;
    }

    function itemsText(count) {
        return __n('{count} item', Number(count));
    }

    // Currency format comes from data-currency-* on the cart table (set server-side from STORE_CURRENCY).
    function currencySpec() {
        const el = document.querySelector('[data-currency-symbol]');
        return {
            symbol: el ? el.dataset.currencySymbol : '',
            before: el ? el.dataset.currencyBefore === '1' : false,
            decimals: el ? parseInt(el.dataset.currencyDecimals, 10) || 0 : 2,
            decimal: (el && el.dataset.currencyDecimal) || '.',
            group: (el && el.dataset.currencyGroup) || ',',
        };
    }

    /** Bare number in the page's language ("1,234.50" / "1 234,50"), e.g. for #cart-total (the symbol sits outside it). */
    function formatMoney(value) {
        const spec = currencySpec();
        const parts = Number(value).toFixed(spec.decimals).split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, spec.group);
        return parts.join(spec.decimal);
    }

    /** Number with the currency symbol on the right side. */
    function formatPrice(value) {
        const spec = currencySpec();
        const amount = formatMoney(value);
        return spec.before ? spec.symbol + amount : amount + spec.symbol;
    }

    function updateCartCount(count) {
        const badge = document.getElementById('cart-count');
        if (badge) badge.textContent = count;
    }

    function updateCartTotal(total) {
        const el = document.getElementById('cart-total');
        if (el) el.textContent = formatMoney(total);
    }

    /** Cart emptied: re-render the cart page so the "empty" state shows. */
    function handleEmptyCart(data) {
        if (data.items.length === 0 && window.AjaxNav) window.AjaxNav.reload();
    }

    async function cartRequest(action, params = {}) {
        const body = new URLSearchParams({ action, ...params });
        const response = await fetch(CART_ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.error || __('Something went wrong.'));
        }
        return data;
    }

    async function refreshCartCount() {
        try {
            const response = await fetch(CART_ENDPOINT + '?action=get');
            const data = await response.json();
            if (data.success) updateCartCount(data.count);
        } catch (e) {
            // Silently ignore — badge just won't update.
        }
    }

    // --- Product page: add to cart ---
    document.addEventListener('submit', async function (e) {
        const addForm = e.target.closest('#add-to-cart-form');
        if (!addForm) return;
        e.preventDefault();

        const variantId = addForm.querySelector('#variant-select').value;
        const quantity = addForm.querySelector('#quantity-input').value;
        const message = document.getElementById('add-to-cart-message');
        const button = addForm.querySelector('[type="submit"]');

        if (button) button.disabled = true;
        try {
            const data = await cartRequest('add', { variant_id: variantId, quantity });
            updateCartCount(data.count);
            if (message) {
                // #add-to-cart-message is a live region: screen readers read this out.
                message.textContent = __('Added to cart. Your cart has {items}.', { items: itemsText(data.count) });
                message.className = 'form-message success';
            } else {
                announce(__('Added to cart.'));
            }
        } catch (err) {
            if (message) {
                message.textContent = err.message;
                message.className = 'form-message error';
            }
        } finally {
            if (button) button.disabled = false;
        }
    });

    // --- Cart page: update quantity ---
    document.addEventListener('change', async function (e) {
        const input = e.target.closest('.cart-qty-input');
        if (!input) return;

        const variantId = input.dataset.variantId;
        const row = input.closest('tr');
        input.disabled = true;
        try {
            const data = await cartRequest('update', { variant_id: variantId, quantity: input.value });
            updateCartCount(data.count);
            updateCartTotal(data.total);
            const item = data.items.find((i) => String(i.variant_id) === String(variantId));
            if (row && item) {
                row.querySelector('.line-subtotal').textContent =
                    formatPrice(item.price * item.quantity);
            } else if (row) {
                row.remove(); // quantity dropped to 0 and was removed server-side
            }
            announce(__('Cart updated: {items}, total {total}.', { items: itemsText(data.count), total: formatPrice(data.total) }));
            handleEmptyCart(data);
        } catch (err) {
            notify(err.message, 'error');
        } finally {
            input.disabled = false;
        }
    });

    // --- Cart page: remove line item ---
    document.addEventListener('click', async function (e) {
        const btn = e.target.closest('.cart-remove-btn');
        if (!btn) return;

        btn.disabled = true;
        try {
            const data = await cartRequest('remove', { variant_id: btn.dataset.variantId });
            updateCartCount(data.count);
            updateCartTotal(data.total);
            const row = btn.closest('tr');
            const cartSection = row && row.closest('main');
            row.remove();
            announce(__('Item removed. Cart: {items}, total {total}.', { items: itemsText(data.count), total: formatPrice(data.total) }));
            // The button that had focus is gone: keep keyboard users in the cart.
            const next = cartSection && cartSection.querySelector('.cart-remove-btn, .cart-qty-input, h1');
            if (next) {
                if (next.tagName === 'H1' && !next.hasAttribute('tabindex')) next.setAttribute('tabindex', '-1');
                next.focus();
            }
            handleEmptyCart(data);
        } catch (err) {
            btn.disabled = false;
            notify(err.message, 'error');
        }
    });

    // --- Product page: image gallery ---
    function initGalleries(root) {
        root.querySelectorAll('[data-gallery]:not([data-gallery-ready])').forEach((gallery) => {
            gallery.dataset.galleryReady = '1';
            const slides = Array.from(gallery.querySelectorAll('.gallery-slide'));
            const thumbs = Array.from(gallery.querySelectorAll('.gallery-thumb'));
            if (slides.length <= 1) return;

            let current = 0;

            function show(index) {
                current = (index + slides.length) % slides.length;
                slides.forEach((slide, i) => slide.classList.toggle('is-active', i === current));
                thumbs.forEach((thumb, i) => {
                    thumb.classList.toggle('is-active', i === current);
                    thumb.setAttribute('aria-pressed', i === current ? 'true' : 'false');
                });
            }

            thumbs.forEach((thumb) => {
                thumb.addEventListener('click', () => show(Number(thumb.dataset.index)));
            });

            const prevBtn = gallery.querySelector('.gallery-prev');
            const nextBtn = gallery.querySelector('.gallery-next');
            if (prevBtn) prevBtn.addEventListener('click', () => show(current - 1));
            if (nextBtn) nextBtn.addEventListener('click', () => show(current + 1));
        });
    }

    /** Accessible names for cart controls whose markup has none (product name from the row). */
    function labelCartControls(root) {
        root.querySelectorAll('#cart-items-body tr').forEach((row) => {
            const name = (row.querySelector('td a, td') || {}).textContent;
            if (!name) return;
            const qty = row.querySelector('.cart-qty-input:not([aria-label]):not([id])');
            if (qty) qty.setAttribute('aria-label', __('Quantity of {name}', { name: name.trim() }));
            const remove = row.querySelector('.cart-remove-btn:not([aria-label])');
            if (remove) {
                remove.setAttribute('aria-label', __('Remove {name} from cart', { name: name.trim() }));
                if (!remove.getAttribute('type')) remove.setAttribute('type', 'button');
            }
        });
    }

    initGalleries(document);
    labelCartControls(document);
    document.addEventListener('ajax:load', (e) => {
        initGalleries(e.detail.main);
        labelCartControls(e.detail.main);
    });

    refreshCartCount();
})();
