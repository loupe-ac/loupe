/*
 * Where a comment goes once it is written: the review screen posts it through
 * its own forms.
 */

/** Posts through the Symfony forms already on the review screen. */
export class ServerTransport {
    constructor(controller) {
        this.controller = controller;
    }

    // The anchor and body are already in the form's own fields — startComment
    // and startSuggestion put them there — so submitting is the whole job.
    comment(anchor, body, submitter) {
        this.controller.composerTarget.requestSubmit(submitter);
    }

    suggestion(anchor, replacement, body, submitter) {
        this.controller.suggestComposerTarget.requestSubmit(submitter);
    }

    strike(anchor) {
        const controller = this.controller;
        controller.strikeQuoteTarget.value = anchor.quote;
        controller.strikePrefixTarget.value = anchor.prefix;
        controller.strikeSuffixTarget.value = anchor.suffix;
        // requestSubmit(), not submit(): only the former fires the submit event
        // Turbo listens for, so submit() would trigger a full page navigation.
        controller.strikeFormTarget.requestSubmit();
    }
}
