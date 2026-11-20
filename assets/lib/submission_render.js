// Turbo starts the visit of a form answer with the response already in hand.
// This tells the render of a submission apart from the render of another
// visit, such as a frame fallback to the same URL.
let answerHref = null;
let submission = null;
let fromSubmission = false;
let submissionVisit = null;
let answerHtml;
let requestRunning = false;

function currentVisit() {
    return window.Turbo?.session?.navigator?.currentVisit ?? null;
}

document.addEventListener('turbo:submit-start', () => {
    requestRunning = true;
    answerHref = null;
    submissionVisit = null;
    submission = null;
    fromSubmission = false;
    answerHtml = undefined;
});

document.addEventListener('turbo:submit-end', (event) => {
    requestRunning = false;
    const response = event.detail?.fetchResponse;
    const type = response?.header?.('Content-Type') ?? '';
    answerHref =
        response && !type.includes('turbo-stream')
            ? (response.location?.href ?? null)
            : null;
    submission = answerHref ? (event.detail?.formSubmission ?? null) : null;
    if (submission) {
        Promise.resolve(response.responseHTML).then((html) => {
            answerHtml = html;
            verifyVisit();
        });
    }
});

// A response-backed visit to the same URL can come from a frame fallback while
// the navigator still holds the submission. Only the visit that carries the
// body of the form answer is the answer. A rejected fallback leaves the
// submission pending, so the visit that carries the answer can still match.
function verifyVisit() {
    if (
        submissionVisit !== null &&
        answerHtml !== undefined &&
        submissionVisit.response?.responseHTML !== answerHtml
    ) {
        submissionVisit = null;
    } else if (submissionVisit !== null && answerHtml !== undefined) {
        submission = null;
        answerHref = null;
    }
}

// Turbo drops the navigator submission when any visit starts, so a fallback
// visit can clear it before the answer is proposed. The answer is the visit
// to the answer URL, and verifyVisit then checks it by its body.
document.addEventListener('turbo:before-visit', (event) => {
    fromSubmission = submission !== null && event.detail?.url === answerHref;
});

document.addEventListener('turbo:visit', () => {
    const visit = currentVisit();
    if (fromSubmission && visit?.response) {
        submissionVisit = visit;
        verifyVisit();
    }
    fromSubmission = false;
});

// A render with no visit under way, such as a failed submission, is the
// answer of the form too. Turbo clears the visit when a submission starts, so
// a render while the request runs is a live morph, never the answer.
export function rendersSubmission() {
    if (requestRunning) {
        return false;
    }
    const visit = currentVisit();
    return visit === null || visit === submissionVisit;
}
