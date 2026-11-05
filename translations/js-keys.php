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
    'Translated. Review the fields, then click "Save translation".',
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
    // admin.js — magic edit all products
    'There are no products yet.',
    'Run this instruction on {count} product? Each product is one AI request.',
    '{done} of {total} products checked — {changed} with changes, {failed} failed.',
    'Stopped.',
    'Save the changes to {count} product?',
    'Saved ✓',
    '{count} product saved. Reload the page to see the changes in the list.',
    // admin.js — magic edit for pages and the newsletter
    'Replace the current title and content with the AI version?',
    'Writing the page… this can take up to 30 seconds.',
    'Done — review the page, then click "Save page".',
    'Replace the current subject and message with the AI version?',
    'Writing the campaign… this can take up to 30 seconds.',
    'Done — review it and send yourself a test first.',
    'The suggestion is too long for: {fields}.',
];
