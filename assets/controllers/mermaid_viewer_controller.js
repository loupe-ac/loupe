/* stimulusFetch: 'lazy' */
import ModalController from './modal_controller.js';
import {
    FIT_MAX_SCALE,
    fit,
    limits,
    pan,
    viewBox,
    zoomAt,
} from '../lib/pan_zoom.js';

const KEY_STEP = 40;
const BUTTON_FACTOR = 1.25;

const stageStyle = `
:host { display: block; width: 100%; height: 100%; }
svg { display: block; cursor: grab; }
:host(.is-dragging) svg { cursor: grabbing; }
`;

export default class extends ModalController {
    static targets = ['dialog', 'stage', 'output', 'zoomIn', 'zoomOut'];

    // Fields, because Stimulus can call a target callback before connect().
    onWheel = (event) => this.wheel(event);
    onKeydown = (event) => this.key(event);
    onPointerDown = (event) => this.pointerDown(event);
    onPointerMove = (event) => this.pointerMove(event);
    onPointerEnd = (event) => this.pointerEnd(event);

    connect() {
        super.connect();
        this.state = null;
        this.pointers = new Map();
        this.pinchDistance = null;
        this.svg = null;
        this.content = null;
        this.range = null;
        this.root = this.stageTarget.attachShadow({ mode: 'open' });
        const style = document.createElement('style');
        style.textContent = stageStyle;
        this.root.append(style);
        this.resizeObserver = new ResizeObserver(() => this.resize());
    }

    disconnect() {
        if (this.hasDialogTarget && this.dialogTarget.open) {
            this.dialogTarget.close();
        }
        this.clear();
        this.resizeObserver?.disconnect();
        super.disconnect();
    }

    dialogTargetConnected(dialog) {
        super.dialogTargetConnected(dialog);
        dialog.addEventListener('wheel', this.onWheel, { passive: false });
        dialog.addEventListener('keydown', this.onKeydown);
    }

    dialogTargetDisconnected(dialog) {
        super.dialogTargetDisconnected(dialog);
        dialog.removeEventListener('wheel', this.onWheel);
        dialog.removeEventListener('keydown', this.onKeydown);
    }

    stageTargetConnected(stage) {
        stage.addEventListener('pointerdown', this.onPointerDown);
        stage.addEventListener('pointermove', this.onPointerMove);
        stage.addEventListener('pointerup', this.onPointerEnd);
        stage.addEventListener('pointercancel', this.onPointerEnd);
    }

    stageTargetDisconnected(stage) {
        stage.removeEventListener('pointerdown', this.onPointerDown);
        stage.removeEventListener('pointermove', this.onPointerMove);
        stage.removeEventListener('pointerup', this.onPointerEnd);
        stage.removeEventListener('pointercancel', this.onPointerEnd);
    }

    show(event) {
        const { svg, figure } = event.detail;
        this.clear();
        const copy = svg.cloneNode(true);
        const box = (svg.getAttribute('viewBox') ?? '0 0 100 100')
            .trim()
            .split(/[\s,]+/)
            .map(Number);
        this.content = {
            x: box[0],
            y: box[1],
            width: box[2],
            height: box[3],
        };
        copy.removeAttribute('style');
        this.svg = copy;
        this.root.append(copy);
        this.open();
        // Focus always goes back to the diagram, also after Expand.
        this.returnFocusTo = figure;
        this.fitToStage();
        this.resizeObserver.observe(this.stageTarget);
    }

    close(event) {
        const closing = super.close(event);
        this.resizeObserver.disconnect();
        this.pointers.clear();
        return closing.then(() => this.clear());
    }

    clear() {
        this.svg?.remove();
        this.svg = null;
        this.state = null;
    }

    get stageSize() {
        const { width, height } = this.stageTarget.getBoundingClientRect();
        return { width: width || 1, height: height || 1 };
    }

    fitToStage() {
        const stage = this.stageSize;
        this.state = fit(this.content, stage, FIT_MAX_SCALE);
        this.range = limits(this.state.scale);
        this.apply();
    }

    resize() {
        if (this.state) {
            this.apply();
        }
    }

    apply() {
        const stage = this.stageSize;
        this.svg.setAttribute('width', String(stage.width));
        this.svg.setAttribute('height', String(stage.height));
        this.svg.setAttribute('viewBox', viewBox(this.state, stage));
        const percent = Math.round(this.state.scale * 100);
        this.outputTarget.value = `${percent}%`;
        this.outputTarget.textContent = `${percent}%`;
        this.zoomInTarget.disabled = this.state.scale >= this.range.max;
        this.zoomOutTarget.disabled = this.state.scale <= this.range.min;
    }

    zoomIn() {
        this.zoomBy(BUTTON_FACTOR);
    }

    zoomOut() {
        this.zoomBy(1 / BUTTON_FACTOR);
    }

    fit() {
        if (this.state) {
            this.fitToStage();
        }
    }

    zoomBy(factor, point) {
        if (!this.state) {
            return;
        }
        const stage = this.stageSize;
        this.state = zoomAt(
            this.state,
            factor,
            point ?? { x: stage.width / 2, y: stage.height / 2 },
            this.range,
        );
        this.apply();
    }

    stagePoint(clientX, clientY) {
        const rect = this.stageTarget.getBoundingClientRect();
        return { x: clientX - rect.left, y: clientY - rect.top };
    }

    wheel(event) {
        event.preventDefault();
        if (!this.state) {
            return;
        }
        const lines = event.deltaMode === 1 ? 16 : 1;
        const strength = event.ctrlKey ? 0.01 : 0.002;
        const factor = Math.exp(-event.deltaY * lines * strength);
        this.zoomBy(factor, this.stagePoint(event.clientX, event.clientY));
    }

    key(event) {
        if (event.ctrlKey || event.metaKey || event.altKey || !this.state) {
            return;
        }
        const moves = {
            ArrowLeft: [KEY_STEP, 0],
            ArrowRight: [-KEY_STEP, 0],
            ArrowUp: [0, KEY_STEP],
            ArrowDown: [0, -KEY_STEP],
        };
        if (event.key === '+' || event.key === '=') {
            this.zoomIn();
        } else if (event.key === '-') {
            this.zoomOut();
        } else if (event.key === '0') {
            this.fit();
        } else if (moves[event.key]) {
            this.state = pan(this.state, ...moves[event.key]);
            this.apply();
        } else {
            return;
        }
        event.preventDefault();
    }

    pointerDown(event) {
        this.stageTarget.setPointerCapture?.(event.pointerId);
        this.pointers.set(event.pointerId, {
            x: event.clientX,
            y: event.clientY,
        });
        this.pinchDistance = this.pointerDistance();
        this.stageTarget.classList.add('is-dragging');
    }

    pointerMove(event) {
        const previous = this.pointers.get(event.pointerId);
        if (!previous || !this.state) {
            return;
        }
        const current = { x: event.clientX, y: event.clientY };
        this.pointers.set(event.pointerId, current);
        if (this.pointers.size === 1) {
            this.state = pan(
                this.state,
                current.x - previous.x,
                current.y - previous.y,
            );
            this.apply();
            return;
        }
        const distance = this.pointerDistance();
        if (this.pinchDistance && distance) {
            const [first, second] = [...this.pointers.values()];
            const middle = this.stagePoint(
                (first.x + second.x) / 2,
                (first.y + second.y) / 2,
            );
            this.zoomBy(distance / this.pinchDistance, middle);
        }
        this.pinchDistance = distance;
    }

    pointerEnd(event) {
        this.pointers.delete(event.pointerId);
        this.pinchDistance = this.pointerDistance();
        if (this.pointers.size === 0) {
            this.stageTarget.classList.remove('is-dragging');
        }
    }

    pointerDistance() {
        if (this.pointers.size < 2) {
            return null;
        }
        const [first, second] = [...this.pointers.values()];
        return Math.hypot(first.x - second.x, first.y - second.y);
    }
}
