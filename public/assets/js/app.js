/**
 * Storefront interactions. Talks to /api/cart via fetch.
 *
 * Everything uses event delegation on `document` (or re-inits on the
 * `ajax:load` event from ajax-nav.js), because <main> is swapped in place
 * when navigating without a page reload.
 */
(function () {
    const CART_ENDPOINT = '/api/cart';

    function notify(message, type) {
        if (window.AjaxNav) window.AjaxNav.toast(message, type);
        else if (type === 'error') alert(message);
    }

    function formatMoney(value) {
        return Number(value).toFixed(2);
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
            throw new Error(data.error || 'Something went wrong.');
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
                message.textContent = 'Added to cart.';
                message.className = 'form-message success';
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
                    formatMoney(item.price * item.quantity) + '€';
            } else if (row) {
                row.remove(); // quantity dropped to 0 and was removed server-side
            }
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
            btn.closest('tr').remove();
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
                thumbs.forEach((thumb, i) => thumb.classList.toggle('is-active', i === current));
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

    initGalleries(document);
    document.addEventListener('ajax:load', (e) => initGalleries(e.detail.main));

    refreshCartCount();
})();
