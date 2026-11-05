/**
 * Responsive tables (progressive enhancement).
 *
 * On narrow screens the stylesheet turns every table with a header row into a
 * list of cards. A card needs to know which column each cell belongs to, so
 * this script copies the header text into a data-label attribute on every cell
 * and marks the table with .is-stackable. Without JavaScript the tables simply
 * scroll sideways instead.
 *
 * Runs on load, after every AJAX page swap (ajax:load) and whenever a table's
 * rows change (the cart re-renders its rows from JavaScript).
 */
(function () {
    'use strict';

    const SELECTOR = 'table.cart-table, table.variants-table';
    const observed = new WeakSet();

    function headerNames(table) {
        const row = table.tHead && table.tHead.rows[0];
        if (!row) return null;
        const names = [];
        Array.from(row.cells).forEach(function (th) {
            const text = th.textContent.replace(/[▲▼↑↓]/g, '').replace(/\s+/g, ' ').trim();
            for (let i = 0; i < Math.max(1, th.colSpan); i++) names.push(i === 0 ? text : '');
        });
        return names;
    }

    function label(table) {
        const names = headerNames(table);
        if (!names) return;
        Array.from(table.tBodies).forEach(function (body) {
            Array.from(body.rows).forEach(function (tr) {
                let col = 0;
                Array.from(tr.cells).forEach(function (td) {
                    if (td.colSpan > 1) { col += td.colSpan; return; }
                    const name = names[col] || '';
                    if (td.getAttribute('data-label') !== name) td.setAttribute('data-label', name);
                    col++;
                });
            });
        });
        table.classList.add('is-stackable');
    }

    function watch(table) {
        if (observed.has(table) || !window.MutationObserver) return;
        observed.add(table);
        let queued = false;
        new MutationObserver(function () {
            if (queued) return;
            queued = true;
            requestAnimationFrame(function () { queued = false; label(table); });
        }).observe(table, { childList: true, subtree: true });
    }

    function run(root) {
        (root || document).querySelectorAll(SELECTOR).forEach(function (table) {
            label(table);
            watch(table);
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { run(); });
    else run();
    document.addEventListener('ajax:load', function () { run(); });
})();
