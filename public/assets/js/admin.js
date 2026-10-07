/**
 * Admin table interactions: expandable edit rows and the magic-edit panel.
 */
(function () {
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

        if (bulkCount) bulkCount.textContent = checkedCount + ' selected';
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
                alert('Select at least one product first.');
                return;
            }
            if (e.submitter && e.submitter.value === 'bulk_delete'
                && !confirm('Delete ' + checked + ' selected product(s)? This cannot be undone.')) {
                e.preventDefault();
            }
        });
    }

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
                    const url = window.prompt('Link URL (https://…):');
                    if (!url) return;
                    document.execCommand(cmd, false, url);
                } else {
                    document.execCommand(cmd, false, null);
                }

                sync();
            });
        });
    });

    const MAGIC_LABELS = { name: 'Name', short_description: 'Short description', long_description: 'Long description' };

    // Writes a suggested value into the product's edit form (long_description
    // arrives already sanitized server-side) and opens that form for review.
    function applyMagicField(productId, key, value) {
        const form = document.querySelector('#edit-' + productId + ' .edit-form');
        if (!form) return false;

        if (key === 'long_description') {
            const editor = form.querySelector('[data-wysiwyg] .wysiwyg-editor');
            editor.innerHTML = value;
            editor.dispatchEvent(new Event('input'));
        } else {
            const input = form.querySelector('[name="' + key + '"]');
            if (!input) return false;
            input.value = value;
        }

        const row = document.getElementById('edit-' + productId);
        if (row.hidden) toggleRow(row.id, document.querySelector('.row-toggle[data-target="' + row.id + '"]'));
        return true;
    }

    function renderMagicFields(block, fields) {
        const box = block.querySelector('.magic-fields');
        const productId = block.dataset.productId;
        const keys = Object.keys(MAGIC_LABELS).filter((k) => k in fields);

        box.replaceChildren();
        box.hidden = keys.length === 0;
        if (keys.length === 0) return;

        const markApplied = (btn) => { btn.textContent = 'Applied ✓'; btn.disabled = true; };
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
            apply.textContent = 'Apply';
            apply.addEventListener('click', () => {
                if (applyMagicField(productId, key, fields[key])) markApplied(apply);
            });
            buttons.push(apply);

            item.append(label, preview, apply);
            box.append(item);
        });

        const applyAll = document.createElement('button');
        applyAll.type = 'button';
        applyAll.className = 'btn-primary';
        applyAll.textContent = 'Apply all';
        applyAll.addEventListener('click', () => {
            buttons.forEach((b) => { if (!b.disabled) b.click(); });
            markApplied(applyAll);
        });

        const hint = document.createElement('p');
        hint.className = 'field-hint';
        hint.textContent = 'Applying fills the product edit form above — review it, then click "Save changes".';

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
                output.textContent = 'Enter an instruction first.';
                return;
            }

            btn.disabled = true;
            output.hidden = false;
            output.textContent = 'Thinking…';

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
                output.textContent = data.success ? data.suggestion : 'Error: ' + data.error;
                if (data.success) renderMagicFields(block, data.fields || {});
            } catch (err) {
                output.textContent = 'Request failed: ' + err.message;
            } finally {
                btn.disabled = false;
            }
        });
    });
})();
