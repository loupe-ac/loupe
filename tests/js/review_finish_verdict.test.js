/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import ReviewFinishVerdictController from '../../assets/controllers/review_finish_verdict_controller.js';
import {
    rememberFormDraft,
    useFormDraftOwner,
} from '../../assets/lib/form_drafts.js';
import { smoothScrollTo } from '../../assets/lib/smooth_scroll.js';

vi.mock('../../assets/lib/smooth_scroll.js', () => ({
    smoothScrollTo: vi.fn(),
}));

const STREAM = 'text/vnd.turbo-stream.html; charset=UTF-8';
const KEY = 'document:review:doc-1';
const DOCUMENT_URL = '/projects/p/documents/doc-1/review';

let application;

const summary = ({
    unanswered = 0,
    first = '',
    pending = 0,
    hint = `${unanswered} decisions have no answer.`,
}) => ({
    ok: true,
    headers: new Headers({ 'Content-Type': STREAM }),
    text: () =>
        Promise.resolve(
            `<turbo-stream data-decision-page="doc-1/1" action="update" target="decision-summary-count" data-decision-answers="{}" data-decision-unanswered="${unanswered}" data-decision-unanswered-hint="${hint}" data-decision-first-unanswered="${first}" data-decision-pending-threads="${pending}"><template>x</template></turbo-stream>`,
        ),
});

const markup = ({ blocked = false, checked = '' } = {}) => `
<div data-controller="modal">
<dialog>
<form data-controller="review-finish-verdict"
      data-action="review:finish@window->review-finish-verdict#refresh"
      data-form-draft-key-value="${KEY}"
      data-review-finish-verdict-summary-url-value="/summary?versionNumber=1"
      data-review-finish-verdict-document-url-value="${DOCUMENT_URL}">
  <label class="lp-review-verdict-choice${blocked ? ' lp-review-verdict-choice--disabled' : ''}">
    <input type="radio" name="verdict" value="approved" id="v_approved"
           data-review-finish-verdict-target="approve"
           ${blocked ? 'disabled' : ''} ${checked === 'approved' ? 'checked' : ''}>
    <span>
      <span id="v_approved_hint" data-review-finish-verdict-target="hint" ${blocked ? 'hidden' : ''}>Ready.</span>
      <span id="v_approved_reason" data-review-finish-verdict-target="reason" ${blocked ? '' : 'hidden'}>
        <span data-review-finish-verdict-target="reasonText"></span>
        <a href="${DOCUMENT_URL}#" data-review-finish-verdict-target="link"
           data-action="click->review-finish-verdict#go">Go to the first one</a>
      </span>
    </span>
  </label>
  <label class="lp-review-verdict-choice">
    <input type="radio" name="verdict" value="changes-requested"
           data-review-finish-verdict-target="changes"
           ${checked === 'changes-requested' ? 'checked' : ''}>
  </label>
</form>
</dialog>
</div>`;

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

async function mount(options) {
    document.body.innerHTML = markup(options);
    await settle();
}

async function open(answer) {
    vi.stubGlobal(
        'fetch',
        vi.fn(() => Promise.resolve(summary(answer))),
    );
    window.dispatchEvent(new CustomEvent('review:finish'));
    await settle();
}

const approve = () => document.getElementById('v_approved');
const changes = () => document.querySelector('[value="changes-requested"]');
const reason = () => document.getElementById('v_approved_reason');
const hint = () => document.getElementById('v_approved_hint');

beforeEach(() => {
    useFormDraftOwner(crypto.randomUUID());
    smoothScrollTo.mockReset();
    application = Application.start();
    application.register(
        'review-finish-verdict',
        ReviewFinishVerdictController,
    );
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
    vi.unstubAllGlobals();
    useFormDraftOwner('');
});

it('asks the summary of the version the form reviews when the dialog opens', async () => {
    await mount();
    await open({});
    expect(fetch).toHaveBeenCalledWith(
        '/summary?versionNumber=1',
        expect.objectContaining({ credentials: 'same-origin' }),
    );
});

it('preselects Approve when every decision has an answer and no thread is pending', async () => {
    await mount();
    await open({ unanswered: 0, pending: 0 });
    expect(approve().disabled).toBe(false);
    expect(approve().checked).toBe(true);
    expect(changes().checked).toBe(false);
    expect(reason().hidden).toBe(true);
});

it('disables Approve, says why and preselects Request changes while decisions have no answer', async () => {
    await mount();
    await open({ unanswered: 2, first: 'decision_block_a' });
    expect(approve().disabled).toBe(true);
    expect(approve().checked).toBe(false);
    expect(changes().checked).toBe(true);
    expect(approve().closest('label').classList).toContain(
        'lp-review-verdict-choice--disabled',
    );
    expect(reason().hidden).toBe(false);
    expect(hint().hidden).toBe(true);
    expect(approve().getAttribute('aria-describedby')).toBe(
        'v_approved_reason',
    );
    expect(reason().textContent).toContain('2 decisions have no answer.');
    expect(reason().querySelector('a').getAttribute('href')).toBe(
        `${DOCUMENT_URL}#decision_block_a`,
    );
});

it('preselects Request changes while a thread is pending, even with every decision answered', async () => {
    await mount();
    await open({ unanswered: 0, pending: 1 });
    expect(approve().disabled).toBe(false);
    expect(changes().checked).toBe(true);
    expect(approve().checked).toBe(false);
});

it('enables Approve again when a later open reads no unanswered decision', async () => {
    await mount();
    await open({ unanswered: 1, first: 'decision_block_a' });
    expect(approve().disabled).toBe(true);

    await open({ unanswered: 0 });
    expect(approve().disabled).toBe(false);
    expect(approve().closest('label').classList).not.toContain(
        'lp-review-verdict-choice--disabled',
    );
    expect(reason().hidden).toBe(true);
    expect(hint().hidden).toBe(false);
    expect(approve().getAttribute('aria-describedby')).toBe('v_approved_hint');
    expect(approve().checked).toBe(true);
});

it('keeps a saved Request changes pick when nothing blocks Approve', async () => {
    await mount();
    rememberFormDraft(KEY, { options: ['changes-requested'] });
    await open({ unanswered: 0, pending: 0 });
    expect(changes().checked).toBe(true);
    expect(approve().checked).toBe(false);
});

it('keeps a saved Approve pick when nothing blocks it, even with a thread pending', async () => {
    await mount();
    rememberFormDraft(KEY, { options: ['approved'] });
    await open({ unanswered: 0, pending: 3 });
    expect(approve().checked).toBe(true);
});

it('drops a saved Approve pick while Approve is disabled', async () => {
    await mount();
    rememberFormDraft(KEY, { options: ['approved'] });
    await open({ unanswered: 2, first: 'decision_block_a' });
    expect(approve().checked).toBe(false);
    expect(changes().checked).toBe(true);
});

it('does not take its own preselection for a saved pick on the next open', async () => {
    await mount();
    await open({ unanswered: 1, first: 'decision_block_a' });
    expect(changes().checked).toBe(true);

    await open({ unanswered: 0, pending: 0 });
    expect(approve().checked).toBe(true);
});

it('leaves the dialog as it is when the summary cannot be read', async () => {
    await mount();
    vi.stubGlobal(
        'fetch',
        vi.fn(() => Promise.reject(new Error('offline'))),
    );
    window.dispatchEvent(new CustomEvent('review:finish'));
    await settle();
    expect(fetch).toHaveBeenCalled();
    expect(approve().disabled).toBe(false);
    expect(approve().checked).toBe(false);
    expect(changes().checked).toBe(false);
});

it('ignores an answer that is not a stream', async () => {
    await mount();
    vi.stubGlobal(
        'fetch',
        vi.fn(() =>
            Promise.resolve({
                ok: true,
                headers: new Headers({ 'Content-Type': 'text/html' }),
                text: () => Promise.resolve('<p>login</p>'),
            }),
        ),
    );
    window.dispatchEvent(new CustomEvent('review:finish'));
    await settle();
    expect(fetch).toHaveBeenCalled();
    expect(approve().disabled).toBe(false);
    expect(changes().checked).toBe(false);
});

it('applies only the newest read when two reads overlap', async () => {
    await mount();
    let releaseFirst;
    const first = new Promise((resolve) => {
        releaseFirst = () => resolve(summary({ unanswered: 3, first: 'x' }));
    });
    vi.stubGlobal(
        'fetch',
        vi
            .fn()
            .mockReturnValueOnce(first)
            .mockReturnValueOnce(Promise.resolve(summary({ unanswered: 0 }))),
    );
    window.dispatchEvent(new CustomEvent('review:finish'));
    window.dispatchEvent(new CustomEvent('review:finish'));
    await settle();
    releaseFirst();
    await settle();
    expect(fetch).toHaveBeenCalledTimes(2);
    expect(approve().disabled).toBe(false);
    expect(approve().checked).toBe(true);
});

it('moves a server-rendered Approve that is disabled but checked to Request changes', async () => {
    await mount({ blocked: true, checked: 'approved' });
    expect(approve().checked).toBe(false);
    expect(changes().checked).toBe(true);
});

it('keeps a Request changes pick that the server rendered next to a disabled Approve', async () => {
    await mount({ blocked: true, checked: 'changes-requested' });
    expect(changes().checked).toBe(true);
    expect(approve().checked).toBe(false);
});

it('scrolls to the first unanswered block and closes the dialog when the link is used', async () => {
    await mount();
    const close = vi.fn();
    vi.spyOn(
        application,
        'getControllerForElementAndIdentifier',
    ).mockReturnValue({
        close,
    });
    const target = document.createElement('div');
    target.id = 'decision_block_a';
    document.body.append(target);
    await open({ unanswered: 1, first: 'decision_block_a' });

    const event = new MouseEvent('click', { bubbles: true, cancelable: true });
    reason().querySelector('a').dispatchEvent(event);

    expect(event.defaultPrevented).toBe(true);
    expect(close).toHaveBeenCalled();
    expect(smoothScrollTo).toHaveBeenCalledWith(target, { align: 'start' });
});

it('lets the link navigate when the block is not on this page', async () => {
    await mount();
    await open({ unanswered: 1, first: 'decision_block_a' });

    const event = new MouseEvent('click', { bubbles: true, cancelable: true });
    reason().querySelector('a').dispatchEvent(event);

    expect(event.defaultPrevented).toBe(false);
    expect(smoothScrollTo).not.toHaveBeenCalled();
});
