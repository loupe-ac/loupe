import ModalController from './modal_controller.js';

const SLIDE_UP = [
    { transform: 'translateY(100%)' },
    { transform: 'translateY(0)' },
];
const SLIDE_DOWN = [
    { transform: 'translateY(0)' },
    { transform: 'translateY(100%)' },
];

// A sheet slides up from the bottom at every width. A drill-down shows one
// `data-sheet-view` at a time, and the back button walks the trail.
export default class extends ModalController {
    static targets = ['back'];

    connect() {
        super.connect();
        this.trail = [];
        this.#render();
    }

    open(event) {
        this.trail = [];
        this.#render();
        super.open(event);
    }

    motion(phase) {
        return {
            keyframes: phase === 'open' ? SLIDE_UP : SLIDE_DOWN,
            timing:
                phase === 'open'
                    ? { duration: 220, easing: 'ease-out' }
                    : { duration: 180, easing: 'ease-in' },
        };
    }

    show(event) {
        this.showView(event.params.view);
    }

    showView(name) {
        if (!this.#views().some((view) => view.dataset.sheetView === name)) {
            return;
        }
        this.trail.push(name);
        this.#render();
    }

    back() {
        this.trail.pop();
        this.#render();
    }

    #views() {
        if (!this.hasDialogTarget) {
            return [];
        }

        return [...this.dialogTarget.querySelectorAll('[data-sheet-view]')];
    }

    #render() {
        const views = this.#views();
        const current = this.trail.at(-1) ?? views[0]?.dataset.sheetView;
        for (const view of views) {
            view.hidden = view.dataset.sheetView !== current;
        }
        if (this.hasBackTarget) {
            this.backTarget.hidden = this.trail.length === 0;
        }
    }
}
