import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';
import { afterEach, expect, it } from 'vitest';

const dir = 'cli/internal/claudedesign/';
const react = readFileSync(dir + 'react/react.production.min.js', 'utf8');
const reactDom = readFileSync(
    dir + 'react/react-dom.production.min.js',
    'utf8',
);
const mount = readFileSync(dir + 'mount.js', 'utf8');

let dom;
const settle = () => new Promise((resolve) => setTimeout(resolve, 30));

const props = (value) => JSON.stringify(value).replaceAll('"', '&quot;');

const run = async (body, system) => {
    dom = new JSDOM('<!doctype html><body>' + body + '</body>', {
        runScripts: 'outside-only',
    });
    const w = dom.window;
    w.eval(react);
    w.eval(reactDom);
    w.eval(system);
    w.eval(mount);
    await settle();

    return w.document;
};

const system = `
window.NS_abcdef = {
  Shell: (p) => React.createElement('main', { className: 'shell ' + (p.tone || ''), style: p.style },
    React.createElement('nav', null, (p.crumbs || []).join(' / ')), p.children),
  Button: (p) => React.createElement('button', { disabled: p.disabled }, p.children),
  Slot: (p) => React.createElement('aside', null, p.actions),
};
`;

afterEach(() => dom.window.close());

it('draws a component with its props and its children', async () => {
    const doc = await run(
        `<loupe-mount data-component="NS_abcdef.Shell" style="display: contents" data-props="${props(
            {
                crumbs: ['Acme', 'Work'],
                tone: 'dark',
                style: 'width: 100%; --gap: 4px',
            },
        )}"><p class="x" style="margin-top: 4px">Hi <b>there</b></p></loupe-mount>`,
        system,
    );
    const main = doc.querySelector('main');
    expect(main.className).toBe('shell dark');
    expect(main.style.width).toBe('100%');
    expect(main.style.getPropertyValue('--gap')).toBe('4px');
    expect(doc.querySelector('nav').textContent).toBe('Acme / Work');
    const p = doc.querySelector('p.x');
    expect(p.style.marginTop).toBe('4px');
    expect(p.innerHTML).toBe('Hi <b>there</b>');
});

it('draws nested components and boolean attributes', async () => {
    const doc = await run(
        `<loupe-mount data-component="NS_abcdef.Shell"><div><loupe-mount data-component="NS_abcdef.Button" data-props="${props(
            { disabled: true },
        )}">Go</loupe-mount></div></loupe-mount>`,
        system,
    );
    const button = doc.querySelector('main div button');
    expect(button.textContent).toBe('Go');
    expect(button.disabled).toBe(true);
    expect(doc.querySelector('loupe-mount loupe-mount')).toBeNull();
});

it('turns an element description in a prop into an element', async () => {
    const actions = {
        $el: {
            type: 'NS_abcdef.Button',
            props: { disabled: false },
            children: ['Hide'],
        },
    };
    const doc = await run(
        `<loupe-mount data-component="NS_abcdef.Slot" data-props="${props({ actions })}"></loupe-mount>`,
        system,
    );
    expect(doc.querySelector('aside button').textContent).toBe('Hide');
});

it('keeps the content when a component is missing', async () => {
    const doc = await run(
        '<loupe-mount data-component="NS_abcdef.Nope"><p>kept</p></loupe-mount>',
        system,
    );
    expect(doc.querySelector('p').textContent).toBe('kept');
});

it('maps for and class on plain elements, and drops table whitespace', async () => {
    const doc = await run(
        `<loupe-mount data-component="NS_abcdef.Shell"><label for="a" class="l">L</label><input id="a" value="v" disabled=""><table> <tbody> <tr> <td colspan="2">c</td> </tr> </tbody> </table></loupe-mount>`,
        system,
    );
    expect(doc.querySelector('label').htmlFor).toBe('a');
    expect(doc.querySelector('label').className).toBe('l');
    expect(doc.querySelector('input').value).toBe('v');
    expect(doc.querySelector('input').disabled).toBe(true);
    expect(doc.querySelector('td').colSpan).toBe(2);
});

it('does nothing when React is missing', async () => {
    dom = new JSDOM(
        '<body><loupe-mount data-component="X.Y"><p>kept</p></loupe-mount></body>',
        { runScripts: 'outside-only' },
    );
    dom.window.eval(mount);
    expect(dom.window.document.querySelector('p').textContent).toBe('kept');
});
