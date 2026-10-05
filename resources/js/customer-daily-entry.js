/**
 * Customer Daily Entry: live totals, filters, Copy Previous Day and the bulk save.
 *
 * Presentation and convenience only. Every figure this file shows is recomputed by
 * the server, and every rule it appears to enforce is enforced again there — a
 * disabled input, a hidden row and a client-side total are all courtesies to the
 * operator, not controls. Nothing here can authorise a save the server would refuse,
 * and nothing here decides whether milk is available.
 *
 * ## Arithmetic
 *
 * No floating point. Quantities are parsed into integer thousandths of a litre and
 * rates into integer paise, both summed as integers and formatted back only for
 * display. 0.1 + 0.2 is not 0.3 in binary floating point, and a column of two
 * hundred deliveries would drift visibly — the operator would see a total that
 * disagrees with the server's by a few millilitres and have no way to tell which is
 * right.
 *
 * Amounts use the same half-up rounding the server applies at the litres-to-rupees
 * boundary, so 3.333 L at ₹85.00 reads ₹283.31 in the browser exactly as it is
 * stored.
 *
 * ## Saving
 *
 * One JSON body for the whole day (docs/DECISIONS.md D5). A traditional form post
 * of a few hundred customers runs past `max_input_vars`, and PHP truncates it
 * silently: the request succeeds, the page says "saved", and the bottom of the day
 * is missing. A JSON body is one value and cannot be cut in half unnoticed.
 */

const THOUSANDTHS = 1000;

/** Litres to integer thousandths. Blank is absent, which is not zero. */
const toThousandths = (value) => {
    const text = String(value ?? '').trim();

    if (text === '') {
        return null;
    }

    const parsed = Number.parseFloat(text);

    return Number.isFinite(parsed) ? Math.round(parsed * THOUSANDTHS) : null;
};

const formatLitres = (thousandths) => (thousandths / THOUSANDTHS).toFixed(3);

/**
 * Thousandths of a litre times paise per litre, as integer paise.
 *
 * The product is in thousandths-of-a-paisa, so it is divided by 1000 and rounded
 * half away from zero — the same decision `Quantity::multiplyToMoney()` makes in
 * PHP, deliberately duplicated rather than approximated so the two agree.
 */
const amountInPaise = (thousandths, ratePaise) => {
    const exact = thousandths * ratePaise;
    const sign = exact < 0 ? -1 : 1;

    return sign * Math.round(Math.abs(exact) / THOUSANDTHS);
};

const formatRupees = (paise) => {
    const sign = paise < 0 ? '-' : '';
    const absolute = Math.abs(paise);
    const rupees = Math.floor(absolute / 100);
    const fraction = String(absolute % 100).padStart(2, '0');

    return `${sign}₹${rupees.toLocaleString('en-IN')}.${fraction}`;
};

class DailyEntryGrid {
    constructor(root) {
        this.root = root;
        this.saveUrl = root.dataset.saveUrl;
        this.copyUrl = root.dataset.copyUrl;
        this.date = root.dataset.date;
        this.canSave = root.dataset.canSave === '1';

        this.rows = Array.from(root.querySelectorAll('[data-entry-row]'));
        this.feedback = root.querySelector('[data-entry-feedback]');
        this.dirtyBadge = root.querySelector('[data-entry-dirty]');
        this.saveButton = root.querySelector('[data-entry-save]');
        this.copyButton = root.querySelector('[data-entry-copy]');

        this.dirty = false;
        this.saving = false;

        this.bind();
        this.recalculate();
    }

    bind() {
        this.root.addEventListener('input', (event) => {
            if (event.target.matches('[data-entry-input]')) {
                this.markDirty();
                this.recalculate();
            }

            if (event.target.matches('[data-entry-filter-search]')) {
                this.applyFilters();
            }
        });

        this.root.addEventListener('change', (event) => {
            if (event.target.matches('[data-entry-filter-type], [data-entry-filter-area]')) {
                this.applyFilters();
            }
        });

        this.saveButton?.addEventListener('click', () => this.save());
        this.copyButton?.addEventListener('click', () => this.copyPreviousDay());

        /*
         * The browser's own warning, which cannot be worded but cannot be
         * suppressed by a slow page either. It is removed the moment a save
         * succeeds, so a saved day never warns on the way out.
         */
        this.beforeUnload = (event) => {
            if (!this.dirty) {
                return undefined;
            }

            event.preventDefault();
            event.returnValue = '';

            return '';
        };

        window.addEventListener('beforeunload', this.beforeUnload);

        // Date navigation is a normal link, so it needs its own confirmation.
        const warnOnLeave = (event) => {
            if (this.dirty && !window.confirm(this.message('unsavedWarning'))) {
                event.preventDefault();
            }
        };

        document.querySelectorAll('[data-entry-nav]').forEach((link) => {
            link.addEventListener('click', warnOnLeave);
        });

        document.querySelector('[data-entry-date-form]')?.addEventListener('submit', warnOnLeave);
    }

    /** Messages are rendered into the page by Blade so they stay translated. */
    message(key) {
        return this.root.dataset[key] ?? '';
    }

    cells(row) {
        return Array.from(row.querySelectorAll('[data-entry-input]'));
    }

    markDirty() {
        this.dirty = true;
        this.dirtyBadge?.classList.remove('d-none');
    }

    markClean() {
        this.dirty = false;
        this.dirtyBadge?.classList.add('d-none');
    }

    /**
     * Recomputes every row total and the day's footer, from the inputs as they
     * stand. Called on every keystroke, so it does no work per cell beyond integer
     * arithmetic.
     */
    recalculate() {
        const shiftTotals = new Map();
        let overallThousandths = 0;
        let overallPaise = 0;

        this.rows.forEach((row) => {
            const milkType = row.dataset.milkType;
            let rowThousandths = 0;
            let rowPaise = 0;

            this.cells(row).forEach((input) => {
                const thousandths = toThousandths(input.value);
                const ratePaise = input.dataset.ratePaise === '' ? null : Number(input.dataset.ratePaise);

                this.flagCell(input, thousandths);

                if (thousandths === null || thousandths < 0) {
                    return;
                }

                rowThousandths += thousandths;

                if (ratePaise !== null) {
                    rowPaise += amountInPaise(thousandths, ratePaise);
                }

                const key = `${input.dataset.shift}:${milkType}`;
                shiftTotals.set(key, (shiftTotals.get(key) ?? 0) + thousandths);
            });

            const totalOutput = row.querySelector('[data-row-total]');
            const amountOutput = row.querySelector('[data-row-amount]');

            if (totalOutput) {
                totalOutput.textContent = formatLitres(rowThousandths);
            }

            if (amountOutput) {
                amountOutput.textContent = formatRupees(rowPaise);
            }

            this.updateRowStatus(row);

            overallThousandths += rowThousandths;
            overallPaise += rowPaise;
        });

        this.root.querySelectorAll('[data-total-shift]').forEach((output) => {
            const key = `${output.dataset.totalShift}:${output.dataset.totalType}`;
            output.textContent = formatLitres(shiftTotals.get(key) ?? 0);
        });

        const quantityOutput = this.root.querySelector('[data-total-quantity]');
        const amountOutput = this.root.querySelector('[data-total-amount]');

        if (quantityOutput) {
            quantityOutput.textContent = `${formatLitres(overallThousandths)} ${this.message('unit') || 'L'}`;
        }

        if (amountOutput) {
            amountOutput.textContent = formatRupees(overallPaise);
        }
    }

    /** A negative quantity is marked where it was typed, and still refused server-side. */
    flagCell(input, thousandths) {
        input.classList.toggle('is-invalid', thousandths !== null && thousandths < 0);
    }

    updateRowStatus(row) {
        const badge = row.querySelector('[data-row-status]');

        if (!badge || row.dataset.editable !== '1') {
            return;
        }

        const changed = this.cells(row).some(
            (input) => String(input.value ?? '').trim() !== String(input.dataset.saved ?? '').trim(),
        );

        badge.textContent = changed ? this.message('statusModified') : badge.dataset.original ?? badge.textContent;

        if (!badge.dataset.original && !changed) {
            badge.dataset.original = badge.textContent;
        }

        badge.classList.toggle('text-bg-warning-subtle', changed);
        badge.classList.toggle('text-warning-emphasis', changed);
        badge.classList.toggle('text-bg-secondary-subtle', !changed);
        badge.classList.toggle('text-secondary-emphasis', !changed);
    }

    /**
     * Filters are visual. A hidden row keeps its inputs and is still submitted with
     * whatever it holds, so narrowing the table cannot be mistaken for emptying it.
     */
    applyFilters() {
        const search = (this.root.querySelector('[data-entry-filter-search]')?.value ?? '')
            .trim()
            .toLowerCase();
        const milkType = this.root.querySelector('[data-entry-filter-type]')?.value ?? '';
        const area = this.root.querySelector('[data-entry-filter-area]')?.value ?? '';

        let visible = 0;

        this.rows.forEach((row) => {
            const matches =
                (search === '' || (row.dataset.search ?? '').includes(search)) &&
                (milkType === '' || row.dataset.milkType === milkType) &&
                (area === '' || (row.dataset.area ?? '') === area);

            row.hidden = !matches;
            visible += matches ? 1 : 0;
        });

        this.root.querySelector('[data-entry-no-matches]')?.classList.toggle('d-none', visible > 0);
    }

    /** The whole day, as the server's request contract expects it. */
    payload() {
        return {
            date: this.date,
            rows: this.rows
                .filter((row) => row.dataset.editable === '1')
                .map((row) => {
                    const entry = {
                        buyer_id: Number(row.dataset.buyerId),
                        milk_type: row.dataset.milkType,
                    };

                    this.cells(row).forEach((input) => {
                        const value = String(input.value ?? '').trim();
                        entry[input.dataset.shift] = value === '' ? null : value;
                    });

                    return entry;
                }),
        };
    }

    async save() {
        if (this.saving || !this.canSave) {
            return;
        }

        this.saving = true;
        this.setBusy(true);

        try {
            const response = await fetch(this.saveUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify(this.payload()),
            });

            const body = await response.json().catch(() => null);

            if (!response.ok) {
                this.showErrors(body);

                return;
            }

            this.applyServerState(body);
            this.markClean();
            this.announce(body.message, 'success');
        } catch (error) {
            /*
             * A failed fetch means the request never completed, so nothing was
             * written. The entered values stay exactly where they are and the form
             * stays dirty: queueing milk and money writes for later replay is
             * deliberately not done, because a delivery recorded from a stale queue
             * minutes or hours later is worse than one the operator re-enters.
             */
            this.announce(this.message('networkFailed'), 'danger');
        } finally {
            this.saving = false;
            this.setBusy(false);
        }
    }

    /**
     * Replaces the grid's idea of what is saved with the server's.
     *
     * The response is authoritative: the server recomputed every amount from the
     * stored rate, so the figures here are replaced rather than kept.
     */
    applyServerState(body) {
        const byKey = new Map((body.rows ?? []).map((row) => [`${row.buyerId}:${row.milkType}`, row]));

        this.rows.forEach((row) => {
            const saved = byKey.get(row.dataset.entryKey);

            if (!saved) {
                return;
            }

            this.cells(row).forEach((input) => {
                const cell = saved.cells?.[input.dataset.shift];
                const value = cell?.saved ?? '';

                input.value = value;
                input.dataset.saved = value;

                if (cell && cell.ratePaise !== null && cell.ratePaise !== undefined) {
                    input.dataset.ratePaise = String(cell.ratePaise);
                }
            });

            const badge = row.querySelector('[data-row-status]');

            if (badge && saved.status) {
                delete badge.dataset.original;
                badge.textContent = this.message(`status${saved.status === 'saved' ? 'Saved' : 'Empty'}`) || badge.textContent;
            }
        });

        this.recalculate();
    }

    showErrors(body) {
        const messages = body?.errors
            ? Object.values(body.errors).flat()
            : [body?.message ?? this.message('saveFailed')];

        this.announce(messages.join(' '), 'danger', this.message('saveFailed'));
    }

    /**
     * Copy Previous Day. Fills the form and writes nothing.
     *
     * Confirmation is required whenever there is something to lose — unsaved typing
     * or quantities already recorded for this day — because the copy replaces what
     * is in the fields, and silently overwriting a morning's work would be the
     * worst possible behaviour for a button people press by habit.
     */
    async copyPreviousDay() {
        if (!this.canSave) {
            return;
        }

        const wouldOverwrite =
            this.dirty ||
            this.rows.some((row) =>
                this.cells(row).some((input) => String(input.value ?? '').trim() !== ''),
            );

        if (wouldOverwrite && !window.confirm(this.message('copyConfirm'))) {
            return;
        }

        this.copyButton?.setAttribute('disabled', 'disabled');

        try {
            const response = await fetch(this.copyUrl, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                this.announce(this.message('saveFailed'), 'danger');

                return;
            }

            const body = await response.json();
            const filled = this.fillFrom(body.quantities ?? {});

            this.recalculate();

            if (filled > 0) {
                this.markDirty();
                this.announce(this.message('copyDone').replace(':count', String(filled)), 'info');
            } else {
                this.announce(this.message('copyNone'), 'secondary');
            }
        } catch (error) {
            this.announce(this.message('networkFailed'), 'danger');
        } finally {
            this.copyButton?.removeAttribute('disabled');
        }
    }

    /**
     * Writes copied quantities into the fields.
     *
     * Only rows the server offered are touched, and only editable ones — a paused,
     * archived or not-yet-started customer is absent from the response entirely, so
     * a value can never be staged that the save would then refuse. Rows with no
     * delivery yesterday are cleared rather than left behind, so what the operator
     * sees is yesterday's round and not a mixture of two days.
     */
    fillFrom(quantities) {
        let filled = 0;

        this.rows.forEach((row) => {
            if (row.dataset.editable !== '1') {
                return;
            }

            const copied = quantities[row.dataset.entryKey] ?? {};

            this.cells(row).forEach((input) => {
                const value = copied[input.dataset.shift];

                if (value !== undefined && value !== null) {
                    input.value = value;
                    filled += 1;
                } else {
                    input.value = '';
                }
            });
        });

        return filled;
    }

    setBusy(busy) {
        if (!this.saveButton) {
            return;
        }

        this.saveButton.disabled = busy;
        this.saveButton.textContent = busy ? this.message('saving') : this.message('saveDay');
    }

    announce(text, variant, heading = null) {
        if (!this.feedback || !text) {
            return;
        }

        this.feedback.innerHTML = '';

        const alert = document.createElement('div');
        alert.className = `alert alert-${variant} py-2 mb-0`;
        alert.setAttribute('role', variant === 'danger' ? 'alert' : 'status');

        if (heading) {
            const strong = document.createElement('strong');
            strong.className = 'd-block';
            strong.textContent = heading;
            alert.appendChild(strong);
        }

        alert.appendChild(document.createTextNode(text));
        this.feedback.appendChild(alert);
    }
}

export function initCustomerDailyEntry() {
    document.querySelectorAll('[data-daily-entry]').forEach((root) => new DailyEntryGrid(root));
}
