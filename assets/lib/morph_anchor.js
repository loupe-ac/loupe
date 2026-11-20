// A same-page redirect with a fragment morphs the page, and the fetch response
// drops the fragment. So the form that submits names its section, and the
// section scrolls into view after the morph when it is out of view.
let anchorId = null;
let answered = false;

function registerMorphAnchor() {
    document.addEventListener('turbo:submit-start', (event) => {
        anchorId = event.target?.closest?.('[data-morph-anchor]')?.id || null;
        answered = false;
    });
    // A stream answer or a failed request morphs nothing, so the anchor ends.
    document.addEventListener('turbo:submit-end', (event) => {
        const response = event.detail?.fetchResponse;
        const type = response?.header?.('Content-Type') ?? '';
        if (!response || type.includes('turbo-stream')) {
            anchorId = null;
        } else {
            answered = true;
        }
    });
    document.addEventListener('turbo:morph', () => {
        if (!answered) {
            return;
        }
        const id = anchorId;
        anchorId = null;
        answered = false;
        const element = id ? document.getElementById(id) : null;
        if (!element) {
            return;
        }
        const bounds = element.getBoundingClientRect();
        const inView = bounds.top >= 0 && bounds.bottom <= window.innerHeight;
        if (!inView) {
            element.scrollIntoView({ block: 'nearest' });
        }
    });
    // A full visit handles the fragment on its own.
    document.addEventListener('turbo:load', () => {
        anchorId = null;
        answered = false;
    });
}

registerMorphAnchor();
