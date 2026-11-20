// A same-page redirect with a fragment morphs the page, and the fetch response
// drops the fragment. So the form that submits names its section, and the
// section scrolls into view after the morph when it is out of view.
let anchorId = null;

function registerMorphAnchor() {
    document.addEventListener('turbo:submit-start', (event) => {
        anchorId = event.target?.closest?.('[data-morph-anchor]')?.id || null;
    });
    document.addEventListener('turbo:morph', () => {
        const id = anchorId;
        anchorId = null;
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
    });
}

registerMorphAnchor();
