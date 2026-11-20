// A morph copies the saved state of the page onto it. A form that holds text
// the reader typed, and is not the form that submits, would lose that text.
const TEXT_FIELDS =
    'textarea, input:not([type="hidden"], [type="checkbox"], [type="radio"], [type="button"], [type="submit"])';

// Turbo clears `aria-busy` on a form when its request ends, which is before
// the render of the answer. So the form is remembered from the submit start
// until the render that follows.
let submitted = null;

export function isSubmitted(form) {
    return form === submitted;
}

export function keepTypedForm(event) {
    const { currentElement } = event.detail;
    if (
        !(currentElement instanceof HTMLFormElement) ||
        isSubmitted(currentElement)
    ) {
        return;
    }
    const typed = [...currentElement.querySelectorAll(TEXT_FIELDS)].some(
        (field) => field.value !== field.defaultValue,
    );
    if (typed) {
        event.preventDefault();
    }
}

document.addEventListener('turbo:submit-start', (event) => {
    submitted = event.target;
});
document.addEventListener('turbo:submit-end', (event) => {
    const response = event.detail.fetchResponse;
    const type = response?.header?.('Content-Type') ?? '';
    if (!response || type.includes('turbo-stream')) {
        submitted = null;
    }
});
document.addEventListener('turbo:render', () => {
    submitted = null;
});
document.addEventListener('turbo:morph', () => {
    submitted = null;
});
document.addEventListener('turbo:before-morph-element', keepTypedForm);
