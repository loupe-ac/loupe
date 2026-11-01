import { Controller } from '@hotwired/stimulus';
import { discardFormDraft } from '../lib/form_drafts.js';

/**
 * The unsent reply of an item that closed while the owner typed. The live
 * view saves the values of the reply card and grafts this into the thread.
 */
export default class extends Controller {
    static targets = ['choiceList', 'noteList', 'icon'];
    static values = { choices: Array, notes: Array, key: String };

    connect() {
        this.choiceListTarget.replaceChildren(
            ...this.choicesValue.map((label) => {
                const choice = document.createElement('li');
                if (this.hasIconTarget) {
                    choice.append(this.iconTarget.content.cloneNode(true));
                }
                choice.append(label);

                return choice;
            }),
        );
        this.choiceListTarget.hidden = this.choicesValue.length === 0;
        this.noteListTarget.replaceChildren(
            ...this.notesValue.map((text) => {
                const note = document.createElement('p');
                note.className = 'lp-inbox-item__answer-text';
                note.textContent = text;

                return note;
            }),
        );
    }

    discard() {
        const hadFocus = this.element.contains(document.activeElement);
        const heading =
            this.element
                .closest('[data-inbox-item]')
                ?.querySelector('.lp-inbox-item__title') ??
            this.element
                .closest('.lp-inbox-ask')
                ?.querySelector('.lp-inbox-ask__title');
        if (this.hasKeyValue && this.keyValue !== '') {
            discardFormDraft(this.keyValue);
        }
        this.element.remove();
        if (hadFocus && heading) {
            heading.setAttribute('tabindex', '-1');
            heading.focus();
        }
    }
}
