/**
 * Behaviour shared by admin pages, via data attributes:
 *   <form data-confirm="Delete this?">       asks before submitting
 *   <button data-toggle="row-id">            shows/hides the element with that id
 *   <select data-autosubmit>                 submits its form when changed
 *
 * Uses delegated listeners on document, registered once even when
 * ajax-nav.js swaps the page in repeatedly. The submit listener runs in
 * the capture phase so it can cancel before ajax-nav.js takes over.
 */
(function () {
    'use strict';
    if (window.__adminCommonReady) return;
    window.__adminCommonReady = true;

    document.addEventListener('submit', function (e) {
        var form = e.target.closest ? e.target.closest('form[data-confirm]') : null;
        if (form && !window.confirm(form.getAttribute('data-confirm'))) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);

    document.addEventListener('click', function (e) {
        var button = e.target.closest ? e.target.closest('[data-toggle]') : null;
        if (!button) return;
        var target = document.getElementById(button.getAttribute('data-toggle'));
        if (!target) return;
        target.hidden = !target.hidden;
        button.setAttribute('aria-expanded', target.hidden ? 'false' : 'true');
    });

    document.addEventListener('change', function (e) {
        var field = e.target;
        if (!field.matches || !field.matches('[data-autosubmit]') || !field.form) return;
        if (field.form.requestSubmit) {
            field.form.requestSubmit();
        } else {
            field.form.submit();
        }
    });
})();
