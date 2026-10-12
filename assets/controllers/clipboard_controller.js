import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['button', 'label'];
    static values = {
        text: String,
        copiedLabel: { type: String, default: 'Copied!' },
    };

    connect() {
        // A Turbo morph can reconnect while "Copied!" is still showing, so that
        // label is never captured as the one to restore.
        const label = this.labelElement ? this.labelElement.textContent : '';
        if (label !== this.copiedLabelValue) {
            this.originalLabel = label;
        }
        this.restoreTimer = null;
    }

    // An icon button keeps its icon and puts the label in a label target.
    get labelElement() {
        if (this.hasLabelTarget) {
            return this.labelTarget;
        }
        return this.hasButtonTarget ? this.buttonTarget : null;
    }

    disconnect() {
        clearTimeout(this.restoreTimer);
    }

    copy() {
        navigator.clipboard.writeText(this.textValue).then(() => {
            const button = this.buttonTarget;
            const label = this.labelElement;
            label.textContent = this.copiedLabelValue;
            button.dataset.copied = '';
            // Restoring the label captured at connect, not the one on screen: a second
            // click lands while "Copied!" is showing and would otherwise make it stick.
            clearTimeout(this.restoreTimer);
            this.restoreTimer = setTimeout(() => {
                label.textContent = this.originalLabel;
                delete button.dataset.copied;
            }, 2000);
        });
    }
}
