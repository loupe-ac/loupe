/** @vitest-environment jsdom */
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { FADE_MILLISECONDS } from '../../assets/lib/live_fade.js';

function stream(action, target) {
    const element = document.createElement('div');
    element.action = action;
    element.target = target;

    return element;
}

function dispatchStream(newStream, render = vi.fn()) {
    const event = new CustomEvent('turbo:before-stream-render', {
        bubbles: true,
        cancelable: true,
        detail: { newStream, render },
    });
    document.dispatchEvent(event);

    return { event, render };
}

function rows(ids) {
    return ids.map((id) => `<li data-live-row="${id}">${id}</li>`).join('');
}

function morph(current, freshHtml) {
    const fresh = document.createElement('turbo-frame');
    fresh.innerHTML = freshHtml;
    current.dispatchEvent(
        new CustomEvent('turbo:before-frame-morph', {
            bubbles: true,
            detail: { currentElement: current, newElement: fresh },
        }),
    );

    return fresh;
}

function reduceMotion(reduce) {
    vi.stubGlobal('matchMedia', () => ({ matches: reduce }));
}

beforeEach(() => {
    vi.useFakeTimers();
    reduceMotion(false);
    document.body.innerHTML =
        '<ul id="list"><li id="row-1" data-live-fade>One</li><li id="row-2">Two</li></ul>';
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    document.body.replaceChildren();
});

it('fades out a removed target with data-live-fade before it removes it', async () => {
    const { event, render } = dispatchStream(stream('remove', 'row-1'));
    const target = document.getElementById('row-1');
    const rendered = stream('remove', 'row-1');
    const pending = event.detail.render(rendered);
    expect(target.classList.contains('lp-live-row--leaving')).toBe(true);
    expect(render).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(FADE_MILLISECONDS);
    await pending;
    expect(render).toHaveBeenCalledWith(rendered);
});

it('leaves a removed target without data-live-fade alone', () => {
    const { event, render } = dispatchStream(stream('remove', 'row-2'));
    expect(event.detail.render).toBe(render);
});

it('leaves other actions alone', () => {
    const { event, render } = dispatchStream(stream('replace', 'row-1'));
    expect(event.detail.render).toBe(render);
});

it('removes at once under reduced motion', async () => {
    reduceMotion(true);
    const { event, render } = dispatchStream(stream('remove', 'row-1'));
    const rendered = stream('remove', 'row-1');
    await event.detail.render(rendered);
    expect(
        document
            .getElementById('row-1')
            .classList.contains('lp-live-row--leaving'),
    ).toBe(false);
    expect(render).toHaveBeenCalledWith(rendered);
});

it('keeps a leaving row at its place in the new frame, and drops it after the fade', () => {
    const current = document.createElement('turbo-frame');
    current.innerHTML = rows(['a', 'b', 'c']);
    document.body.append(current);
    const fresh = morph(current, rows(['a', 'c']));
    const items = [...fresh.children];
    expect(items.map((item) => item.textContent)).toEqual(['a', 'b', 'c']);
    expect(items[1].classList.contains('lp-live-row--leaving')).toBe(true);
    expect(items[1].hasAttribute('data-live-row')).toBe(false);
    vi.advanceTimersByTime(FADE_MILLISECONDS);
    expect([...fresh.children].map((item) => item.textContent)).toEqual([
        'a',
        'c',
    ]);
});

it('places a leaving first row before the next row that stays', () => {
    const current = document.createElement('turbo-frame');
    current.innerHTML = rows(['a', 'b']);
    document.body.append(current);
    const fresh = morph(current, rows(['b']));
    expect([...fresh.children].map((item) => item.textContent)).toEqual([
        'a',
        'b',
    ]);
});

it('fades every row when the new frame holds none, and drops them after the fade', () => {
    const current = document.createElement('turbo-frame');
    current.innerHTML = rows(['a', 'b']);
    document.body.append(current);
    const fresh = morph(current, '<p>Nothing here</p>');
    const leaving = fresh.querySelectorAll('.lp-live-row--leaving');
    expect([...leaving].map((item) => item.textContent)).toEqual(['a', 'b']);
    vi.advanceTimersByTime(FADE_MILLISECONDS);
    expect(fresh.querySelectorAll('.lp-live-row--leaving')).toHaveLength(0);
    expect(fresh.textContent).toBe('Nothing here');
});

it('puts the leaving rows of an emptied list into the twin list of the new frame', () => {
    const current = document.createElement('turbo-frame');
    current.innerHTML = `<ul id="rows">${rows(['a'])}</ul>`;
    document.body.append(current);
    const fresh = morph(current, '<ul id="rows"></ul>');
    expect(fresh.querySelector('#rows').children).toHaveLength(1);
});

it('marks a new row as entering', () => {
    const current = document.createElement('turbo-frame');
    current.innerHTML = rows(['a']);
    document.body.append(current);
    const fresh = morph(current, rows(['a', 'b']));
    const [kept, added] = fresh.children;
    expect(kept.classList.contains('lp-live-row--entering')).toBe(false);
    expect(added.classList.contains('lp-live-row--entering')).toBe(true);
});

it('changes nothing under reduced motion', () => {
    reduceMotion(true);
    const current = document.createElement('turbo-frame');
    current.innerHTML = rows(['a', 'b']);
    document.body.append(current);
    const fresh = morph(current, rows(['b', 'c']));
    expect([...fresh.children].map((item) => item.textContent)).toEqual([
        'b',
        'c',
    ]);
    expect(fresh.querySelector('.lp-live-row--entering')).toBeNull();
});
