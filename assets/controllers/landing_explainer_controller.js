import { Controller } from '@hotwired/stimulus';

/**
 * The Loupe Explainer of the home page. One scene per workflow step sits in a
 * stack, and a timer steps through them in a loop. The loop pauses while the
 * pointer is over the frame or a control has keyboard focus, and it never runs
 * under `prefers-reduced-motion`. The buttons step by hand in every case.
 */
export default class extends Controller {
    static targets = ['scene', 'dot', 'toggle', 'toggleLabel'];
    static values = {
        index: { type: Number, default: 0 },
        playing: { type: Boolean, default: true },
        interval: { type: Number, default: 8000 },
        playLabel: String,
        pauseLabel: String,
    };

    initialize() {
        this.hovering = false;
        this.focused = false;
        this.reducedMotion = false;
        this.timer = null;
    }

    connect() {
        this.motionQuery =
            window.matchMedia?.('(prefers-reduced-motion: reduce)') ?? null;
        this.reducedMotion = Boolean(this.motionQuery?.matches);
        this.motionListener = (event) => {
            this.reducedMotion = event.matches;
            this.sync();
        };
        this.motionQuery?.addEventListener('change', this.motionListener);
        this.sync();
    }

    disconnect() {
        this.stopTimer();
        this.motionQuery?.removeEventListener('change', this.motionListener);
    }

    pick(event) {
        this.show(event.params.index);
    }

    previous() {
        this.show(this.indexValue - 1);
    }

    next() {
        this.show(this.indexValue + 1);
    }

    toggle() {
        this.playingValue = !this.playingValue;
    }

    hold() {
        this.hovering = true;
        this.schedule();
    }

    release() {
        this.hovering = false;
        this.schedule();
    }

    focusIn(event) {
        try {
            this.focused = event.target.matches(':focus-visible');
        } catch {
            this.focused = true;
        }
        this.schedule();
    }

    focusOut() {
        this.focused = false;
        this.schedule();
    }

    show(index) {
        const count = this.sceneTargets.length;
        this.indexValue = (index + count) % count;
        this.schedule();
    }

    indexValueChanged() {
        const active = this.indexValue;

        this.sceneTargets.forEach((scene, position) => {
            scene.setAttribute('aria-hidden', String(position !== active));
            scene.inert = position !== active;
        });
        this.dotTargets.forEach((dot, position) => {
            if (position === active) {
                dot.setAttribute('aria-current', 'step');
            } else {
                dot.removeAttribute('aria-current');
            }
        });
    }

    playingValueChanged() {
        this.sync();
    }

    sync() {
        this.toggleTargets.forEach((toggle) => {
            toggle.hidden = this.reducedMotion;
            toggle.dataset.playing = String(this.playingValue);
            toggle.setAttribute(
                'aria-label',
                this.playingValue ? this.pauseLabelValue : this.playLabelValue,
            );
        });
        this.toggleLabelTargets.forEach((label) => {
            label.textContent = this.playingValue
                ? this.pauseLabelValue
                : this.playLabelValue;
        });
        this.schedule();
    }

    schedule() {
        this.stopTimer();
        const running =
            this.playingValue &&
            !this.hovering &&
            !this.focused &&
            !this.reducedMotion;

        if (running) {
            this.timer = setTimeout(() => this.next(), this.intervalValue);
        }
    }

    stopTimer() {
        clearTimeout(this.timer);
        this.timer = null;
    }
}
