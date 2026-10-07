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

    document.querySelectorAll('.magic-run').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const block = btn.closest('.magic-block');
            const output = block.querySelector('.magic-output');
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
            } catch (err) {
                output.textContent = 'Request failed: ' + err.message;
            } finally {
                btn.disabled = false;
            }
        });
    });
})();
