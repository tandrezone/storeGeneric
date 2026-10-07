/**
 * Checkout page: keeps the shipping cost and total in sync with the chosen
 * shipping method, and hides methods that don't deliver to the typed country
 * (the server re-checks both).
 */
(function () {
    var form = document.querySelector('[data-checkout]');
    if (!form) return;
    var subtotal = parseFloat(form.dataset.subtotal) || 0;
    var shippingEl = document.getElementById('checkout-shipping-cost');
    var totalEl = document.getElementById('checkout-total');
    var countryInput = document.getElementById('country');
    var noShipping = document.querySelector('[data-no-shipping]');
    var radios = Array.prototype.slice.call(document.querySelectorAll('input[name="shipping_method"]'));

    function updateTotals() {
        var checked = radios.filter(function (r) { return r.checked && !r.disabled; })[0];
        var cost = checked ? parseFloat(checked.dataset.cost) || 0 : 0;
        shippingEl.textContent = cost.toFixed(2) + '€';
        totalEl.textContent = (subtotal + cost).toFixed(2) + '€';
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
})();
