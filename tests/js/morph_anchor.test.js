/** @vitest-environment jsdom */
import { beforeAll, beforeEach, expect, it, vi } from 'vitest';

beforeAll(async () => {
    await import('../../assets/lib/morph_anchor.js');
});

let section;

function place(top, bottom) {
    section.getBoundingClientRect = () => ({ top, bottom });
}

beforeEach(() => {
    document.body.innerHTML = `<section id="git-identity" data-morph-anchor>
        <form id="identity-form"><button>Save</button></form>
    </section><form id="other-form"></form>`;
    section = document.getElementById('git-identity');
    section.scrollIntoView = vi.fn();
    vi.stubGlobal('innerHeight', 800);
    document.dispatchEvent(new CustomEvent('turbo:load'));
});

function answer(type = 'text/html') {
    document.dispatchEvent(
        new CustomEvent('turbo:submit-end', {
            detail: {
                fetchResponse: {
                    header: () => type,
                    location: new URL('http://localhost/settings'),
                    responseHTML: Promise.resolve(ANSWER),
                },
                formSubmission: SUBMISSION,
            },
        }),
    );
}

const ANSWER = '<html><body><p>answer</p></body></html>';

async function render() {
    await Promise.resolve();
    await Promise.resolve();
    document.dispatchEvent(
        new CustomEvent('turbo:before-render', {
            detail: {
                newBody: new DOMParser().parseFromString(ANSWER, 'text/html')
                    .body,
            },
        }),
    );
}

const SUBMISSION = { id: 'form' };

function startVisit(visit, formSubmission) {
    window.Turbo = { session: { navigator: { formSubmission } } };
    document.dispatchEvent(
        new CustomEvent('turbo:before-visit', {
            detail: { url: visit.location.href },
        }),
    );
    window.Turbo = { session: { navigator: { currentVisit: visit } } };
    document.dispatchEvent(new CustomEvent('turbo:visit'));
}

function submit(id) {
    document
        .getElementById(id)
        .dispatchEvent(
            new CustomEvent('turbo:submit-start', { bubbles: true }),
        );
}

it('scrolls the section of the submitted form into view when it is out of view', async () => {
    place(900, 1100);
    submit('identity-form');
    answer();
    await render();
    document.dispatchEvent(new CustomEvent('turbo:morph'));
    expect(section.scrollIntoView).toHaveBeenCalledWith({ block: 'nearest' });
});

it('leaves a section that is already in view where it is', () => {
    place(100, 300);
    submit('identity-form');
    answer();
    document.dispatchEvent(new CustomEvent('turbo:morph'));
    expect(section.scrollIntoView).not.toHaveBeenCalled();
});

it('finds the section again when the morph replaced its element', async () => {
    place(900, 1100);
    submit('identity-form');
    answer();
    await render();
    const replacement = section.cloneNode(true);
    replacement.scrollIntoView = vi.fn();
    replacement.getBoundingClientRect = () => ({ top: 900, bottom: 1100 });
    section.replaceWith(replacement);
    document.dispatchEvent(new CustomEvent('turbo:morph'));
    expect(replacement.scrollIntoView).toHaveBeenCalled();
});

it('does nothing for a form outside every marked section', () => {
    place(900, 1100);
    submit('other-form');
    answer();
    document.dispatchEvent(new CustomEvent('turbo:morph'));
    expect(section.scrollIntoView).not.toHaveBeenCalled();
});

it('forgets the section after a full visit', () => {
    place(900, 1100);
    submit('identity-form');
    document.dispatchEvent(new CustomEvent('turbo:load'));
    document.dispatchEvent(new CustomEvent('turbo:morph'));
    expect(section.scrollIntoView).not.toHaveBeenCalled();
});

it('uses a stored section once', async () => {
    place(900, 1100);
    submit('identity-form');
    answer();
    await render();
    document.dispatchEvent(new CustomEvent('turbo:morph'));
    document.dispatchEvent(new CustomEvent('turbo:morph'));
    expect(section.scrollIntoView).toHaveBeenCalledOnce();
});

it('forgets the section when the answer is a stream', () => {
    place(900, 1100);
    submit('identity-form');
    answer('text/vnd.turbo-stream.html');
    document.dispatchEvent(new CustomEvent('turbo:morph'));
    expect(section.scrollIntoView).not.toHaveBeenCalled();
});

it('forgets the section when the request fails', () => {
    place(900, 1100);
    submit('identity-form');
    document.dispatchEvent(new CustomEvent('turbo:submit-end', { detail: {} }));
    document.dispatchEvent(new CustomEvent('turbo:morph'));
    expect(section.scrollIntoView).not.toHaveBeenCalled();
});

it('keeps the section through a morph that arrives before the answer', async () => {
    place(900, 1100);
    submit('identity-form');
    document.dispatchEvent(new CustomEvent('turbo:morph'));
    expect(section.scrollIntoView).not.toHaveBeenCalled();
    answer();
    await render();
    document.dispatchEvent(new CustomEvent('turbo:morph'));
    expect(section.scrollIntoView).toHaveBeenCalledOnce();
});

it('keeps the section through an unrelated morph after the request ended', () => {
    place(900, 1100);
    submit('identity-form');
    answer();
    const unrelated = [
        { response: undefined, location: new URL('http://localhost/settings') },
        {
            response: { responseHTML: '<html></html>' },
            location: new URL('http://localhost/frame-fallback'),
        },
    ];
    for (const visit of unrelated) {
        startVisit(visit, SUBMISSION);
        document.dispatchEvent(new CustomEvent('turbo:morph'));
        expect(section.scrollIntoView).not.toHaveBeenCalled();
    }
    const own = {
        response: { responseHTML: '<html></html>' },
        location: new URL('http://localhost/settings'),
    };
    startVisit(own, SUBMISSION);
    document.dispatchEvent(new CustomEvent('turbo:morph'));
    expect(section.scrollIntoView).toHaveBeenCalledOnce();
    delete window.Turbo;
});
