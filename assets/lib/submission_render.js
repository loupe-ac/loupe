// Turbo starts the visit of a form answer with the response already in hand.
// A live refresh or another visit has none when it starts. This tells the
// render of a submission apart from an unrelated one.
let submissionVisit = null;

function currentVisit() {
    return window.Turbo?.session?.navigator?.currentVisit ?? null;
}

document.addEventListener('turbo:visit', () => {
    const visit = currentVisit();
    submissionVisit = visit?.response ? visit : null;
});

// A render with no visit under way, such as a failed submission, is the
// answer of the form too.
export function rendersSubmission() {
    const visit = currentVisit();
    return visit === null || visit === submissionVisit;
}
