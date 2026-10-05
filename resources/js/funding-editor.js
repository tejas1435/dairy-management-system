/**
 * Split-funding editor: add and remove rows, swap the source selector to match
 * the chosen type, and keep a live allocated/remaining readout.
 *
 * Presentation only. The server revalidates the whole split inside the database
 * transaction and rejects anything that does not sum exactly to the payable, so
 * nothing here can authorise a bad save. The totals exist so the person filling
 * the form can see where they are, not to decide whether the form is correct.
 *
 * Money is handled in integer paise rather than floats: 0.1 + 0.2 in binary
 * floating point is not 0.3, and a rounding artefact in the "remaining" figure
 * would tell the user they are short when they are not.
 */

const toPaise = (value) => {
    const parsed = Number.parseFloat(String(value ?? '').trim());

    return Number.isFinite(parsed) ? Math.round(parsed * 100) : 0;
};

const formatRupees = (paise) => {
    const sign = paise < 0 ? '-' : '';
    const absolute = Math.abs(paise);
    const rupees = Math.floor(absolute / 100);
    const fraction = String(absolute % 100).padStart(2, '0');

    return `${sign}₹${rupees.toLocaleString('en-IN')}.${fraction}`;
};

class FundingEditor {
    constructor(root) {
        this.root = root;
        this.rows = root.querySelector('[data-funding-rows]');
        this.template = root.parentElement.querySelector('[data-funding-template]');
        this.allocatedOutput = root.querySelector('[data-funding-allocated]');
        this.remainingOutput = root.querySelector('[data-funding-remaining]');

        const selector = root.dataset.amountInput;
        this.amountInput = selector ? document.querySelector(selector) : null;

        this.bind();
        this.syncAllRowTypes();
        this.recalculate();
    }

    bind() {
        this.root.querySelector('[data-funding-add]')?.addEventListener('click', () => this.addRow());

        // Delegated, so rows added later are covered without rebinding.
        this.root.addEventListener('click', (event) => {
            if (event.target.closest('[data-funding-remove]')) {
                this.removeRow(event.target.closest('[data-funding-row]'));
            }
        });

        this.root.addEventListener('input', (event) => {
            if (event.target.matches('[data-funding-amount]')) {
                this.recalculate();
            }
        });

        this.root.addEventListener('change', (event) => {
            if (event.target.matches('[data-funding-type]')) {
                this.syncRowType(event.target.closest('[data-funding-row]'));
            }
        });

        this.amountInput?.addEventListener('input', () => this.recalculate());
    }

    addRow() {
        if (!this.template) {
            return;
        }

        const index = this.nextIndex();
        const html = this.template.innerHTML.replaceAll('__INDEX__', String(index));
        const holder = document.createElement('tbody');
        holder.innerHTML = html.trim();

        const row = holder.querySelector('tr');
        this.rows.append(row);

        this.syncRowType(row);
        this.recalculate();

        // Land the caret where the user is about to type.
        row.querySelector('[data-funding-source]:not(.d-none)')?.focus();
    }

    removeRow(row) {
        if (!row) {
            return;
        }

        // Always leave one row, so the editor never becomes unusable.
        if (this.rows.querySelectorAll('[data-funding-row]').length <= 1) {
            row.querySelectorAll('input, select').forEach((field) => {
                field.value = '';
            });
        } else {
            row.remove();
        }

        this.recalculate();
    }

    /**
     * Shows the selector matching the chosen type and disables the other.
     *
     * Disabling matters: a disabled field is not submitted, so the two
     * selectors sharing one field name cannot both post and the server can
     * never receive a partner id labelled as an account.
     */
    syncRowType(row) {
        if (!row) {
            return;
        }

        const type = row.querySelector('[data-funding-type]')?.value ?? 'financial_account';

        row.querySelectorAll('[data-funding-source]').forEach((select) => {
            const matches = select.dataset.fundingSource === type;

            select.classList.toggle('d-none', !matches);
            select.disabled = !matches;

            if (!matches) {
                select.value = '';
            }
        });
    }

    syncAllRowTypes() {
        this.rows.querySelectorAll('[data-funding-row]').forEach((row) => this.syncRowType(row));
    }

    nextIndex() {
        return this.rows.querySelectorAll('[data-funding-row]').length;
    }

    recalculate() {
        let allocated = 0;

        this.rows.querySelectorAll('[data-funding-amount]').forEach((input) => {
            allocated += toPaise(input.value);
        });

        const total = toPaise(this.amountInput?.value);
        const remaining = total - allocated;

        if (this.allocatedOutput) {
            this.allocatedOutput.textContent = formatRupees(allocated);
        }

        if (this.remainingOutput) {
            this.remainingOutput.textContent = formatRupees(remaining);
            this.remainingOutput.classList.toggle('text-success', remaining === 0 && total > 0);
            this.remainingOutput.classList.toggle('text-danger', remaining !== 0);
        }
    }
}

export function initFundingEditors(root = document) {
    root.querySelectorAll('[data-funding-editor]').forEach((element) => new FundingEditor(element));
}
