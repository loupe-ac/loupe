/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import MetricFormulaController from '../../assets/controllers/metric_formula_controller.js';

const JOINABLE = {
    cost: {
        units: ['run', 'card'],
        groups: ['none', 'model'],
        statistics: ['sum', 'mean'],
    },
    wakes: {
        units: ['run', 'card'],
        groups: ['none', 'model', 'wake-kind'],
        statistics: ['sum', 'count'],
    },
    'run-only': { units: ['run'], groups: ['none'], statistics: ['sum'] },
};

let application;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

function optionsOf(select) {
    return [...select.options].map((option) => option.value);
}

async function mount() {
    application = Application.start();
    application.register('metric-formula', MetricFormulaController);
    document.body.innerHTML = `<form data-controller="metric-formula" data-action="submit->metric-formula#guard"
        data-metric-formula-joinable-value='${JSON.stringify(JOINABLE)}'
        data-metric-formula-labels-value='{"sum":"Sum","mean":"Mean","count":"Count"}'
        data-metric-formula-check-url-value="/check"
        data-metric-formula-default-metric-value="cost">
        <div data-metric-formula-target="panel" hidden>
            <div data-metric-formula-target="rows"></div>
            <template data-metric-formula-target="rowTemplate"><div data-metric-formula-target="row" data-letter="@">
                <span data-metric-formula-role="letter">@</span>
                <select data-metric-formula-role="metric" data-action="change->metric-formula#rowMetricChanged">
                    <option value="">Choose</option>
                    <option value="cost">Cost</option>
                    <option value="wakes">Wakes</option>
                    <optgroup label="Narrow"><option value="run-only">Run only</option></optgroup>
                </select>
                <select data-metric-formula-role="statistic" data-action="change->metric-formula#change"></select>
                <button type="button" aria-label="Remove row @" data-action="click->metric-formula#removeRow"></button>
            </div></template>
            <template data-metric-formula-target="unitTemplate"><option value="run">Run</option><option value="card">Card</option></template>
            <template data-metric-formula-target="groupTemplate"><option value="none">None</option><option value="model">Model</option><option value="wake-kind">Wake kind</option></template>
            <button type="button" data-metric-formula-target="addRow" data-action="click->metric-formula#addRow"></button>
            <input data-metric-formula-target="formulaText" data-action="input->metric-formula#change">
            <select data-metric-formula-target="format"><option value="auto">Auto</option></select>
            <input type="hidden" name="rows" data-metric-formula-target="rowsField" disabled>
            <input type="hidden" name="formula" data-metric-formula-target="formulaField" disabled>
            <p data-metric-formula-target="error" hidden></p>
        </div>
        <div data-metric-formula-target="single"><select name="metric" data-metric-formula-target="metric"><option value="cost" selected>Cost</option><option value="stop-rate">Stop rate</option></select></div>
        <div data-metric-formula-target="single"><select name="statistic" data-metric-formula-target="statistic"><option value="sum">Sum</option></select></div>
        <select name="unit" data-metric-formula-target="unit"><option value="run">Run</option><option value="card" selected>Card</option></select>
        <select name="group" data-metric-formula-target="group"><option value="none">None</option><option value="model">Model</option><option value="wake-kind">Wake kind</option></select>
        <div data-metric-formula-target="addFormula"><button type="button" id="open" data-action="click->metric-formula#open"></button><button type="button" id="close" data-action="click->metric-formula#close"></button></div>
    </form>`;
    await settle();

    return document.querySelector('form');
}

function rows() {
    return [...document.querySelectorAll('[data-metric-formula-target="row"]')];
}

async function pick(row, metric) {
    await settle();
    const select = row.querySelector('[data-metric-formula-role="metric"]');
    select.value = metric;
    select.dispatchEvent(new Event('change', { bubbles: true }));
}

beforeEach(() => {
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({
            json: async () => ({ ok: false, error: 'No row for B.' }),
        })),
    );
    HTMLFormElement.prototype.requestSubmit = vi.fn();
});

afterEach(() => {
    application.stop();
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
});

it('opens with row A on the current metric, an empty row B and the formula A / B', async () => {
    await mount();
    document.querySelector('#open').click();
    await settle();

    expect(rows().map((row) => row.dataset.letter)).toEqual(['A', 'B']);
    expect(
        rows()[0].querySelector('[data-metric-formula-role="metric"]').value,
    ).toBe('cost');
    expect(
        rows()[1].querySelector('[data-metric-formula-role="metric"]').value,
    ).toBe('');
    expect(
        document.querySelector('[data-metric-formula-target="formulaText"]')
            .value,
    ).toBe('A / B');
    expect(document.querySelector('[name="rows"]').value).toBe('A:cost:sum');
    expect(
        document.querySelector('[data-metric-formula-target="panel"]').hidden,
    ).toBe(false);
    expect(document.querySelector('[name="metric"]').disabled).toBe(true);
});

it('starts row A empty when the current metric cannot join', async () => {
    await mount();
    document.querySelector('[name="metric"]').value = 'stop-rate';
    document.querySelector('#open').click();
    await settle();

    expect(
        rows()[0].querySelector('[data-metric-formula-role="metric"]').value,
    ).toBe('');
});

it('drops a wake Group when a Cost row joins and brings it back when that row goes', async () => {
    await mount();
    document.querySelector('#open').click();
    await settle();
    const group = document.querySelector('[name="group"]');
    await pick(rows()[0], 'wakes');
    expect(optionsOf(group)).toEqual(['none', 'model', 'wake-kind']);

    await pick(rows()[1], 'cost');
    expect(optionsOf(group)).toEqual(['none', 'model']);

    rows()[1].querySelector('button').click();
    expect(optionsOf(group)).toEqual(['none', 'model', 'wake-kind']);
});

it('moves a Group that no longer fits to None and a Unit that no longer fits to the first allowed Unit', async () => {
    await mount();
    const group = document.querySelector('[name="group"]');
    const unit = document.querySelector('[name="unit"]');
    group.value = 'wake-kind';
    document.querySelector('#open').click();
    await settle();
    await pick(rows()[0], 'wakes');
    group.value = 'wake-kind';
    await pick(rows()[1], 'cost');
    expect(group.value).toBe('none');

    await pick(rows()[1], 'run-only');
    expect(optionsOf(unit)).toEqual(['run']);
    expect(unit.value).toBe('run');
});

it('leaves out a metric whose Units share nothing with the other rows, and never an empty row', async () => {
    const joinable = {
        ...JOINABLE,
        'run-only': { ...JOINABLE['run-only'], units: ['run'] },
        wakes: { ...JOINABLE.wakes, units: ['card'] },
    };
    await mount();
    document.querySelector('form').dataset.metricFormulaJoinableValue =
        JSON.stringify(joinable);
    document.querySelector('#open').click();
    await settle();
    await pick(rows()[0], 'wakes');

    expect(
        optionsOf(
            rows()[1].querySelector('[data-metric-formula-role="metric"]'),
        ),
    ).not.toContain('run-only');
    expect(
        optionsOf(
            rows()[0].querySelector('[data-metric-formula-role="metric"]'),
        ),
    ).toContain('run-only');
});

it('gives a new row the free letter, stops at six rows and keeps the other letters when one goes', async () => {
    await mount();
    document.querySelector('#open').click();
    await settle();
    for (let count = 0; count < 4; count += 1) {
        document.querySelector('[data-metric-formula-target="addRow"]').click();
    }
    expect(rows().map((row) => row.dataset.letter)).toEqual([
        'A',
        'B',
        'C',
        'D',
        'E',
        'F',
    ]);
    expect(
        document.querySelector('[data-metric-formula-target="addRow"]').hidden,
    ).toBe(true);

    rows()[1].querySelector('button').click();
    expect(rows().map((row) => row.dataset.letter)).toEqual([
        'A',
        'C',
        'D',
        'E',
        'F',
    ]);
    document.querySelector('[data-metric-formula-target="addRow"]').click();
    expect(rows().map((row) => row.dataset.letter)).toEqual([
        'A',
        'C',
        'D',
        'E',
        'F',
        'B',
    ]);
});

it('shows the server error and blocks the submit', async () => {
    const form = await mount();
    document.querySelector('#open').click();
    await settle();
    await new Promise((resolve) => setTimeout(resolve, 400));

    expect(
        document.querySelector('[data-metric-formula-target="error"]')
            .textContent,
    ).toBe('No row for B.');
    expect(form.hasAttribute('data-formula-invalid')).toBe(true);
    const submit = new Event('submit', { cancelable: true });
    form.dispatchEvent(submit);
    expect(submit.defaultPrevented).toBe(true);
});

it('submits when the check says the formula runs', async () => {
    fetch.mockResolvedValue({ json: async () => ({ ok: true, error: null }) });
    const form = await mount();
    document.querySelector('#open').click();
    await settle();
    await pick(rows()[1], 'wakes');
    await new Promise((resolve) => setTimeout(resolve, 400));

    expect(form.hasAttribute('data-formula-invalid')).toBe(false);
    expect(form.requestSubmit).toHaveBeenCalled();
    expect(String(fetch.mock.calls.at(-1)[0])).toContain(
        'rows=A%3Acost%3Asum%2CB%3Awakes%3Asum',
    );
});

it('closes onto the earliest row that holds a metric, or onto Cost when none does', async () => {
    const form = await mount();
    document.querySelector('#open').click();
    await settle();
    await pick(rows()[1], 'wakes');
    rows()[0].querySelector('button').click();
    document.querySelector('#close').click();

    expect(
        form.querySelector('input[name="metric"][type="hidden"]').value,
    ).toBe('wakes');
    expect(
        form.querySelector('input[name="statistic"][type="hidden"]').value,
    ).toBe('sum');
    expect(document.querySelector('[name="rows"]').disabled).toBe(true);
    expect(form.requestSubmit).toHaveBeenCalled();
});

it('closes onto Cost when no row holds a metric', async () => {
    const form = await mount();
    document.querySelector('[name="metric"]').value = 'stop-rate';
    document.querySelector('#open').click();
    await settle();
    document.querySelector('#close').click();

    expect(
        form.querySelector('input[name="metric"][type="hidden"]').value,
    ).toBe('cost');
    expect(
        form.querySelector('input[name="statistic"][type="hidden"]'),
    ).toBeNull();
});
