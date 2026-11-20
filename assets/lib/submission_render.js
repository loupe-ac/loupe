// Turbo starts the visit of a form answer with the response already in hand,
// and aims it at the location of that response. A live refresh or another
// visit does not match both. This tells the render of a submission apart
// from an unrelated one.
let answerHref = null;
let submissionVisit = null;

function currentVisit() {
    return window.Turbo?.session?.navigator?.currentVisit ?? null;
}

document.addEventListener('turbo:submit-start', () => {
    answerHref = null;
});

document.addEventListener('turbo:submit-end', (event) => {
    const response = event.detail?.fetchResponse;
    const type = response?.header?.('Content-Type') ?? '';
    answerHref =
        response && !type.includes('turbo-stream')
            ? (response.location?.href ?? null)
            : null;
});

document.addEventListener('turbo:visit', () => {
    const visit = currentVisit();
    if (visit?.response && answerHref && visit.location?.href === answerHref) {
        submissionVisit = visit;
        answerHref = null;
    }
});

// A render with no visit under way, such as a failed submission, is the
// answer of the form too.
export function rendersSubmission() {
    const visit = currentVisit();
    return visit === null || visit === submissionVisit;
}
