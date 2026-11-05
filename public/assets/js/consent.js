/* Cookie notice: remembers the choice in the store_consent cookie ("accepted" / "declined", 1 year) and, only after
   "accepted", runs the optional tracking snippet from <template id="consent-snippet">. Elements with
   data-cookie-settings (and the footer link added below) reopen the notice. */
(function () {
    'use strict';
    var NAME = 'store_consent';

    function read() {
        var match = document.cookie.match(new RegExp('(?:^|; )' + NAME + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : '';
    }

    function write(value) {
        var secure = location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = NAME + '=' + encodeURIComponent(value) + '; Max-Age=31536000; Path=/; SameSite=Lax' + secure;
    }

    function loadSnippet() {
        var template = document.getElementById('consent-snippet');
        if (!template || template.dataset.loaded) return;
        template.dataset.loaded = '1';
        // Scripts created by parsing HTML don't run; re-create them so they do.
        Array.prototype.forEach.call(template.content.childNodes, function (node) {
            var copy = document.importNode(node, true);
            if (copy.nodeName === 'SCRIPT') {
                var script = document.createElement('script');
                Array.prototype.forEach.call(copy.attributes, function (attr) { script.setAttribute(attr.name, attr.value); });
                script.text = copy.text;
                document.head.appendChild(script);
            } else {
                document.body.appendChild(copy);
            }
        });
    }

    function init() {
        var notice = document.getElementById('cookie-notice');
        if (!notice) return;
        var hasSnippet = notice.dataset.hasSnippet === '1';
        var choice = read();

        if (choice === 'accepted' && hasSnippet) loadSnippet();
        notice.hidden = choice !== '';

        notice.addEventListener('click', function (event) {
            var button = event.target.closest('[data-consent]');
            if (!button) return;
            var value = button.dataset.consent;
            write(value);
            notice.hidden = true;
            if (value === 'accepted' && hasSnippet) loadSnippet();
        });

        function reopen(event) { event.preventDefault(); notice.hidden = false; }
        Array.prototype.forEach.call(document.querySelectorAll('[data-cookie-settings]'), function (el) { el.addEventListener('click', reopen); });

        var nav = document.querySelector('.footer-nav');
        if (nav && !nav.querySelector('[data-cookie-settings]')) {
            var link = document.createElement('a');
            link.href = '#cookie-notice';
            link.textContent = notice.getAttribute('aria-label');
            link.setAttribute('data-cookie-settings', '');
            link.addEventListener('click', reopen);
            nav.appendChild(link);
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
