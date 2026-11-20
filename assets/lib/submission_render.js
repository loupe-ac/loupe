// Turbo starts the visit of a form answer with the response already in hand.
// This tells the render of a submission apart from the render of another
// visit, such as a frame fallback to the same URL.
let answerHref = null;
let submission = null;
let fromSubmission = false;
let submissionVisit = null;

function navigator() {
    return window.Turbo?.session?.navigator ?? null;
}

function currentVisit() {
    return navigator()?.currentVisit ?? null;
}

document.addEventListener('turbo:submit-start', () => {
    answerHref = null;
    submission = null;
    fromSubmission = false;
});

document.addEventListener('turbo:submit-end', (event) => {
    const response = event.detail?.fetchResponse;
    const type = response?.header?.('Content-Type') ?? '';
    answerHref =
        response && !type.includes('turbo-stream')
            ? (response.location?.href ?? null)
            : null;
    submission = answerHref ? (event.detail?.formSubmission ?? null) : null;
});

// Turbo proposes the visit of a form answer while the navigator still holds
// that submission, and drops the submission when any other visit starts. So
// the proposal that finds it is the one that the form made.
document.addEventListener('turbo:before-visit', (event) => {
    fromSubmission =
        submission !== null &&
        navigator()?.formSubmission === submission &&
        event.detail?.url === answerHref;
});

document.addEventListener('turbo:visit', () => {
    const visit = currentVisit();
    if (fromSubmission && visit?.response) {
        submissionVisit = visit;
        submission = null;
        answerHref = null;
    }
    fromSubmission = false;
});

// A render with no visit under way, such as a failed submission, is the
// answer of the form too.
export function rendersSubmission() {
    const visit = currentVisit();
    return visit === null || visit === submissionVisit;
}
