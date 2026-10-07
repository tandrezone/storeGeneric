<?php

/**
 * English catalog — the source language. Keys are the English texts used in
 * templates (|trans, t()), PHP (Translator::trans()) and scripts
 * (translations/js-keys.php); values are what is shown. Plural messages map to
 * ['one' => …, 'other' => …] and get {count}. Keys starting with "@"
 * describe the language. Every other catalog must have the same keys
 * (tests/Unit/I18n/CatalogTest.php checks it).
 */

declare(strict_types=1);

return [
    '@name'      => 'English',
    '@html_lang' => 'en',
    '@intl'      => 'en',

    // layout
    'Skip to content' => 'Skip to content',
    'Search products' => 'Search products',
    'Search products…' => 'Search products…',
    'Search' => 'Search',
    'Main' => 'Main',
    'Shop' => 'Shop',
    'Signed in as {name}' => 'Signed in as {name}',
    'Account' => 'Account',
    'Sign in' => 'Sign in',
    'Cart' => 'Cart',
    'items in cart' => 'items in cart',
    'Language' => 'Language',
    'Footer' => 'Footer',
    'About Us' => 'About Us',
    'Info' => 'Info',
    'Support' => 'Support',
    'Track your order' => 'Track your order',
    'Terms & Conditions' => 'Terms & Conditions',
    'All rights reserved.' => 'All rights reserved.',
    'Welcome' => 'Welcome',
    'Browse the catalogue, pick what you like and check out in a few steps.' => 'Browse the catalogue, pick what you like and check out in a few steps.',
    'New drop' => 'New drop',
    'Loud colours. Big deals. Fresh stock every week.' => 'Loud colours. Big deals. Fresh stock every week.',
    'The collection' => 'The collection',
    'Less, but better.' => 'Less, but better.',
    'A considered selection from {store}.' => 'A considered selection from {store}.',
    'New arrivals' => 'New arrivals',
    'Fresh picks, glowing deals.' => 'Fresh picks, glowing deals.',
    'Freshly potted' => 'Freshly potted',
    'Welcome to {store}' => 'Welcome to {store}',
    'Cacti, succulents and green friends that thrive on sunshine and a little neglect.' => 'Cacti, succulents and green friends that thrive on sunshine and a little neglect.',
    'Made with care' => 'Made with care',
    'Thoughtfully chosen goods, packed by hand and sent with a note.' => 'Thoughtfully chosen goods, packed by hand and sent with a note.',
    'Admin' => 'Admin',
    'Dashboard' => 'Dashboard',
    'Products' => 'Products',
    'Categories' => 'Categories',
    'Orders' => 'Orders',
    'Customers' => 'Customers',
    'Shipping' => 'Shipping',
    'Discounts' => 'Discounts',
    'Analytics' => 'Analytics',
    'Activity' => 'Activity',
    'Users' => 'Users',
    'Settings' => 'Settings',
    'Owner' => 'Owner',
    'Manager' => 'Manager',
    'Staff' => 'Staff',
    'My account ({role})' => 'My account ({role})',
    'Log out' => 'Log out',
    'Something went wrong. Please try again in a moment.' => 'Something went wrong. Please try again in a moment.',
];
