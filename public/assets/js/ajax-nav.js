/**
 * AJAX navigation layer (progressive enhancement).
 *
 * Intercepts same-origin link clicks and form submissions, fetches the
 * target page in the background and swaps only the <main> element, so the
 * header, cart badge and scroll position survive and the browser never does
 * a full reload. Server-side PHP is unchanged: every page still renders full
 * HTML, so everything keeps working with JavaScript disabled.
 *
 * Opt out per element with the `data-no-ajax` attribute (on the link/form or
 * any ancestor). Pages whose <body data-layout> differs from the current one
 * (e.g. storefront -> admin, admin -> login after a session expiry) fall back
 * to a normal full navigation.
 *
 * Other scripts can hook in via:
 *   document.addEventListener('ajax:load', e => ...)   // after every swap
 *   window.AjaxNav.visit(url) / .reload() / .toast(msg, 'error')
 */
(function () {
    'use strict';

    if (!window.fetch || !window.DOMParser || !window.history || !history.pushState) return;

    const LAYOUT = document.body.dataset.layout;
    if (!LAYOUT) return;

    let getController = null;   // in-flight GET (abortable)
    let postInFlight = false;   // in-flight POST (never aborted, never doubled)
    let busyTimer = null;

    // ---------------------------------------------------------------- UI

    const progress = document.createElement('div');
    progress.className = 'ajax-progress';
    progress.setAttribute('aria-hidden', 'true');

    const toastRegion = document.createElement('div');
    toastRegion.className = 'ajax-toast-region';
    toastRegion.setAttribute('role', 'status');
    toastRegion.setAttribute('aria-live', 'polite');

    document.body.append(progress, toastRegion);

    function setBusy(on) {
        clearTimeout(busyTimer);
        const main = document.querySelector('main');
        if (on) {
            progress.classList.add('is-active');
            // Only dim the page if the request is noticeably slow — avoids flicker.
            busyTimer = setTimeout(() => main && main.classList.add('is-loading'), 200);
            if (main) main.setAttribute('aria-busy', 'true');
        } else {
            progress.classList.remove('is-active');
            if (main) {
                main.classList.remove('is-loading');
                main.removeAttribute('aria-busy');
            }
        }
    }

    function toast(message, type) {
        if (!message) return;
        const el = document.createElement('div');
        el.className = 'ajax-toast' + (type === 'error' ? ' is-error' : '');
        el.textContent = message;
        toastRegion.appendChild(el);
        requestAnimationFrame(() => el.classList.add('is-visible'));
        setTimeout(() => {
            el.classList.remove('is-visible');
            setTimeout(() => el.remove(), 300);
        }, type === 'error' ? 6000 : 3500);
    }

    // ---------------------------------------------------- state helpers

    /** Remembers which expandable rows/panels are open so a swap doesn't collapse them. */
    function captureOpenState() {
        const main = document.querySelector('main');
        if (!main) return [];
        return Array.from(main.querySelectorAll(
            '.edit-row[id]:not([hidden]), .magic-row[id]:not([hidden]), details[id][open]'
        )).map((el) => el.id);
    }

    function restoreOpenState(ids) {
        ids.forEach((id) => {
            const el = document.getElementById(id);
            if (!el) return;
            if (el.tagName === 'DETAILS') {
                el.open = true;
                return;
            }
            el.hidden = false;
            if (el.classList.contains('edit-row')) {
                const pid = id.replace(/^edit-/, '');
                const row = document.querySelector('.product-row[data-product-id="' + pid + '"]');
                if (row) row.classList.add('is-open');
                document.querySelectorAll('.row-toggle[data-target="' + id + '"]')
                    .forEach((b) => b.setAttribute('aria-expanded', 'true'));
            }
        });
    }

    /** Scripts parsed by DOMParser are inert; re-create them so they execute (in order). */
    function activateScripts(container) {
        container.querySelectorAll('script').forEach((old) => {
            const s = document.createElement('script');
            for (const attr of old.attributes) s.setAttribute(attr.name, attr.value);
            if (!old.src) s.textContent = old.textContent;
            s.async = false;
            old.replaceWith(s);
        });
    }

    /** Copies active-state classes from the fetched page's nav onto the live one. */
    function syncNav(doc) {
        const fresh = new Map();
        doc.querySelectorAll('.main-nav a').forEach((a) => fresh.set(a.getAttribute('href'), a.className));
        document.querySelectorAll('.main-nav a').forEach((a) => {
            const cls = fresh.get(a.getAttribute('href'));
            if (cls !== undefined) a.className = cls;
        });
    }

    function saveScroll() {
        const state = Object.assign({}, history.state, { ajax: true, scrollY: window.scrollY });
        history.replaceState(state, '', location.href);
    }

    // ------------------------------------------------------------ core

    /**
     * Swaps <main> with the one from `doc`.
     * opts.history: 'push' | 'replace' | false
     * opts.scroll:  'top' | 'keep' | number
     */
    function swap(doc, url, opts) {
        const newMain = doc.querySelector('main');
        const curMain = document.querySelector('main');

        if (!newMain || !curMain || doc.body.dataset.layout !== LAYOUT) {
            window.location.assign(url);
            return false;
        }

        const samePage = new URL(url, location.href).pathname === location.pathname;
        const openIds = samePage ? captureOpenState() : [];

        const adopted = document.adoptNode(newMain);
        curMain.replaceWith(adopted);
        activateScripts(adopted);

        document.title = doc.title;
        syncNav(doc);
        restoreOpenState(openIds);

        if (opts.history === 'push') {
            saveScroll();
            history.pushState({ ajax: true, scrollY: 0 }, '', url);
        } else if (opts.history === 'replace') {
            history.replaceState({ ajax: true, scrollY: window.scrollY }, '', url);
        }

        if (opts.scroll === 'top') {
            window.scrollTo(0, 0);
            adopted.setAttribute('tabindex', '-1');
            adopted.focus({ preventScroll: true });
        } else if (typeof opts.scroll === 'number') {
            window.scrollTo(0, opts.scroll);
        }

        document.dispatchEvent(new CustomEvent('ajax:load', { detail: { url, main: adopted } }));
        return true;
    }

    async function load(url, fetchOpts, swapOpts) {
        const isPost = (fetchOpts.method || 'GET').toUpperCase() !== 'GET';

        if (isPost) {
            if (postInFlight) return;
            postInFlight = true;
        }
        if (getController) getController.abort();
        const controller = new AbortController();
        if (!isPost) getController = controller;

        setBusy(true);
        try {
            const res = await fetch(url, Object.assign({
                credentials: 'same-origin',
                signal: controller.signal,
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
            }, fetchOpts));

            const type = res.headers.get('content-type') || '';
            if (!type.includes('text/html')) {
                // JSON, file download, image… let the browser deal with it.
                if (!res.ok) throw new Error('Request failed (' + res.status + ').');
                window.location.assign(res.url);
                return;
            }

            const html = await res.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');

            if (!res.ok && !doc.querySelector('main')) {
                // e.g. the CSRF check's plain-text 400 response.
                const text = (doc.body && doc.body.textContent || '').trim();
                throw new Error(text.slice(0, 300) || 'Request failed (' + res.status + ').');
            }

            // Follow server redirects (PRG, login bounce) in the address bar.
            const finalUrl = res.redirected ? res.url : url;
            const historyMode = isPost
                ? (res.redirected ? 'replace' : false)
                : swapOpts.history;

            const swapped = swap(doc, finalUrl, Object.assign({}, swapOpts, { history: historyMode }));

            if (swapped && isPost) {
                const msg = document.querySelector('main .msg');
                if (msg) toast(msg.textContent.trim(), msg.classList.contains('error') ? 'error' : 'success');
            }
        } catch (err) {
            if (err.name === 'AbortError') return;
            toast(err.message || 'Network error — please try again.', 'error');
        } finally {
            if (isPost) postInFlight = false;
            if (getController === controller) getController = null;
            setBusy(false);
        }
    }

    function visit(url, opts) {
        opts = opts || {};
        const target = new URL(url, location.href);
        const scroll = opts.scroll
            || (target.pathname === location.pathname ? 'keep' : 'top');
        return load(target.href, { method: 'GET' }, { history: opts.history || 'push', scroll });
    }

    function reload() {
        return load(location.href, { method: 'GET' }, { history: false, scroll: 'keep' });
    }

    // ------------------------------------------------- event wiring

    function optedOut(el) {
        return !!el.closest('[data-no-ajax]');
    }

    document.addEventListener('click', (e) => {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

        const a = e.target.closest('a[href]');
        if (!a || optedOut(a)) return;
        if (a.target && a.target !== '_self') return;
        if (a.hasAttribute('download')) return;

        const url = new URL(a.href, location.href);
        if (url.origin !== location.origin) return;
        if (!/^https?:$/.test(url.protocol)) return;
        // In-page anchors.
        if (url.hash && url.pathname === location.pathname && url.search === location.search) return;
        // Static files (images, PDFs…) — only .php pages and extension-less paths are pages.
        if (/\.[a-z0-9]+$/i.test(url.pathname) && !/\.php$/i.test(url.pathname)) return;

        e.preventDefault();
        const scroll = a.closest('.pagination') ? 'top' : undefined;
        visit(url.href, { scroll });
    });

    document.addEventListener('submit', (e) => {
        // Inline onsubmit="return confirm(...)" and other handlers run first
        // and cancel by calling preventDefault — respect that.
        if (e.defaultPrevented) return;

        const form = e.target;
        const submitter = e.submitter || null;
        if (optedOut(form) || (submitter && optedOut(submitter))) return;
        // NB: read attributes, not form.action/form.target — every admin form has an
        // <input name="action">, which shadows the form.action property.
        const formTarget = (submitter && submitter.getAttribute('formtarget')) || form.getAttribute('target');
        if (formTarget && formTarget !== '_self') return;

        const actionAttr = (submitter && submitter.getAttribute('formaction')) || form.getAttribute('action') || location.href;
        const action = new URL(actionAttr, location.href);
        if (action.origin !== location.origin) return;

        const method = ((submitter && submitter.getAttribute('formmethod')) || form.getAttribute('method') || 'get').toUpperCase();

        let data;
        try {
            data = new FormData(form, submitter);
        } catch (err) {
            data = new FormData(form);
            if (submitter && submitter.name) data.append(submitter.name, submitter.value);
        }

        e.preventDefault();

        if (method === 'GET') {
            action.search = new URLSearchParams(data).toString();
            visit(action.href);
            return;
        }

        const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
        const formId = form.getAttribute('id');
        if (formId) buttons.push(...document.querySelectorAll('[form="' + CSS.escape(formId) + '"][type="submit"]'));
        buttons.forEach((b) => { b.disabled = true; });
        form.setAttribute('aria-busy', 'true');

        load(action.href, { method: 'POST', body: data }, { scroll: 'keep' }).finally(() => {
            // If the form survived (e.g. error toast, no swap), re-enable it.
            if (document.contains(form)) {
                buttons.forEach((b) => { b.disabled = false; });
                form.removeAttribute('aria-busy');
            }
        });
    });

    window.addEventListener('popstate', (e) => {
        if (!e.state || !e.state.ajax) return;
        const y = typeof e.state.scrollY === 'number' ? e.state.scrollY : 0;
        load(location.href, { method: 'GET' }, { history: false, scroll: y });
    });

    // Take manual control of scroll restoration so back/forward land correctly,
    // and keep F5 behaving like before by saving/restoring the position ourselves.
    const initialState = history.state;
    if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
    history.replaceState(Object.assign({}, initialState, { ajax: true, scrollY: window.scrollY }), '', location.href);
    window.addEventListener('pagehide', saveScroll);

    const navEntry = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
    if (navEntry && (navEntry.type === 'reload' || navEntry.type === 'back_forward')
        && initialState && typeof initialState.scrollY === 'number') {
        window.addEventListener('load', () => window.scrollTo(0, initialState.scrollY), { once: true });
    }

    window.AjaxNav = { visit, reload, toast };
})();
