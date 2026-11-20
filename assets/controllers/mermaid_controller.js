/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

// Every diagram, notice and toggle goes into a shadow root, because the
// comment anchors walk each light-DOM text node of the doc target.

let libraryPromise = null;
let renderCount = 0;

const shadowStyle = `
:host { display: block; user-select: none; margin-block: calc(var(--spacing) * 4); }
svg { max-width: 100%; height: auto; }
.lp-mermaid__notice, .lp-mermaid__toggle { font: inherit; font-size: 0.875em; color: var(--text-mute); }
.lp-mermaid__notice { display: flex; align-items: flex-start; gap: calc(var(--spacing) * 2); margin: 0; padding: calc(var(--spacing) * 2) calc(var(--spacing) * 3); border: 1px solid var(--border); border-radius: calc(var(--spacing) * 1.5); background: var(--surface-2); }
.lp-mermaid__notice--error { color: var(--status-danger-deep); border-color: var(--status-danger-border); background: var(--status-danger-bg); }
.lp-mermaid__notice svg, .lp-mermaid__toggle svg { width: 1em; height: 1em; flex-shrink: 0; }
.lp-mermaid__notice svg { margin-top: 0.2em; }
.lp-mermaid__toggle { display: flex; align-items: center; gap: calc(var(--spacing) * 1.5); margin: calc(var(--spacing) * 2) 0 0 auto; padding: calc(var(--spacing) * 1) calc(var(--spacing) * 2.5); border: 1px solid transparent; border-radius: calc(var(--spacing) * 1.5); background: transparent; cursor: pointer; transition: background-color 150ms, color 150ms; }
.lp-mermaid__toggle:hover { color: var(--ink); background: var(--bg-active); }
.lp-mermaid__figure { cursor: zoom-in; border-radius: calc(var(--spacing) * 1.5); }
.lp-mermaid__figure:focus-visible, .lp-mermaid__toggle:focus-visible { outline: 2px solid var(--accent-ink); outline-offset: 2px; }
.lp-mermaid__actions { display: flex; justify-content: flex-end; gap: calc(var(--spacing) * 1); }
.lp-mermaid__actions .lp-mermaid__toggle { margin: calc(var(--spacing) * 2) 0 0; }
`;

export default class extends Controller {
    static targets = [
        'doc',
        'disabledNotice',
        'errorNotice',
        'toggle',
        'expand',
    ];
    static values = { enabled: Boolean, moduleUrl: String };

    connect() {
        this.build();
        document.addEventListener('turbo:morph', this.onMorph);
    }

    // A page morph drops the hosts that the server never rendered.
    onMorph = () => {
        const missingHost = [
            ...this.docTarget.querySelectorAll('pre > code.language-mermaid'),
        ].some(
            (code) =>
                !code.parentElement.nextElementSibling?.matches('.lp-mermaid'),
        );
        if (missingHost) this.build();
    };

    build() {
        // A Turbo snapshot keeps the empty hosts and the hidden sources.
        this.removeHosts();
        const blocks = [
            ...this.docTarget.querySelectorAll('pre > code.language-mermaid'),
        ].map((code) => ({
            source: code.textContent,
            pre: code.parentElement,
            host: this.attachHost(code.parentElement),
        }));

        if (this.enabledValue) {
            this.rendering = this.renderAll(blocks);
            return;
        }
        for (const block of blocks) {
            this.append(block.host, this.disabledNoticeTarget);
        }
        this.rendering = Promise.resolve();
    }

    disconnect() {
        document.removeEventListener('turbo:morph', this.onMorph);
        this.removeHosts();
    }

    loadLibrary() {
        libraryPromise ??= import(/* @vite-ignore */ this.moduleUrlValue)
            .then((module) => module.default)
            .catch((error) => {
                libraryPromise = null;
                throw error;
            });
        return libraryPromise;
    }

    async renderAll(blocks) {
        let mermaid;
        try {
            mermaid = await this.loadLibrary();
            mermaid.initialize({
                startOnLoad: false,
                securityLevel: 'strict',
                suppressErrorRendering: true,
            });
        } catch {
            for (const block of blocks) {
                this.append(block.host, this.errorNoticeTarget);
            }
            return;
        }

        for (const block of blocks) {
            renderCount += 1;
            try {
                const { svg } = await mermaid.render(
                    `lp-mermaid-${renderCount}`,
                    block.source,
                );
                if (!block.host.isConnected) {
                    continue;
                }
                const parsed = document.createElement('template');
                parsed.innerHTML = svg;
                const figure = this.addFigure(block, parsed.content);
                this.addToggle(block, figure);
                block.pre.hidden = true;
            } catch {
                this.append(block.host, this.errorNoticeTarget);
            }
        }
    }

    attachHost(pre) {
        const host = document.createElement('div');
        host.className = 'lp-mermaid';
        const style = document.createElement('style');
        style.textContent = shadowStyle;
        host.attachShadow({ mode: 'open' }).append(style);
        pre.after(host);
        return host;
    }

    append(host, template) {
        host.shadowRoot.append(template.content.cloneNode(true));
    }

    // The figure holds the drawn SVG. It opens the viewer on a click, Enter
    // or Space, and it is where focus returns when the viewer closes.
    addFigure(block, content) {
        const figure = document.createElement('div');
        figure.className = 'lp-mermaid__figure';
        figure.tabIndex = 0;
        figure.setAttribute('role', 'button');
        figure.setAttribute('aria-label', this.expandTarget.dataset.openLabel);
        figure.append(content);
        block.host.shadowRoot.append(figure);
        figure.addEventListener('click', () => this.expand(figure));
        figure.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                this.expand(figure);
            }
        });
        return figure;
    }

    expand(figure) {
        this.dispatch('expand', {
            detail: { svg: figure.querySelector('svg'), figure },
        });
    }

    addToggle(block, figure) {
        const actions = document.createElement('div');
        actions.className = 'lp-mermaid__actions';
        actions.append(
            this.expandTarget.content.cloneNode(true),
            this.toggleTarget.content.cloneNode(true),
        );
        block.host.shadowRoot.append(actions);
        actions
            .querySelector('[data-expand]')
            .addEventListener('click', () => this.expand(figure));
        const button = actions.querySelector('[aria-expanded]');
        const label = button.querySelector('[data-label]');
        button.addEventListener('click', () => {
            block.pre.hidden = !block.pre.hidden;
            const sourceShown = !block.pre.hidden;
            button.setAttribute('aria-expanded', String(sourceShown));
            label.textContent = sourceShown
                ? button.dataset.labelHide
                : button.dataset.labelShow;
        });
    }

    removeHosts() {
        for (const host of this.docTarget.querySelectorAll('.lp-mermaid')) {
            host.remove();
        }
        for (const code of this.docTarget.querySelectorAll(
            'pre > code.language-mermaid',
        )) {
            code.parentElement.hidden = false;
        }
    }
}
