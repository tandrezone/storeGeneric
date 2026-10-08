<?php

/**
 * Catalog keys that scripts in public/assets/js/ use. Their translations are
 * put on the page (data-i18n of the i18n.js script tag the layouts load, see
 * TranslationExtension's i18n_js()) and read with StoreI18n.t('…') /
 * StoreI18n.tn('{count} item', n). Add a key here when a script needs it.
 */

declare(strict_types=1);

return [
    // app.js (storefront cart)
    '{count} item',
    'Something went wrong.',
    'Added to cart. Your cart has {items}.',
    'Added to cart.',
    'Cart updated: {items}, total {total}.',
    'Item removed. Cart: {items}, total {total}.',
    'Quantity of {name}',
    'Remove {name} from cart',
    // ajax-nav.js
    'Request failed ({status}).',
    'Network error — please try again.',
    // admin.js
    '{count} selected',
    'Select at least one product first.',
    'Delete {count} selected product? This cannot be undone.',
    'Enter a price or an amount of 0 or more.',
    'Change price',
    '{action} {value} for every variant of {count} product?',
    'Link URL (https://…):',
    'Name',
    'Short description',
    'Long description',
    'Applied ✓',
    'Apply',
    'Applied — shortened to {count} characters',
    'The suggestion was longer than this field allows; review the end of the text.',
    'Apply all',
    'Applying fills the product edit form above — review it, then click "Save changes".',
    'Enter an instruction first.',
    'Thinking…',
    'Error: {message}',
    'Request failed: {message}',
    // dashboard.js
    'Revenue from paid orders per day',
    'Revenue',
    'Paid orders',
    '{day}: {revenue} from {orders} paid orders',
    // analytics.js
    'Daily visits: total visits and unique visitors over time',
    'Total visits',
    'Unique visitors',
    '{day}: {total} total visits, {unique} unique visitors',
];
