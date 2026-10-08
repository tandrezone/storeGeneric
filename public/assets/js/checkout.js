/**
 * Checkout page: keeps the shipping cost, discount, VAT and total in sync with
 * the chosen shipping method and country (asks the server for a quote — it
 * knows the VAT rates and the applied discount code), and hides methods that
 * don't deliver to the typed country (the server re-checks everything).
 */
(function () {
    var form = document.querySelector('[data-checkout]');
    if (!form) return;
    var subtotal = parseFloat(form.dataset.subtotal) || 0;
    var discount = parseFloat(form.dataset.discount) || 0;
    var quoteUrl = form.dataset.quoteUrl;
    var shippingEl = document.getElementById('checkout-shipping-cost');
    var totalEl = document.getElementById('checkout-total');
    var subtotalEl = document.getElementById('checkout-subtotal');
    var discountRow = document.getElementById('checkout-discount-row');
    var discountEl = document.getElementById('checkout-discount');
    var taxRow = document.getElementById('checkout-tax-row');
    var taxEl = document.getElementById('checkout-tax');
    var taxIncludedRow = document.getElementById('checkout-tax-included-row');
    var taxIncludedEl = document.getElementById('checkout-tax-included');
    var couponInput = document.getElementById('coupon_code');
    var couponApply = document.querySelector('[data-coupon-apply]');
    var quoteTimer = null;
    var quoteSeq = 0;
    var countryInput = document.getElementById('country');
    var noShipping = document.querySelector('[data-no-shipping]');
    var radios = Array.prototype.slice.call(document.querySelectorAll('input[name="shipping_method"]'));

    // Same format as the server's `money` filter (App\Support\Money::spec()).
    var symbol = form.dataset.currencySymbol || '€';
    var symbolBefore = form.dataset.currencyBefore === '1';
    var decimalPoint = form.dataset.currencyDecimal || '.';
    var groupSeparator = form.dataset.currencyGroup || ',';
    var decimals = parseInt(form.dataset.currencyDecimals, 10);
    if (isNaN(decimals)) decimals = 2;

    function formatMoney(value) {
        var parts = Number(value).toFixed(decimals).split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, groupSeparator);
        var number = parts.join(decimalPoint);
        return symbolBefore ? symbol + number : number + symbol;
    }

    function setRow(row, valueEl, visible, text) {
        if (!row) return;
        row.hidden = !visible;
        if (valueEl) valueEl.textContent = text;
    }

    function showQuote(q) {
        if (subtotalEl) subtotalEl.textContent = formatMoney(q.subtotal);
        shippingEl.textContent = formatMoney(q.shipping);
        setRow(discountRow, discountEl, q.discount > 0, '−' + formatMoney(q.discount));
        var label = q.tax_label || '';
        [taxRow, taxIncludedRow].forEach(function (row) {
            if (!row) return;
            var labelEl = row.querySelector('[data-tax-label]');
            if (labelEl) labelEl.textContent = label;
        });
        setRow(taxRow, taxEl, !!label && !q.prices_include_tax, formatMoney(q.tax_amount));
        setRow(taxIncludedRow, taxIncludedEl, !!label && q.prices_include_tax, formatMoney(q.tax_amount));
        totalEl.textContent = formatMoney(q.total);
    }

    // Immediate estimate from the radio's cost, then the server's exact quote.
    function updateTotals() {
        var checked = radios.filter(function (r) { return r.checked && !r.disabled; })[0];
        var cost = checked ? parseFloat(checked.dataset.cost) || 0 : 0;
        shippingEl.textContent = formatMoney(cost);
        if (taxRow && taxRow.hidden) {
            totalEl.textContent = formatMoney(Math.max(0, subtotal - discount + cost));
        }
        requestQuote(checked ? checked.value : '');
    }

    function requestQuote(method) {
        if (!quoteUrl || !window.fetch) return;
        clearTimeout(quoteTimer);
        quoteTimer = setTimeout(function () {
            var seq = ++quoteSeq;
            var params = 'country=' + encodeURIComponent((countryInput && countryInput.value || '').trim())
                + '&shipping_method=' + encodeURIComponent(method);
            fetch(quoteUrl + '?' + params, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (q) { if (q && q.success && seq === quoteSeq) showQuote(q); })
                .catch(function () { /* keep the estimate; the server re-prices the order anyway */ });
        }, 250);
    }

    // Enter in the discount field applies the code instead of placing the order.
    if (couponInput && couponApply) {
        couponInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                couponApply.click();
            }
        });
    }

    // Hide methods that don't deliver to the typed country. The server
    // re-checks this, so it's only a convenience.
    function filterByCountry() {
        var country = (countryInput.value || '').trim().toUpperCase();
        var visible = 0;
        radios.forEach(function (radio) {
            var list = [];
            try { list = JSON.parse(radio.dataset.countries || '[]'); } catch (e) {}
            var serves = !country || list.length === 0 || list.indexOf(country) !== -1;
            radio.disabled = !serves;
            radio.closest('label').style.display = serves ? '' : 'none';
            if (!serves && radio.checked) radio.checked = false;
            if (serves) visible++;
        });
        if (!radios.some(function (r) { return r.checked; })) {
            var first = radios.filter(function (r) { return !r.disabled; })[0];
            if (first) first.checked = true;
        }
        if (noShipping) noShipping.style.display = (visible > 0 || radios.length === 0) ? 'none' : '';
        updateTotals();
    }

    radios.forEach(function (radio) { radio.addEventListener('change', updateTotals); });
    if (countryInput) {
        countryInput.addEventListener('input', filterByCountry);
        filterByCountry();
    }

    // Signed-in customers: picking a saved address fills the shipping fields
    // (without JavaScript its "Use" button reloads the page with it instead).
    var savedAddress = document.querySelector('[data-saved-address]');
    if (savedAddress) {
        savedAddress.addEventListener('change', function () {
            var option = savedAddress.options[savedAddress.selectedIndex];
            var address = {};
            try { address = JSON.parse(option.dataset.address || '{}'); } catch (e) { return; }
            ['name', 'phone', 'address1', 'address2', 'city', 'state', 'postal_code', 'country'].forEach(function (field) {
                var input = form.querySelector('[name="' + field + '"]');
                if (input) input.value = address[field] || '';
            });
            var idInput = form.querySelector('[name="address_id"]');
            if (idInput) idInput.value = option.value;
            if (countryInput) filterByCountry();
        });
    }
})();
