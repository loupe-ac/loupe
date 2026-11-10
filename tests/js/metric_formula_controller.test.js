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

function renderedRow(letter, metric) {
    return `<div data-metric-formula-target="row" data-letter="${letter}">
        <span data-metric-formula-role="letter">${letter}</span>
        <select data-metric-formula-role="metric" data-action="change->metric-formula#rowMetricChanged">
            <option value="">Choose</option>
            <option value="cost"${'cost' === metric ? ' selected' : ''}>Cost</option>
            <option value="wakes"${'wakes' === metric ? ' selected' : ''}>Wakes</option>
        </select>
        <select data-metric-formula-role="statistic" data-action="change->metric-formula#change"><option value="sum" selected>Sum</option></select>
        <button type="button" aria-label="Remove row ${letter}" data-action="click->metric-formula#removeRow"></button>
    </div>`;
}

async function mount({ open = false } = {}) {
    application = Application.start();
    application.register('metric-formula', MetricFormulaController);
    document.body.innerHTML = `<form data-controller="metric-formula" data-turbo-frame="metrics-result" data-action="submit->metric-formula#guard"
        data-metric-formula-joinable-value='${JSON.stringify(JOINABLE)}'
        data-metric-formula-labels-value='{"sum":"Sum","mean":"Mean","count":"Count"}'
        data-metric-formula-check-url-value="/check"
        data-metric-formula-default-metric-value="cost"
        data-metric-formula-open-value="${open}">
        <div data-metric-formula-target="panel"${open ? '' : ' hidden'}>
            <div data-metric-formula-target="rows">${open ? renderedRow('A', 'cost') + renderedRow('B', 'wakes') : ''}</div>
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
            <input data-metric-formula-target="formulaText" data-action="input->metric-formula#change" value="${open ? 'A / B' : ''}">
            <select data-metric-formula-target="format"><option value="auto">Auto</option></select>
            <input type="hidden" name="rows" data-metric-formula-target="rowsField" disabled>
            <input type="hidden" name="formula" data-metric-formula-target="formulaField" disabled>
            <p data-metric-formula-target="error" hidden></p>
        </div>
        <div data-metric-formula-target="single"${open ? ' hidden' : ''}><select id="metrics-metric" name="metric" data-metric-formula-target="metric" data-action="change->metric-formula#apply"${open ? ' disabled' : ''}><option value="cost" selected>Cost</option><option value="wakes">Wakes</option><option value="stop-rate">Stop rate</option></select></div>
        <div data-metric-formula-target="single"${open ? ' hidden' : ''}><select id="metrics-statistic" name="statistic" data-metric-formula-target="statistic" data-action="change->metric-formula#apply"${open ? ' disabled' : ''}><option value="median">Median</option></select></div>
        <select id="metrics-unit" name="unit" data-metric-formula-target="unit" data-action="change->metric-formula#apply"><option value="run">Run</option><option value="card" selected>Card</option></select>
        <select id="metrics-group" name="group" data-metric-formula-target="group" data-action="change->metric-formula#apply"><option value="none">None</option><option value="model">Model</option><option value="wake-kind">Wake kind</option></select>
        <select id="metrics-bucket" name="bucket" data-action="change->metric-formula#apply"><option value="day">Day</option><option value="week" selected>Week</option></select>
        <div data-metric-formula-target="addFormula"><button type="button" id="open" data-action="click->metric-formula#open"></button><button type="button" id="close" data-action="click->metric-formula#close"></button></div>
    </form>
    <div data-metrics-result><turbo-frame id="metrics-result"></turbo-frame></div>`;
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

it('adds an empty row without a visit, because the query does not change', async () => {
    fetch.mockResolvedValue({ json: async () => ({ ok: true, error: null }) });
    const form = await mount({ open: true });
    document.querySelector('[data-metric-formula-target="addRow"]').click();
    await new Promise((resolve) => setTimeout(resolve, 400));

    expect(rows().map((row) => row.dataset.letter)).toEqual(['A', 'B', 'C']);
    expect(form.requestSubmit).not.toHaveBeenCalled();

    await pick(rows()[2], 'cost');
    await new Promise((resolve) => setTimeout(resolve, 400));

    expect(form.requestSubmit).toHaveBeenCalled();
});

it('keeps Save off and blocks the submit when a check fails to answer', async () => {
    fetch.mockRejectedValue(new Error('offline'));
    const form = await mount();
    document.querySelector('#open').click();
    await settle();
    await new Promise((resolve) => setTimeout(resolve, 400));

    const submit = new Event('submit', { cancelable: true });
    form.dispatchEvent(submit);
    expect(submit.defaultPrevented).toBe(true);
    expect(form.requestSubmit).not.toHaveBeenCalled();
});

it('starts the empty row on Sum', async () => {
    await mount();
    document.querySelector('#open').click();
    await settle();

    const statistic = rows()[1].querySelector(
        '[data-metric-formula-role="statistic"]',
    );
    expect(statistic.value).toBe('sum');
});

it('does not submit the changed input when an older check returns', async () => {
    let release;
    fetch.mockImplementation(
        () =>
            new Promise((resolve) => {
                release = () =>
                    resolve({ json: async () => ({ ok: true, error: null }) });
            }),
    );
    const form = await mount();
    document.querySelector('#open').click();
    await settle();
    await new Promise((resolve) => setTimeout(resolve, 350));
    const text = document.querySelector(
        '[data-metric-formula-target="formulaText"]',
    );
    text.value = 'A / 2';
    text.dispatchEvent(new Event('input', { bubbles: true }));
    release();
    await settle();

    expect(form.requestSubmit).not.toHaveBeenCalled();
    const submit = new Event('submit', { cancelable: true });
    form.dispatchEvent(submit);
    expect(submit.defaultPrevented).toBe(true);
});

it('closes onto the earliest row that holds a metric, or onto Cost when none does', async () => {
    const form = await mount();
    document.querySelector('#open').click();
    await settle();
    await pick(rows()[1], 'wakes');
    rows()[0].querySelector('button').click();
    document.querySelector('#close').click();

    const metric = document.querySelector('select[name="metric"]');
    const statistic = document.querySelector('select[name="statistic"]');
    expect(metric.value).toBe('wakes');
    expect(metric.disabled).toBe(false);
    expect(optionsOf(statistic)).toEqual(['sum']);
    expect(statistic.disabled).toBe(false);
    expect(
        form.querySelectorAll('input[type="hidden"][name="metric"]'),
    ).toHaveLength(0);
    expect(document.querySelector('[name="rows"]').disabled).toBe(true);
    expect(
        document.querySelector('[data-metric-formula-target="panel"]').hidden,
    ).toBe(true);
    expect(
        document.querySelector('[data-metric-formula-target="single"]').hidden,
    ).toBe(false);
    expect(form.requestSubmit).toHaveBeenCalled();
});

it('closes onto the lowest letter when a removed letter was added again', async () => {
    const form = await mount();
    document.querySelector('#open').click();
    await settle();
    rows()[0].querySelector('button').click();
    document.querySelector('[data-metric-formula-target="addRow"]').click();
    await settle();
    const added = rows().find((row) => 'A' === row.dataset.letter);
    await pick(added, 'wakes');
    await pick(
        rows().find((row) => 'B' === row.dataset.letter),
        'cost',
    );
    document.querySelector('#close').click();

    expect(document.querySelector('select[name="metric"]').value).toBe('wakes');
    expect(form.requestSubmit).toHaveBeenCalled();
});

it('closes onto Cost when no row holds a metric', async () => {
    const form = await mount();
    document.querySelector('[name="metric"]').value = 'stop-rate';
    document.querySelector('#open').click();
    await settle();
    document.querySelector('#close').click();

    expect(document.querySelector('select[name="metric"]').value).toBe('cost');
    // No statistic goes out, so the server picks the default of Cost.
    expect(
        optionsOf(document.querySelector('select[name="statistic"]')),
    ).toEqual([]);
    expect(form.requestSubmit).toHaveBeenCalled();
});

function result() {
    return document.querySelector('[data-metrics-result]');
}

function streamRender(target) {
    const stream = document.createElement('div');
    stream.target = target;
    document.body.append(stream);
    const event = new CustomEvent('turbo:before-stream-render', {
        bubbles: true,
        cancelable: true,
    });
    stream.dispatchEvent(event);
    stream.remove();

    return event.defaultPrevented;
}

it('sends a setting change at once and shows the result busy until the frame renders', async () => {
    const form = await mount();
    const unit = document.querySelector('[name="unit"]');
    unit.value = 'run';
    unit.dispatchEvent(new Event('change', { bubbles: true }));

    expect(form.requestSubmit).toHaveBeenCalledTimes(1);
    expect(result().getAttribute('aria-busy')).toBe('true');

    form.dispatchEvent(new Event('submit', { cancelable: true }));
    document
        .querySelector('turbo-frame')
        .dispatchEvent(new Event('turbo:frame-load', { bubbles: true }));

    expect(result().hasAttribute('aria-busy')).toBe(false);
});

it('shows the result busy from a formula change through the check and drops it when the check fails', async () => {
    const form = await mount({ open: true });
    const text = document.querySelector(
        '[data-metric-formula-target="formulaText"]',
    );
    text.value = 'A / (B';
    text.dispatchEvent(new Event('input', { bubbles: true }));

    expect(result().getAttribute('aria-busy')).toBe('true');
    expect(fetch).not.toHaveBeenCalled();

    await new Promise((resolve) => setTimeout(resolve, 400));

    expect(fetch).toHaveBeenCalled();
    expect(result().hasAttribute('aria-busy')).toBe(false);
    expect(form.hasAttribute('data-formula-invalid')).toBe(true);
    expect(form.requestSubmit).not.toHaveBeenCalled();
});

it('drops the busy state when a check fails to answer', async () => {
    fetch.mockRejectedValue(new Error('offline'));
    await mount({ open: true });
    document.querySelector('[data-metric-formula-target="addRow"]').click();

    expect(result().getAttribute('aria-busy')).toBe('true');
    await new Promise((resolve) => setTimeout(resolve, 400));

    expect(result().hasAttribute('aria-busy')).toBe(false);
});

it('drops the busy state when an added empty row leaves the query as it is', async () => {
    fetch.mockResolvedValue({ json: async () => ({ ok: true, error: null }) });
    const form = await mount({ open: true });
    document.querySelector('[data-metric-formula-target="addRow"]').click();
    await new Promise((resolve) => setTimeout(resolve, 400));

    expect(form.requestSubmit).not.toHaveBeenCalled();
    expect(result().hasAttribute('aria-busy')).toBe(false);
});

it('keeps the result busy when a newer submit aborts the older one', async () => {
    const form = await mount();
    document
        .querySelector('[name="unit"]')
        .dispatchEvent(new Event('change', { bubbles: true }));
    form.dispatchEvent(new Event('submit', { cancelable: true }));
    form.dispatchEvent(
        new CustomEvent('turbo:submit-end', { detail: { success: false } }),
    );

    expect(result().getAttribute('aria-busy')).toBe('true');

    form.dispatchEvent(
        new CustomEvent('turbo:submit-end', {
            detail: { success: false, error: new Error('offline') },
        }),
    );

    expect(result().hasAttribute('aria-busy')).toBe(false);
});

it('leaves Statistic, Unit and Group to the formula rows, and applies them to a single metric', async () => {
    await mount({ open: true });

    expect(streamRender('metrics-unit')).toBe(true);
    expect(streamRender('metrics-statistic')).toBe(true);
    expect(streamRender('metrics-bucket')).toBe(false);

    document.querySelector('#close').click();
    document
        .querySelector('form')
        .dispatchEvent(new Event('submit', { cancelable: true }));

    expect(streamRender('metrics-unit')).toBe(false);
    expect(streamRender('somewhere-else')).toBe(false);
});

it('keeps a changed setting that waits for the formula when an older answer lands', async () => {
    fetch.mockResolvedValue({ json: async () => ({ ok: true, error: null }) });
    const form = await mount({ open: true });
    const text = document.querySelector(
        '[data-metric-formula-target="formulaText"]',
    );
    text.value = 'A * B';
    text.dispatchEvent(new Event('input', { bubbles: true }));
    const bucket = document.querySelector('[name="bucket"]');
    bucket.value = 'day';
    bucket.dispatchEvent(new Event('change', { bubbles: true }));
    const blocked = new Event('submit', { cancelable: true });
    form.dispatchEvent(blocked);

    expect(blocked.defaultPrevented).toBe(true);
    expect(streamRender('metrics-bucket')).toBe(true);

    form.requestSubmit.mockClear();
    await new Promise((resolve) => setTimeout(resolve, 400));

    expect(form.requestSubmit).toHaveBeenCalled();
});

it('drops a frame answer that lands while the page visits another page', async () => {
    await mount();
    const frame = document.querySelector('turbo-frame');
    const render = () => {
        const event = new CustomEvent('turbo:before-frame-render', {
            bubbles: true,
            cancelable: true,
        });
        frame.dispatchEvent(event);

        return event.defaultPrevented;
    };

    expect(render()).toBe(false);
    document.documentElement.setAttribute('aria-busy', 'true');
    expect(render()).toBe(true);
    document.documentElement.removeAttribute('aria-busy');
});
