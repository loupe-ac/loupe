import { Controller } from '@hotwired/stimulus';

/**
 * The confirm step of a run-command action. A branch pick swaps the folder in the command label,
 * and the Run button stays off until a branch with a worktree is picked. A typed value fills its
 * placeholder in the command line. The server checks every value again.
 */
export default class extends Controller {
    static targets = ['branch', 'param', 'command', 'run', 'noWorktree'];
    static values = {
        template: Array,
        label: String,
        labelIn: String,
    };

    refresh() {
        const folder = this.hasBranchTarget
            ? (this.branchTarget.selectedOptions[0]?.dataset.folder ?? '')
            : '';

        if (this.hasRunTarget) {
            this.runTarget.disabled = folder === '';
        }
        if (this.hasNoWorktreeTarget) {
            this.noWorktreeTarget.hidden = folder !== '';
        }
        if (this.hasCommandTarget) {
            const label = this.commandTarget.querySelector(
                '.lp-code-dark__label',
            );
            if (label) {
                label.textContent =
                    folder === ''
                        ? this.labelValue
                        : this.labelInValue.replace('%folder%', folder);
            }
            const code = this.commandTarget.querySelector('code');
            if (code) {
                code.textContent = this.#argv().join(' ');
            }
        }
    }

    #argv() {
        const values = new Map(
            this.paramTargets.map((input) => [input.dataset.name, input.value]),
        );

        return this.templateValue.map((element) =>
            element.replace(/\{([^{}]+)\}/g, (match, name) =>
                values.has(name) ? values.get(name) : match,
            ),
        );
    }
}
