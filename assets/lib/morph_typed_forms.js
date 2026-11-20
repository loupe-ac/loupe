// A morph copies the saved state of the page onto it. A form that holds text
// the reader typed, and is not the form that submits, would lose that text.
const TEXT_FIELDS =
    'textarea, input:not([type="hidden"], [type="checkbox"], [type="radio"], [type="button"], [type="submit"])';

export function keepTypedForm(event) {
    const { currentElement } = event.detail;
    if (
        !(currentElement instanceof HTMLFormElement) ||
        currentElement.getAttribute('aria-busy') === 'true'
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

document.addEventListener('turbo:before-morph-element', keepTypedForm);
