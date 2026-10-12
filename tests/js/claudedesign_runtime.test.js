/** @vitest-environment jsdom */
import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const path = 'cli/internal/claudedesign/runtime.js';
const source = readFileSync(path, 'utf8');
const LoupeView = new Function(source + '\nreturn LoupeView;')();
const settle = () => Promise.resolve();

beforeEach(() => {
    document.body = document.createElement('body');
    document.body.innerHTML =
        '<script id="loupe-runtime">/* runtime */</script>';
});

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
});

const counter = (extra = {}) => {
    class C extends LoupeView {
        constructor(p) {
            super(p);
            this.state = { n: 0 };
        }
        renderVals() {
            return {
                n: this.state.n,
                up: () => this.setState((s) => ({ n: s.n + 1 })),
            };
        }
    }
    Object.assign(C.prototype, extra);

    return C;
};
const counterRender = (s, h, R) =>
    `<button data-on-click="${h(s.up)}">${R.e(s.n)}</button><p>static</p>`;

it('keeps the runtime source free of forbidden strings', () => {
    for (const bad of [
        'DCLogic',
        '{{',
        '<sc-',
        'support.js',
        '.dc.html',
        '/_blob/',
    ]) {
        expect(source.includes(bad)).toBe(false);
    }
});

it('draws once and updates after a click', async () => {
    LoupeView.mount(counter(), counterRender, {}, ['click'], {});
    const button = document.querySelector('button');
    expect(button.textContent).toBe('0');
    button.click();
    await settle();
    expect(document.querySelector('button')).toBe(button);
    expect(button.textContent).toBe('1');
});

it('keeps the runtime script last and unchanged', async () => {
    LoupeView.mount(counter(), counterRender, {}, ['click'], {});
    const script = document.getElementById('loupe-runtime');
    document.querySelector('button').click();
    await settle();
    expect(document.body.lastElementChild).toBe(script);
    expect(script.textContent).toBe('/* runtime */');
});

it('batches several setState calls into one draw', async () => {
    const render = vi.fn(counterRender);
    const view = LoupeView.mount(counter(), render, {}, [], {});
    view.setState({ n: 5 });
    view.setState((s) => ({ n: s.n + 1 }));
    expect(render).toHaveBeenCalledTimes(1);
    await settle();
    expect(render).toHaveBeenCalledTimes(2);
    expect(document.querySelector('button').textContent).toBe('6');
});

it('only merges state before mount', () => {
    const view = new (counter())({});
    view.setState({ n: 3 });
    expect(view.state.n).toBe(3);
});

it('filters a list on input while the input keeps focus and caret', async () => {
    class C extends LoupeView {
        constructor(p) {
            super(p);
            this.state = { q: '' };
        }
        renderVals() {
            const items = ['apple', 'apricot', 'banana'].filter((i) =>
                i.includes(this.state.q),
            );
            return {
                q: this.state.q,
                items,
                type: (e) => this.setState({ q: e.target.value }),
            };
        }
    }
    const render = (s, h, R) =>
        `<input value="${R.q(s.q)}" data-on-input="${h(s.type)}"><ul>${R.A(
            s.items,
        )
            .map((i) => `<li>${R.e(i)}</li>`)
            .join('')}</ul>`;
    LoupeView.mount(C, render, {}, ['input'], {});
    const input = document.querySelector('input');
    input.focus();
    input.value = 'ap';
    input.setSelectionRange(1, 1);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();
    expect(document.querySelector('input')).toBe(input);
    expect(document.activeElement).toBe(input);
    expect(input.selectionStart).toBe(1);
    expect(document.querySelectorAll('li')).toHaveLength(2);
    input.value = 'ban';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();
    expect(document.querySelectorAll('li')).toHaveLength(1);
    expect(input.value).toBe('ban');
});

const focusAcross = async (render) => {
    class C extends LoupeView {
        constructor(p) {
            super(p);
            this.state = { q: '' };
        }
        renderVals() {
            return {
                q: this.state.q,
                type: (e) => this.setState({ q: e.target.value }),
            };
        }
    }
    LoupeView.mount(C, render, {}, ['input'], {});
    const input = document.querySelector('input[name="q"]');
    input.focus();
    input.value = 'ab';
    input.setSelectionRange(1, 1);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();
    expect(document.querySelector('input[name="q"]')).toBe(input);
    expect(document.activeElement).toBe(input);
    expect(input.selectionStart).toBe(1);
    input.value = '';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();
    expect(document.querySelector('input[name="q"]')).toBe(input);
    expect(document.activeElement).toBe(input);
};

it('keeps focus when a sibling appears before the input', () =>
    focusAcross(
        (s, h, R) =>
            `${R.T(s.q) ? '<div>hint</div>' : ''}<input name="q" value="${R.q(s.q)}" data-on-input="${h(s.type)}">`,
    ));

it('keeps focus when an unnamed input appears before the input', () =>
    focusAcross(
        (s, h, R) =>
            `${R.T(s.q) ? '<input value="x">' : ''}<input name="q" value="${R.q(s.q)}" data-on-input="${h(s.type)}">`,
    ));

it('keeps focus when a sibling before the input disappears', () =>
    focusAcross(
        (s, h, R) =>
            `${R.T(s.q) ? '' : '<p>empty</p>'}<input name="q" value="${R.q(s.q)}" data-on-input="${h(s.type)}">`,
    ));

it('keeps focus when the input swaps places with a keyed sibling', () =>
    focusAcross((s, h, R) => {
        const q = `<input id="q" name="q" value="${R.q(s.q)}" data-on-input="${h(s.type)}">`;
        const other = '<input id="other">';

        return R.T(s.q) ? other + q : q + other;
    }));

it('keeps focus when a keyed sibling moves after the focused input', () =>
    focusAcross((s, h, R) => {
        const q = `<input id="q" name="q" value="${R.q(s.q)}" data-on-input="${h(s.type)}">`;
        const other = '<input id="other">';

        return R.T(s.q) ? q + other : other + q;
    }));

it('keeps focus when unkeyed wrappers of keyed inputs swap places', () =>
    focusAcross((s, h, R) => {
        const q = `<div><input id="q" name="q" value="${R.q(s.q)}" data-on-input="${h(s.type)}"></div>`;
        const other = '<div><input id="other"></div>';

        return R.T(s.q) ? other + q : q + other;
    }));

it('keeps focus when a keyed input appears before it in its wrapper', () =>
    focusAcross(
        (s, h, R) =>
            `<div>${R.T(s.q) ? '<input id="other">' : ''}<input id="q" name="q" value="${R.q(s.q)}" data-on-input="${h(s.type)}"></div>`,
    ));

const focusUnkeyed = async (render) => {
    class C extends LoupeView {
        constructor(p) {
            super(p);
            this.state = { q: '' };
        }
        renderVals() {
            return {
                q: this.state.q,
                type: (e) => this.setState({ q: e.target.value }),
            };
        }
    }
    LoupeView.mount(C, render, {}, ['input'], {});
    const input = document.querySelector('input[data-on-input]');
    input.focus();
    input.value = 'ab';
    input.setSelectionRange(1, 1);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();
    expect(document.querySelector('input[data-on-input]')).toBe(input);
    expect(document.activeElement).toBe(input);
    expect(input.selectionStart).toBe(1);
    expect(input.value).toBe('ab');
    input.value = '';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();
    expect(document.querySelector('input[data-on-input]')).toBe(input);
    expect(document.activeElement).toBe(input);
};

it('keeps focus on an unkeyed input when another appears before it', () =>
    focusUnkeyed(
        (s, h, R) =>
            `${R.T(s.q) ? '<input value="x">' : ''}<input value="${R.q(s.q)}" data-on-input="${h(s.type)}">`,
    ));

it('keeps focus on an unkeyed input when another before it disappears', () =>
    focusUnkeyed(
        (s, h, R) =>
            `${R.T(s.q) ? '' : '<input value="x">'}<input value="${R.q(s.q)}" data-on-input="${h(s.type)}">`,
    ));

it('keeps focus on an unkeyed input when an unkeyed wrapper appears before it', () =>
    focusUnkeyed(
        (s, h, R) =>
            `${R.T(s.q) ? '<div><input></div>' : ''}<div><input value="${R.q(s.q)}" data-on-input="${h(s.type)}"></div>`,
    ));

it('keeps focus on an unkeyed input when an unkeyed input after it moves before it', () =>
    focusUnkeyed((s, h, R) => {
        const q = `<input value="${R.q(s.q)}" data-on-input="${h(s.type)}">`;

        return R.T(s.q) ? '<input value="x">' + q : q + '<input value="x">';
    }));

it('keeps the value of a keyed input whose unkeyed wrapper moves', async () => {
    class C extends LoupeView {
        renderVals() {
            return {
                flip: !!this.state.flip,
                go: () => this.setState({ flip: !this.state.flip }),
            };
        }
    }
    const a = '<div><input id="a"></div>';
    const b = '<div><input id="b"></div>';
    const render = (s, h, R) =>
        `<button data-on-click="${h(s.go)}"></button>` +
        (R.T(s.flip) ? b + a : a + b);
    LoupeView.mount(C, render, {}, ['click'], {});
    const before = document.querySelector('#a');
    before.value = 'typed';
    document.querySelector('button').click();
    await settle();
    expect(document.querySelector('#a')).toBe(before);
    expect(before.value).toBe('typed');
    expect(
        Array.from(document.querySelectorAll('input'), (el) => el.id),
    ).toEqual(['b', 'a']);
});

it('keeps keyed siblings and their values when they reorder', async () => {
    class C extends LoupeView {
        renderVals() {
            return {
                flip: !!this.state.flip,
                go: () => this.setState({ flip: !this.state.flip }),
            };
        }
    }
    const render = (s, h, R) =>
        `<button data-on-click="${h(s.go)}"></button>` +
        (R.T(s.flip)
            ? '<input id="c"><input id="b"><input id="a">'
            : '<input id="a"><input id="b"><input id="c">');
    LoupeView.mount(C, render, {}, ['click'], {});
    const before = Array.from(document.querySelectorAll('input'));
    before.forEach((el) => {
        el.value = el.id + '!';
    });
    document.querySelector('button').click();
    await settle();
    const after = Array.from(document.querySelectorAll('input'));
    expect(after.map((el) => el.id)).toEqual(['c', 'b', 'a']);
    [2, 1, 0].forEach((i, at) => expect(after[at]).toBe(before[i]));
    expect(after.map((el) => el.value)).toEqual(['c!', 'b!', 'a!']);
});

it('syncs a checkbox from the new markup', async () => {
    class C extends LoupeView {
        renderVals() {
            return {
                on: !!this.state.on,
                flip: () => this.setState({ on: !this.state.on }),
            };
        }
    }
    const render = (s, h, R) =>
        `<input type="checkbox"${R.T(s.on) ? ' checked' : ''} data-on-change="${h(s.flip)}">`;
    const view = LoupeView.mount(C, render, {}, ['change'], {});
    const box = document.querySelector('input');
    expect(box.checked).toBe(false);
    view.setState({ on: true });
    await settle();
    expect(document.querySelector('input')).toBe(box);
    expect(box.checked).toBe(true);
});

it('gives a loop handler the right item', async () => {
    const seen = [];
    class C extends LoupeView {
        renderVals() {
            return {
                rows: ['a', 'b', 'c'].map((id) => ({
                    id,
                    pick: () => seen.push(id),
                })),
            };
        }
    }
    const render = (s, h, R) =>
        R.A(s.rows)
            .map(
                (r) =>
                    `<li data-on-click="${h(r.pick)}"><b>${R.e(r.id)}</b></li>`,
            )
            .join('');
    LoupeView.mount(C, render, {}, ['click'], {});
    document.querySelectorAll('b')[1].click();
    document.querySelectorAll('b')[2].click();
    expect(seen).toEqual(['b', 'c']);
});

it('checks only the target for an event that does not bubble', () => {
    const calls = [];
    class C extends LoupeView {
        renderVals() {
            return {
                outer: () => calls.push('outer'),
                inner: () => calls.push('inner'),
            };
        }
    }
    const render = (s, h) =>
        `<div data-on-focus="${h(s.outer)}"><input data-on-focus="${h(s.inner)}"></div><p data-on-focus="${h(s.outer)}"><span></span></p>`;
    LoupeView.mount(C, render, {}, ['focus'], {});
    document
        .querySelector('input')
        .dispatchEvent(new Event('focus', { bubbles: false }));
    document
        .querySelector('span')
        .dispatchEvent(new Event('focus', { bubbles: false }));
    expect(calls).toEqual(['inner']);
});

it('stops walking up after cancelBubble', () => {
    const calls = [];
    class C extends LoupeView {
        renderVals() {
            return {
                outer: () => calls.push('outer'),
                inner: (e) => {
                    calls.push('inner');
                    e.stopPropagation();
                },
            };
        }
    }
    const render = (s, h) =>
        `<div data-on-click="${h(s.outer)}"><i data-on-click="${h(s.inner)}"></i></div>`;
    LoupeView.mount(C, render, {}, ['click'], {});
    document.querySelector('i').click();
    expect(calls).toEqual(['inner']);
});

it('survives a throwing handler', () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    const calls = [];
    class C extends LoupeView {
        renderVals() {
            return {
                bad: () => {
                    throw new Error('boom');
                },
                ok: () => calls.push('ok'),
            };
        }
    }
    const render = (s, h) =>
        `<div data-on-click="${h(s.ok)}"><i data-on-click="${h(s.bad)}"></i></div>`;
    LoupeView.mount(C, render, {}, ['click'], {});
    document.querySelector('i').click();
    expect(error).toHaveBeenCalledTimes(1);
    expect(calls).toEqual(['ok']);
});

it('runs componentDidMount once and redraws on a timer', () => {
    vi.useFakeTimers();
    const didMount = vi.fn();
    class C extends LoupeView {
        constructor(p) {
            super(p);
            this.state = { n: 0 };
        }
        componentDidMount() {
            didMount(document.querySelector('button') !== null);
            setInterval(() => this.setState((s) => ({ n: s.n + 1 })), 1000);
        }
        renderVals() {
            return { n: this.state.n };
        }
    }
    LoupeView.mount(C, (s, h, R) => `<button>${R.e(s.n)}</button>`, {}, [], {});
    expect(didMount).toHaveBeenCalledTimes(1);
    expect(didMount).toHaveBeenCalledWith(true);
    vi.advanceTimersByTime(2000);
    return Promise.resolve().then(() => {
        expect(document.querySelector('button').textContent).toBe('2');
        expect(didMount).toHaveBeenCalledTimes(1);
    });
});

it('keeps unchanged nodes and replaces changed tags', async () => {
    class C extends LoupeView {
        renderVals() {
            return { wide: !!this.state.wide };
        }
    }
    const render = (s, h, R) =>
        `<p class="a">same</p>${R.T(s.wide) ? '<h1>x</h1>' : '<h2>x</h2>'}<em>tail</em>`;
    const view = LoupeView.mount(C, render, {}, [], {});
    const p = document.querySelector('p');
    const em = document.querySelector('em');
    view.setState({ wide: true });
    await settle();
    expect(document.querySelector('p')).toBe(p);
    expect(document.querySelector('em')).toBe(em);
    expect(document.querySelector('h1')).not.toBeNull();
    expect(document.querySelector('h2')).toBeNull();
});

it('adds and removes trailing nodes before the runtime script', async () => {
    class C extends LoupeView {
        renderVals() {
            return { n: this.state.n ?? 1 };
        }
    }
    const render = (s, h, R) =>
        R.A(Array.from({ length: s.n }, (_, i) => i))
            .map((i) => `<i>${i}</i>`)
            .join('');
    const view = LoupeView.mount(C, render, {}, [], {});
    view.setState({ n: 3 });
    await settle();
    expect(document.querySelectorAll('i')).toHaveLength(3);
    expect(document.body.lastElementChild.id).toBe('loupe-runtime');
    view.setState({ n: 1 });
    await settle();
    expect(document.querySelectorAll('i')).toHaveLength(1);
});

it('provides the render helpers', () => {
    const R = LoupeView.helpers({ 'Pricing.dc.html': 'Pricing.html' });
    expect(R.g({ a: { b: [10, 20] } }, 'a.b.1')).toBe(20);
    expect(R.g({}, 'a.b')).toBeUndefined();
    expect(R.e(null)).toBe('');
    expect(R.e(true)).toBe('true');
    expect(R.e('<&>"')).toBe('&lt;&amp;&gt;"');
    expect(R.q('"\'')).toBe('&quot;&#39;');
    expect(R.T(0)).toBe(false);
    expect(R.A('x')).toEqual([]);
    expect(R.lk('Pricing.dc.html#top')).toBe('Pricing.html#top');
    expect(R.lk('Pricing.dc.html?a=1')).toBe('Pricing.html?a=1');
    expect(R.lk('Other.dc.html')).toBe('Other.dc.html');
    expect(R.raw({ __html: '<b>x</b>' })).toBe('<b>x</b>');
    expect(R.raw(undefined)).toBe('');
    expect(R.raw(5)).toBe('5');
});

it('rebases a link from a value at each redraw on a moved page', async () => {
    class C extends LoupeView {
        constructor(p) {
            super(p);
            this.state = { p: 'a.png' };
        }
        renderVals() {
            return {
                p: this.state.p,
                go: () => this.setState({ p: 'img/b.png' }),
            };
        }
    }
    const render = (s, h, R) =>
        `<button data-on-click="${h(s.go)}">x</button><img src="${R.q(R.rb('src', s.p))}">`;
    LoupeView.mount(C, render, {}, ['click'], {}, { from: 'site', to: '.' });
    expect(document.querySelector('img').getAttribute('src')).toBe(
        'site/a.png',
    );
    document.querySelector('button').click();
    await settle();
    expect(document.querySelector('img').getAttribute('src')).toBe(
        'site/img/b.png',
    );
});

it('leaves a link as it is when the page did not move', () => {
    const R = LoupeView.helpers({});
    expect(R.rb('src', 'a.png')).toBe('a.png');
});

it('points the links of markup from a value as the build does', () => {
    const R = LoupeView.helpers(
        { 'Pricing.dc.html': 'Pricing.html' },
        { from: 'site', to: '.' },
    );
    const out = R.raw({
        __html:
            '<a href="Pricing.dc.html#top">p</a><img src="a.png" srcset="a.png 1x">' +
            '<p style="background: url(bg.png)">x</p><style>.a { background: url(img/c.png) }</style>',
    });
    expect(out).toBe(
        '<a href="site/Pricing.html#top">p</a><img src="site/a.png" srcset="site/a.png 1x">' +
            '<p style="background: url(site/bg.png)">x</p><style>.a { background: url(site/img/c.png) }</style>',
    );
    const still = LoupeView.helpers({ 'Pricing.dc.html': 'Pricing.html' });
    expect(still.raw('<a href="Pricing.dc.html">p</a><b>x</b>')).toBe(
        '<a href="Pricing.html">p</a><b>x</b>',
    );
    expect(still.raw('<b>x</b>')).toBe('<b>x</b>');
});
