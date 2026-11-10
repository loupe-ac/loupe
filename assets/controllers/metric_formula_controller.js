import { Controller } from '@hotwired/stimulus';

const LETTERS = 'ABCDEF';
const CHECK_DELAY = 300;
const FORMULA_OWNED = ['metrics-statistic', 'metrics-unit', 'metrics-group'];

/**
 * The controls of the Metrics tab. It adds and removes formula rows, writes the hidden rows and formula
 * fields, narrows Unit, Group and each row's Metric list to what every row allows, and asks the server
 * whether the formula can run before the form submits. The form reloads the result frame alone, and
 * the frame answer corrects the options that depend on the query. The result shows busy from a change
 * until its graph renders.
 */
export default class extends Controller {
    static targets = [
        'panel',
        'rows',
        'row',
        'rowTemplate',
        'unitTemplate',
        'groupTemplate',
        'addRow',
        'addFormula',
        'single',
        'metric',
        'statistic',
        'unit',
        'group',
        'format',
        'formulaText',
        'rowsField',
        'formulaField',
        'error',
    ];

    static values = {
        joinable: Object,
        labels: Object,
        checkUrl: String,
        defaultMetric: String,
        open: Boolean,
    };

    connect() {
        this.invalid = false;
        this.sequence = 0;
        this.checking = false;
        this.unverified = false;
        this.waiting = false;
        this.timer = null;
        this.shown = null;
        document.addEventListener(
            'turbo:before-stream-render',
            this.#onStreamRender,
        );
        document.addEventListener('turbo:frame-load', this.#onFrameLoad);
        document.addEventListener(
            'turbo:before-frame-render',
            this.#onBeforeFrameRender,
        );
        document.addEventListener(
            'turbo:fetch-request-error',
            this.#onRequestError,
        );
        this.element.addEventListener('turbo:submit-end', this.#onSubmitEnd);
        if (this.openValue) {
            this.narrow();
            this.#updateAddRow();
            this.#compose();
            this.shown = this.#signature();
        }
        if (!this.errorTarget.hidden && '' !== this.errorTarget.textContent) {
            this.#setInvalid(this.errorTarget.textContent);
        }
    }

    disconnect() {
        clearTimeout(this.timer);
        document.removeEventListener(
            'turbo:before-stream-render',
            this.#onStreamRender,
        );
        document.removeEventListener('turbo:frame-load', this.#onFrameLoad);
        document.removeEventListener(
            'turbo:before-frame-render',
            this.#onBeforeFrameRender,
        );
        document.removeEventListener(
            'turbo:fetch-request-error',
            this.#onRequestError,
        );
        this.element.removeEventListener('turbo:submit-end', this.#onSubmitEnd);
    }

    /** A control that needs no check sends the form at once, so no older answer can undo it. */
    apply() {
        this.waiting = true;
        if (this.openValue && this.invalid) {
            return;
        }
        this.#setBusy(true);
        this.element.requestSubmit();
    }

    open() {
        const current = this.metricTarget.value;
        this.openValue = true;
        this.panelTarget.hidden = false;
        this.addFormulaTarget.hidden = true;
        this.singleTargets.forEach((field) => (field.hidden = true));
        this.metricTarget.disabled = true;
        this.metricTarget.tomselect?.disable();
        this.statisticTarget.disabled = true;
        this.formatTarget.disabled = false;
        this.rowsFieldTarget.disabled = false;
        this.formulaFieldTarget.disabled = false;
        this.rowsTarget.replaceChildren();
        this.#buildRow('A', current in this.joinableValue ? current : '');
        this.#buildRow('B', '');
        this.formulaTextTarget.value = 'A / B';
        this.formatTarget.value = 'auto';
        this.change();
    }

    addRow() {
        const used = this.rowTargets.map((row) => row.dataset.letter);
        const free = [...LETTERS].find((letter) => !used.includes(letter));
        if (undefined === free) {
            return;
        }
        this.#buildRow(free, '');
        this.change();
    }

    removeRow(event) {
        event.target.closest('[data-metric-formula-target="row"]').remove();
        this.change();
    }

    rowMetricChanged(event) {
        const row = event.target.closest('[data-metric-formula-target="row"]');
        this.#fillStatistics(row, this.#metricOf(row), 'sum');
        this.change();
    }

    change() {
        this.sequence += 1;
        this.checking = true;
        this.unverified = false;
        this.#setBusy(true);
        this.#setSaveDisabled(true);
        this.#compose();
        this.narrow();
        this.#updateAddRow();
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.#check(), CHECK_DELAY);
    }

    /** Closing keeps the earliest row that holds a metric, or the metric the tab opens with. */
    close() {
        const first = this.#byLetter().find(
            (row) => '' !== this.#metricOf(row),
        );
        const metric =
            undefined === first
                ? this.defaultMetricValue
                : this.#metricOf(first);
        const statistic = undefined === first ? null : this.#statisticOf(first);
        clearTimeout(this.timer);
        this.sequence += 1;
        this.checking = false;
        this.unverified = false;
        this.#setInvalid(null);
        this.openValue = false;
        this.panelTarget.hidden = true;
        this.addFormulaTarget.hidden = false;
        this.singleTargets.forEach((field) => (field.hidden = false));
        [
            this.rowsFieldTarget,
            this.formulaFieldTarget,
            this.formatTarget,
        ].forEach((field) => (field.disabled = true));
        this.metricTarget.disabled = false;
        this.statisticTarget.disabled = false;
        const tomSelect = this.metricTarget.tomselect;
        if (tomSelect) {
            tomSelect.enable();
            tomSelect.setValue(metric, true);
        } else {
            this.metricTarget.value = metric;
        }
        // The frame answer brings the Statistic list of the metric. An empty list lets the server pick.
        this.statisticTarget.replaceChildren(
            ...(null === statistic
                ? []
                : [
                      new Option(
                          this.labelsValue[statistic] ?? statistic,
                          statistic,
                          true,
                          true,
                      ),
                  ]),
        );
        this.apply();
    }

    /** A formula that cannot run never submits. */
    guard(event) {
        if (this.openValue) {
            this.#compose();
        }
        if (
            this.invalid ||
            (this.openValue && (this.checking || this.unverified))
        ) {
            event.preventDefault();
            if (!this.checking) {
                this.#setBusy(false);
            }
            return;
        }
        this.waiting = false;
        this.shown = this.openValue ? this.#signature() : null;
        this.#setBusy(true);
    }

    narrow() {
        const filled = this.rowTargets
            .map((row) => ({ row, key: this.#metricOf(row) }))
            .filter((entry) => entry.key in this.joinableValue);
        const units = this.#shared(filled, 'units');
        const groups = this.#shared(filled, 'groups');
        this.#rebuild(this.unitTarget, this.unitTemplateTarget, units, null);
        this.#rebuild(
            this.groupTarget,
            this.groupTemplateTarget,
            groups,
            'none',
        );

        this.rowTargets.forEach((row) => {
            const others = filled.filter((entry) => entry.row !== row);
            const sharedUnits = this.#shared(others, 'units');
            this.#narrowMetrics(row, sharedUnits);
        });
    }

    #shared(entries, name) {
        if (0 === entries.length) {
            return null;
        }

        return entries
            .map((entry) => this.joinableValue[entry.key][name])
            .reduce((left, right) =>
                left.filter((value) => right.includes(value)),
            );
    }

    #rebuild(select, template, allowed, fallback) {
        const current = select.value;
        const options = [...template.content.querySelectorAll('option')]
            .filter(
                (option) => null === allowed || allowed.includes(option.value),
            )
            .map((option) => option.cloneNode(true));
        select.replaceChildren(...options);
        if (options.some((option) => option.value === current)) {
            select.value = current;
        } else if (
            null !== fallback &&
            options.some((option) => option.value === fallback)
        ) {
            select.value = fallback;
        } else if (options.length > 0) {
            select.value = options[0].value;
        }
    }

    #narrowMetrics(row, sharedUnits) {
        const select = this.#roleOf(row, 'metric');
        const current = select.value;
        const fits = (option) =>
            '' === option.value ||
            option.value === current ||
            null === sharedUnits ||
            (this.joinableValue[option.value]?.units ?? []).some((unit) =>
                sharedUnits.includes(unit),
            );
        const master = this.rowTemplateTarget.content.querySelector(
            '[data-metric-formula-role="metric"]',
        );
        const children = [];
        [...master.children].forEach((child) => {
            if ('OPTGROUP' !== child.tagName) {
                if (fits(child)) {
                    children.push(child.cloneNode(true));
                }
                return;
            }
            const kept = [...child.children].filter(fits);
            if (kept.length > 0) {
                const group = child.cloneNode(false);
                kept.forEach((option) => group.append(option.cloneNode(true)));
                children.push(group);
            }
        });
        const signature = children.map((child) => child.outerHTML).join('');
        if (select.dataset.signature === signature) {
            return;
        }
        select.dataset.signature = signature;
        select.replaceChildren(...children);
        select.value = current;
        select.tomselect?.sync();
        select.tomselect?.close();
    }

    #buildRow(letter, metric) {
        const row =
            this.rowTemplateTarget.content.firstElementChild.cloneNode(true);
        row.dataset.letter = letter;
        row.querySelector('[data-metric-formula-role="letter"]').textContent =
            letter;
        const remove = row.querySelector('button');
        remove.setAttribute(
            'aria-label',
            remove.getAttribute('aria-label').replace('@', letter),
        );
        this.rowsTarget.append(row);
        this.#roleOf(row, 'metric').value = metric;
        this.#fillStatistics(row, metric, 'sum');

        return row;
    }

    #fillStatistics(row, metric, preferred) {
        const select = this.#roleOf(row, 'statistic');
        // An empty row offers Sum alone, so a new row starts on Sum.
        const allowed = this.joinableValue[metric]?.statistics ?? ['sum'];
        select.replaceChildren(
            ...allowed.map(
                (value) => new Option(this.labelsValue[value] ?? value, value),
            ),
        );
        const wanted = allowed.includes(preferred) ? preferred : allowed[0];
        if (undefined !== wanted) {
            select.value = wanted;
        }
    }

    #byLetter() {
        return [...this.rowTargets].sort((a, b) =>
            a.dataset.letter.localeCompare(b.dataset.letter),
        );
    }

    #compose() {
        this.rowsFieldTarget.value = this.#byLetter()
            .filter((row) => '' !== this.#metricOf(row))
            .map(
                (row) =>
                    `${row.dataset.letter}:${this.#metricOf(row)}:${this.#statisticOf(row)}`,
            )
            .join(',');
        this.formulaFieldTarget.value = this.formulaTextTarget.value;
    }

    async #check() {
        const sequence = this.sequence;
        const url = new URL(this.checkUrlValue, window.location.origin);
        url.searchParams.set('rows', this.rowsFieldTarget.value);
        url.searchParams.set('formula', this.formulaFieldTarget.value);
        url.searchParams.set('format', this.formatTarget.value);
        let result;
        try {
            // A read-only check that changes nothing, so it has no form to go through.
            // eslint-disable-next-line no-restricted-syntax
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
            });
            result = await response.json();
        } catch {
            if (sequence === this.sequence) {
                // A check that never answered proves nothing, so Save stays off.
                this.checking = false;
                this.unverified = true;
                this.#setBusy(false);
                this.#setSaveDisabled(true);
            }
            return;
        }
        if (sequence !== this.sequence) {
            return;
        }
        this.checking = false;
        if (result.ok) {
            this.#setInvalid(null);
            // An empty row adds nothing to the query, so the graph on the page is still right.
            if (this.waiting || this.#signature() !== this.shown) {
                this.element.requestSubmit();
            } else {
                this.#setBusy(false);
            }
        } else {
            this.#setInvalid(result.error);
        }
    }

    #signature() {
        return [
            this.rowsFieldTarget.value,
            this.formulaFieldTarget.value,
            this.formatTarget.value,
        ].join('\n');
    }

    #setInvalid(message) {
        this.invalid = null !== message;
        if (this.invalid) {
            this.#setBusy(false);
        }
        this.errorTarget.hidden = null === message;
        this.errorTarget.textContent = message ?? '';
        this.element.toggleAttribute('data-formula-invalid', this.invalid);
        this.#setSaveDisabled(this.invalid);
    }

    /** A button the server rendered disabled, such as Save as new at the cap, stays disabled. */
    #setSaveDisabled(disabled) {
        document
            .querySelectorAll('[data-save-new], [data-save-update]')
            .forEach((button) => {
                button.dataset.serverDisabled ??= button.disabled ? '1' : '0';
                button.disabled =
                    disabled || '1' === button.dataset.serverDisabled;
            });
    }

    #updateAddRow() {
        this.addRowTarget.hidden = this.rowTargets.length >= LETTERS.length;
    }

    get #result() {
        return document
            .getElementById(this.element.dataset.turboFrame ?? '')
            ?.closest('[data-metrics-result]');
    }

    #setBusy(busy) {
        if (busy) {
            this.#result?.setAttribute('aria-busy', 'true');
        } else {
            this.#result?.removeAttribute('aria-busy');
        }
    }

    /**
     * An answer that lands while a newer change waits to go out must not reset that change. In a
     * formula the rows narrow Statistic, Unit and Group, so their streams never apply.
     */
    #onStreamRender = (event) => {
        const target = document.getElementById(event.target.target ?? '');
        if (null === target || !this.element.contains(target)) {
            return;
        }
        if (
            this.waiting ||
            (this.openValue && FORMULA_OWNED.includes(target.id))
        ) {
            event.preventDefault();
        }
    };

    /**
     * Turbo marks the page busy while it visits another page. A frame answer that lands then would
     * replace the URL and cancel that visit, so it never renders.
     */
    #onBeforeFrameRender = (event) => {
        if (
            event.target.id === this.element.dataset.turboFrame &&
            document.documentElement.hasAttribute('aria-busy')
        ) {
            event.preventDefault();
        }
    };

    #onFrameLoad = (event) => {
        if (event.target.id === this.element.dataset.turboFrame) {
            this.#settle();
        }
    };

    #onRequestError = (event) => {
        if (
            event.target === this.element ||
            event.target.id === this.element.dataset.turboFrame
        ) {
            this.#settle();
        }
    };

    /** A newer submit aborts the older one, which ends with neither a response nor an error. */
    #onSubmitEnd = (event) => {
        const { success, fetchResponse, error } = event.detail;
        if (!success && (fetchResponse || error)) {
            this.#settle();
        }
    };

    #settle() {
        if (!this.waiting && !this.checking) {
            this.#setBusy(false);
        }
    }

    #roleOf(row, role) {
        return row.querySelector(`[data-metric-formula-role="${role}"]`);
    }

    #metricOf(row) {
        return this.#roleOf(row, 'metric').value;
    }

    #statisticOf(row) {
        return this.#roleOf(row, 'statistic').value;
    }
}
