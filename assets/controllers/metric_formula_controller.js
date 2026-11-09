import { Controller } from '@hotwired/stimulus';

const LETTERS = 'ABCDEF';
const CHECK_DELAY = 300;

/**
 * The formula rows of the Metrics tab. It adds and removes rows, writes the hidden rows and formula
 * fields, narrows Unit, Group and each row's Metric list to what every row allows, and asks the server
 * whether the formula can run before the form submits. The rules come from the server, in the joinable
 * map. Only the intersection and the two fallbacks live here, and a visit corrects any difference.
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
        this.timer = null;
        if (this.openValue) {
            this.narrow();
            this.#updateAddRow();
        }
        if (!this.errorTarget.hidden && '' !== this.errorTarget.textContent) {
            this.#setInvalid(this.errorTarget.textContent);
        }
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    open() {
        const current = this.metricTarget.value;
        this.openValue = true;
        this.panelTarget.hidden = false;
        this.addFormulaTarget.hidden = true;
        this.singleTargets.forEach((field) => (field.hidden = true));
        this.metricTarget.disabled = true;
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
        this.#setSaveDisabled(true);
        this.#compose();
        this.narrow();
        this.#updateAddRow();
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.#check(), CHECK_DELAY);
    }

    /** Closing keeps the earliest row that holds a metric, or the metric the tab opens with. */
    close() {
        const first = this.rowTargets.find((row) => '' !== this.#metricOf(row));
        const metric =
            undefined === first
                ? this.defaultMetricValue
                : this.#metricOf(first);
        const statistic = undefined === first ? null : this.#statisticOf(first);
        clearTimeout(this.timer);
        this.sequence += 1;
        this.checking = false;
        this.#setInvalid(null);
        [
            this.rowsFieldTarget,
            this.formulaFieldTarget,
            this.formatTarget,
        ].forEach((field) => (field.disabled = true));
        this.#appendHidden('metric', metric);
        if (null !== statistic) {
            this.#appendHidden('statistic', statistic);
        }
        this.element.requestSubmit();
    }

    /** A formula that cannot run never submits. */
    guard(event) {
        if (this.openValue) {
            this.#compose();
        }
        if (this.invalid || (this.openValue && this.checking)) {
            event.preventDefault();
        }
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

    #compose() {
        this.rowsFieldTarget.value = this.rowTargets
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
                this.checking = false;
                this.#setSaveDisabled(this.invalid);
            }
            return;
        }
        if (sequence !== this.sequence) {
            return;
        }
        this.checking = false;
        if (result.ok) {
            this.#setInvalid(null);
            this.element.requestSubmit();
        } else {
            this.#setInvalid(result.error);
        }
    }

    #setInvalid(message) {
        this.invalid = null !== message;
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

    #appendHidden(name, value) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        this.element.append(input);
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
