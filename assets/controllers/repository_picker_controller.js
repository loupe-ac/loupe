import { Controller } from '@hotwired/stimulus';

/**
 * The repository picker: filters the lists by name, and keeps the label and the
 * state of the submit button in step with the ticked boxes.
 */
export default class extends Controller {
    static targets = ['search', 'repository', 'account', 'submit', 'empty'];
    static values = { none: String, one: String, many: String };

    connect() {
        this.update();
    }

    filter() {
        const term = this.searchTarget.value.trim().toLowerCase();
        for (const repository of this.repositoryTargets) {
            repository.hidden =
                term !== '' && !repository.dataset.name.includes(term);
        }

        for (const account of this.accountTargets) {
            account.hidden =
                account.querySelectorAll(
                    '[data-repository-picker-target="repository"]:not([hidden])',
                ).length === 0;
        }

        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = this.accountTargets.some(
                (account) => !account.hidden,
            );
        }
    }

    update() {
        if (!this.hasSubmitTarget) {
            return;
        }

        const count = this.#ticked().length;
        this.submitTarget.disabled = count === 0;
        this.submitTarget.textContent =
            count === 0
                ? this.noneValue
                : count === 1
                  ? this.oneValue
                  : this.manyValue.replace('%count%', String(count));
    }

    #ticked() {
        return this.repositoryTargets
            .map((repository) =>
                repository.querySelector(
                    'input[type="checkbox"]:not(:disabled)',
                ),
            )
            .filter((box) => box !== null && box.checked);
    }
}
