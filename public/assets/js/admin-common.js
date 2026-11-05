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

    // <input type="checkbox" data-select-all="group"> ticks every <input data-select="group">; [data-print] prints the page.
    document.addEventListener('change', function (e) {
        var all = e.target.closest ? e.target.closest('[data-select-all]') : null;
        if (!all) return;
        var group = all.getAttribute('data-select-all');
        document.querySelectorAll('input[data-select="' + group + '"]').forEach(function (box) { box.checked = all.checked; });
    });
    document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('[data-print]')) window.print();
    });

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

    // <label data-upload-zone> around a file input: highlight while a file
    // is dragged over it, and list the chosen files in [data-upload-zone-files].
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(function (type) {
        document.addEventListener(type, function (e) {
            var zone = e.target.closest ? e.target.closest('[data-upload-zone]') : null;
            if (zone) zone.classList.toggle('is-dragover', type === 'dragenter' || type === 'dragover');
        });
    });

    document.addEventListener('change', function (e) {
        var zone = e.target.closest ? e.target.closest('[data-upload-zone]') : null;
        var list = zone ? zone.querySelector('[data-upload-zone-files]') : null;
        if (!list || !e.target.files || !e.target.files.length) return;
        list.textContent = Array.prototype.map.call(e.target.files, function (f) { return f.name; }).join(', ');
    });
})();
