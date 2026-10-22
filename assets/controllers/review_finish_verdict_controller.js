import { Controller } from '@hotwired/stimulus';
import { formDraft } from '../lib/form_drafts.js';
import { smoothScrollTo } from '../lib/smooth_scroll.js';

const STREAM_TYPE = 'text/vnd.turbo-stream.html';
const APPROVED = 'approved';
const CHANGES_REQUESTED = 'changes-requested';
const DISABLED_CLASS = 'lp-review-verdict-choice--disabled';

/**
 * Sits on the Finish review form. Each time the dialog opens it reads the
 * decision summary, blocks Approve while a decision has no answer, and
 * preselects the verdict the document asks for.
 *
 * The server refuses the same approval, so a failed read leaves the dialog as
 * it stands and costs a refused submit at worst.
 */
export default class extends Controller {
    static targets = [
        'approve',
        'changes',
        'hint',
        'reason',
        'reasonText',
        'link',
    ];
    static values = { summaryUrl: String, documentUrl: String };

    connect() {
        this.reads = 0;
        if (!this.hasApproveTarget || !this.approveTarget.disabled) return;

        // The server rendered Approve blocked. A restored pick can still hold
        // it checked, and a disabled radio posts nothing.
        this.approveTarget.checked = false;
        if (this.hasChangesTarget && !this.#picked()) {
            this.changesTarget.checked = true;
        }
    }

    async refresh() {
        if (!this.hasApproveTarget || !this.summaryUrlValue) return;

        const read = ++this.reads;
        const summary = await this.#read();
        if (summary === null || read !== this.reads) return;
        if (!this.element.isConnected) return;

        this.#apply(summary);
    }

    go(event) {
        const target = document.getElementById(this.linkTarget.hash.slice(1));
        if (!target) return;

        event.preventDefault();
        this.application
            .getControllerForElementAndIdentifier(
                this.element.closest('[data-controller~="modal"]'),
                'modal',
            )
            ?.close();
        smoothScrollTo(target, { align: 'start' });
    }

    #picked() {
        return this.hasChangesTarget && this.changesTarget.checked;
    }

    async #read() {
        try {
            // A read of the summary, with no form to submit.
            // eslint-disable-next-line no-restricted-syntax
            const response = await fetch(this.summaryUrlValue, {
                headers: { Accept: STREAM_TYPE },
                credentials: 'same-origin',
            });
            const type = response.headers.get('Content-Type') ?? '';
            if (!response.ok || !type.startsWith(STREAM_TYPE)) return null;

            const template = document.createElement('template');
            template.innerHTML = await response.text();
            const stream = template.content.querySelector(
                'turbo-stream[data-decision-unanswered]',
            );
            if (!stream) return null;

            const unanswered = Number.parseInt(
                stream.dataset.decisionUnanswered,
                10,
            );
            const pending = Number.parseInt(
                stream.dataset.decisionPendingThreads,
                10,
            );
            if (Number.isNaN(unanswered) || Number.isNaN(pending)) return null;

            return {
                unanswered,
                pending,
                hint: stream.dataset.decisionUnansweredHint ?? '',
                first: stream.dataset.decisionFirstUnanswered ?? '',
            };
        } catch {
            return null;
        }
    }

    #apply({ unanswered, pending, hint, first }) {
        const blocked = unanswered > 0;
        this.approveTarget.disabled = blocked;
        this.approveTarget
            .closest('label')
            ?.classList.toggle(DISABLED_CLASS, blocked);
        if (this.hasHintTarget) this.hintTarget.hidden = blocked;
        if (this.hasReasonTarget) {
            this.reasonTarget.hidden = !blocked;
            this.approveTarget.setAttribute(
                'aria-describedby',
                blocked ? this.reasonTarget.id : this.hintTarget.id,
            );
        }
        if (blocked) {
            this.reasonTextTarget.textContent = hint;
            this.linkTarget.href = `${this.documentUrlValue}#${first}`;
        }

        const saved = formDraft(this.element.dataset.formDraftKeyValue)
            ?.options?.[0];
        const wanted = blocked || pending > 0 ? CHANGES_REQUESTED : APPROVED;
        const keepSaved =
            saved === CHANGES_REQUESTED || (saved === APPROVED && !blocked);
        const verdict = keepSaved ? saved : wanted;
        this.approveTarget.checked = verdict === APPROVED;
        this.changesTarget.checked = verdict === CHANGES_REQUESTED;
    }
}
