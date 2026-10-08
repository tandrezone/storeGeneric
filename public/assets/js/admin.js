/**
 * Admin table interactions: expandable edit rows and the magic-edit panel.
 */
(function () {
    /** Translated text (i18n.js, loaded by the admin layout); the English text if it is missing. */
    function __(key, params) {
        if (window.StoreI18n) return window.StoreI18n.t(key, params);
        return key.replace(/\{(\w+)\}/g, (m, name) => (params && name in params ? String(params[name]) : m));
    }

    function __n(key, count, params) {
        if (window.StoreI18n) return window.StoreI18n.tn(key, count, params);
        return __(key, Object.assign({ count: count }, params || {}));
    }

    function toggleRow(id, button) {
        const row = document.getElementById(id);
        if (!row) return;

        const willOpen = row.hidden;
        row.hidden = !willOpen;

        const productRow = document.querySelector('[data-product-id="' + id.split('-')[1] + '"]');
        if (productRow) productRow.classList.toggle('is-open', willOpen);
        if (button) button.setAttribute('aria-expanded', String(willOpen));
    }

    document.querySelectorAll('.row-toggle').forEach((btn) => {
        btn.addEventListener('click', () => toggleRow(btn.dataset.target, btn));
    });

    const productChecks = () => document.querySelectorAll('.product-select');
    const bulkCount = document.querySelector('[data-bulk-count]');
    const selectAll = document.getElementById('select-all-products');

    function updateBulkCount() {
        const boxes = Array.from(productChecks());
        const checkedCount = boxes.filter((cb) => cb.checked).length;

        if (bulkCount) bulkCount.textContent = __('{count} selected', { count: checkedCount });
        if (selectAll) {
            selectAll.checked = boxes.length > 0 && checkedCount === boxes.length;
            selectAll.indeterminate = checkedCount > 0 && checkedCount < boxes.length;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            productChecks().forEach((cb) => { cb.checked = selectAll.checked; });
            updateBulkCount();
        });
    }

    productChecks().forEach((cb) => cb.addEventListener('change', updateBulkCount));
    updateBulkCount();

    const bulkForm = document.getElementById('bulk-form');
    if (bulkForm) {
        bulkForm.addEventListener('submit', (e) => {
            const checked = document.querySelectorAll('.product-select:checked').length;
            if (checked === 0) {
                e.preventDefault();
                alert(__('Select at least one product first.'));
                return;
            }
            if (e.submitter && e.submitter.value === 'bulk_delete'
                && !confirm(__n('Delete {count} selected product? This cannot be undone.', checked))) {
                e.preventDefault();
            }
            if (e.submitter && e.submitter.value === 'bulk_price') {
                const mode = bulkForm.querySelector('[name="price_mode"]');
                const value = bulkForm.querySelector('[name="price_value"]');
                if (!value || value.value.trim() === '' || Number(value.value) < 0) {
                    e.preventDefault();
                    alert(__('Enter a price or an amount of 0 or more.'));
                    if (value) value.focus();
                    return;
                }
                const label = mode ? mode.options[mode.selectedIndex].text : __('Change price');
                if (!confirm(__n('{action} {value} for every variant of {count} product?', checked, { action: label, value: value.value }))) {
                    e.preventDefault();
                }
            }
        });
    }

    // Drag and drop to reorder a product's images; the first one is the main
    // image. On drop the new order is posted through the hidden form named
    // by data-image-order-form (the arrow buttons work without JavaScript).
    document.querySelectorAll('[data-image-order-form]').forEach((grid) => {
        const form = document.getElementById(grid.dataset.imageOrderForm);
        if (!form) return;
        let dragged = null;

        grid.addEventListener('dragstart', (e) => {
            dragged = e.target.closest('.image-thumb');
            if (!dragged) return;
            dragged.classList.add('is-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', dragged.dataset.path || '');
        });

        grid.addEventListener('dragover', (e) => {
            if (!dragged) return;
            e.preventDefault();
            const over = e.target.closest('.image-thumb');
            if (!over || over === dragged) return;
            const rect = over.getBoundingClientRect();
            const after = (e.clientX - rect.left) > rect.width / 2;
            over.parentNode.insertBefore(dragged, after ? over.nextSibling : over);
        });

        grid.addEventListener('dragend', () => {
            if (dragged) dragged.classList.remove('is-dragging');
            dragged = null;
        });

        grid.addEventListener('drop', (e) => {
            e.preventDefault();
            form.querySelectorAll('input[name="order[]"]').forEach((input) => input.remove());
            grid.querySelectorAll('.image-thumb').forEach((thumb) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'order[]';
                input.value = thumb.dataset.path || '';
                form.appendChild(input);
            });
            if (form.requestSubmit) form.requestSubmit();
            else form.submit();
        });
    });

    document.querySelectorAll('.magic-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const row = document.getElementById(btn.dataset.target);
            if (row) row.hidden = !row.hidden;
        });
    });

    // Normalize Enter to <p> in contenteditable regions — Chrome defaults
    // to bare <div>s, which the server-side sanitizer has to special-case
    // anyway, but this keeps the two editors' output consistent.
    try {
        document.execCommand('defaultParagraphSeparator', false, 'p');
    } catch (err) {
        // Unsupported in some browsers — the sanitizer's <div>-as-<p>
        // handling covers that case regardless.
    }

    document.querySelectorAll('[data-wysiwyg]').forEach((wrap) => {
        const editor = wrap.querySelector('.wysiwyg-editor');
        const textarea = wrap.querySelector('textarea');
        const form = wrap.closest('form');

        const sync = () => { textarea.value = editor.innerHTML; };
        sync();

        editor.addEventListener('input', sync);
        if (form) form.addEventListener('submit', sync);

        wrap.querySelectorAll('[data-cmd]').forEach((btn) => {
            btn.addEventListener('click', () => {
                editor.focus();
                const cmd = btn.dataset.cmd;

                if (cmd === 'createLink') {
                    const url = window.prompt(__('Link URL (https://…):'));
                    if (!url) return;
                    document.execCommand(cmd, false, url);
                } else {
                    document.execCommand(cmd, false, null);
                }

                sync();
            });
        });
    });

    const MAGIC_LABELS = { name: __('Name'), short_description: __('Short description'), long_description: __('Long description') };

    // Writes a suggested value into the product's edit form (long_description
    // arrives already sanitized server-side) and opens that form for review.
    function applyMagicField(productId, key, value) {
        const form = document.querySelector('#edit-' + productId + ' .edit-form');
        if (!form) return false;
        let truncated = 0;

        if (key === 'long_description') {
            const editor = form.querySelector('[data-wysiwyg] .wysiwyg-editor');
            editor.innerHTML = value;
            editor.dispatchEvent(new Event('input'));
        } else {
            const input = form.querySelector('[name="' + key + '"]');
            if (!input) return false;
            // Respect the column size (maxlength); the server rejects longer values.
            const max = input.maxLength;
            if (max > 0 && value.length > max) {
                input.value = value.slice(0, max).trimEnd();
                truncated = max;
            } else {
                input.value = value;
            }
        }

        const row = document.getElementById('edit-' + productId);
        if (row.hidden) toggleRow(row.id, document.querySelector('.row-toggle[data-target="' + row.id + '"]'));
        return truncated ? { truncated } : true;
    }

    function renderMagicFields(block, fields) {
        const box = block.querySelector('.magic-fields');
        const productId = block.dataset.productId;
        const keys = Object.keys(MAGIC_LABELS).filter((k) => k in fields);

        box.replaceChildren();
        box.hidden = keys.length === 0;
        if (keys.length === 0) return;

        const markApplied = (btn) => { btn.textContent = __('Applied ✓'); btn.disabled = true; };
        const buttons = [];

        keys.forEach((key) => {
            const item = document.createElement('div');
            item.className = 'magic-field';

            const label = document.createElement('strong');
            label.textContent = MAGIC_LABELS[key];

            const preview = document.createElement('div');
            preview.className = 'magic-field-value';
            if (key === 'long_description') preview.innerHTML = fields[key];
            else preview.textContent = fields[key];

            const apply = document.createElement('button');
            apply.type = 'button';
            apply.className = 'btn-secondary btn-small';
            apply.textContent = __('Apply');
            apply.addEventListener('click', () => {
                const result = applyMagicField(productId, key, fields[key]);
                if (!result) return;
                markApplied(apply);
                if (result.truncated) {
                    apply.textContent = __('Applied — shortened to {count} characters', { count: result.truncated });
                    apply.title = __('The suggestion was longer than this field allows; review the end of the text.');
                }
            });
            buttons.push(apply);

            item.append(label, preview, apply);
            box.append(item);
        });

        const applyAll = document.createElement('button');
        applyAll.type = 'button';
        applyAll.className = 'btn-primary';
        applyAll.textContent = __('Apply all');
        applyAll.addEventListener('click', () => {
            buttons.forEach((b) => { if (!b.disabled) b.click(); });
            markApplied(applyAll);
        });

        const hint = document.createElement('p');
        hint.className = 'field-hint';
        hint.textContent = __('Applying fills the product edit form above — review it, then click "Save changes".');

        box.append(applyAll, hint);
    }

    document.querySelectorAll('.magic-run').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const block = btn.closest('.magic-block');
            const output = block.querySelector('.magic-output');
            block.querySelector('.magic-fields').hidden = true;
            const instruction = block.querySelector('.magic-prompt').value.trim();

            if (!instruction) {
                output.hidden = false;
                output.textContent = __('Enter an instruction first.');
                return;
            }

            btn.disabled = true;
            output.hidden = false;
            output.textContent = __('Thinking…');

            try {
                const meta = document.querySelector('meta[name="csrf-token"]');
                const csrfToken = block.dataset.csrf || (meta ? meta.content : '');
                const response = await fetch(block.dataset.endpoint || '/admin/api/magic-edit', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        product_id: block.dataset.productId,
                        instruction,
                        csrf_token: csrfToken,
                    }),
                });
                const data = await response.json();
                output.textContent = data.success ? data.suggestion : __('Error: {message}', { message: data.error });
                if (data.success) renderMagicFields(block, data.fields || {});
            } catch (err) {
                output.textContent = __('Request failed: {message}', { message: err.message });
            } finally {
                btn.disabled = false;
            }
        });
    });
    // Product translations: "Translate with AI" fills the language's form with
    // a suggestion from the original text; nothing is saved until "Save translation".
    document.querySelectorAll('.translation-form').forEach((form) => {
        const button = form.querySelector('.translation-ai');
        const status = form.querySelector('.translation-status');
        if (!button) return;

        const say = (text) => { if (status) status.textContent = text; };

        button.addEventListener('click', async () => {
            button.disabled = true;
            say(__('Thinking…'));

            try {
                const meta = document.querySelector('meta[name="csrf-token"]');
                const response = await fetch(form.dataset.endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        product_id: form.dataset.productId,
                        locale: form.dataset.locale,
                        csrf_token: form.dataset.csrf || (meta ? meta.content : ''),
                    }),
                });
                const data = await response.json();
                if (!data.success) {
                    say(__('Error: {message}', { message: data.error }));
                    return;
                }

                ['name', 'short_description'].forEach((key) => {
                    const input = form.querySelector('[name="' + key + '"]');
                    if (input && data.fields[key]) {
                        input.value = input.maxLength > 0 ? data.fields[key].slice(0, input.maxLength) : data.fields[key];
                    }
                });
                if (data.fields.long_description) {
                    const editor = form.querySelector('[data-wysiwyg] .wysiwyg-editor');
                    editor.innerHTML = data.fields.long_description;
                    editor.dispatchEvent(new Event('input'));
                }
                say(__('Translated. Review the fields, then click "Save translation".'));
            } catch (err) {
                say(__('Request failed: {message}', { message: err.message }));
            } finally {
                button.disabled = false;
            }
        });
    });
})();
