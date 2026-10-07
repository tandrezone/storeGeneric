/**
 * Translations for the other scripts. The layouts load this file first with
 * the strings of the page's language (translations/js-keys.php) in its
 * data-i18n attribute — no inline script, so it works under the admin CSP:
 *
 *   <script src="/assets/js/i18n.js" data-i18n="{…}" data-locale="pt-PT"></script>
 *
 *   StoreI18n.t('Added to cart.')                       translated text
 *   StoreI18n.t('Remove {name} from cart', {name: n})   with placeholders
 *   StoreI18n.tn('{count} item', 3)                     plural forms (one/other), {count} = 3
 *   StoreI18n.number(1234.5, 2)                         "1,234.50" / "1 234,50"
 *   StoreI18n.locale                                    "en", "pt-PT"
 *
 * Missing strings fall back to the English text, so a script still works
 * without this file (use `window.StoreI18n ? … : …` guards there).
 */
(function () {
    'use strict';

    const script = document.currentScript;
    let strings = {};
    try {
        strings = JSON.parse((script && script.getAttribute('data-i18n')) || '{}') || {};
    } catch (e) {
        strings = {};
    }
    const locale = (script && script.getAttribute('data-locale')) || document.documentElement.lang || 'en';

    let plurals = null;
    try {
        plurals = new Intl.PluralRules(locale);
    } catch (e) {
        plurals = null;
    }

    function fill(text, params) {
        if (!params) return text;
        return text.replace(/\{(\w+)\}/g, (match, name) => (
            Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : match
        ));
    }

    function t(key, params) {
        const value = strings[key];
        let text = key;
        if (typeof value === 'string' && value !== '') text = value;
        else if (value && typeof value === 'object') text = value.other || value.one || key;
        return fill(text, params);
    }

    function tn(key, count, params) {
        const value = strings[key];
        let text = key;
        if (typeof value === 'string' && value !== '') {
            text = value;
        } else if (value && typeof value === 'object') {
            const form = plurals ? plurals.select(Number(count)) : (Number(count) === 1 ? 'one' : 'other');
            text = value[form] || value.other || value.one || key;
        }
        return fill(text, Object.assign({ count: count }, params || {}));
    }

    function number(value, decimals) {
        const digits = typeof decimals === 'number' ? decimals : 0;
        try {
            return Number(value).toLocaleString(locale, { minimumFractionDigits: digits, maximumFractionDigits: digits });
        } catch (e) {
            return Number(value).toFixed(digits);
        }
    }

    window.StoreI18n = { locale: locale, t: t, tn: tn, number: number };
})();
