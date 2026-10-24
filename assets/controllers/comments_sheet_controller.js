import { Controller } from '@hotwired/stimulus';

/**
 * The Open and Resolved tabs of the Comments sheet. The choice lives in a
 * `data-status` attribute on a box that holds the tabs and the list, and CSS
 * hides the rows by their `data-anchor-status`. A stream replaces the list but
 * never the box, so the choice survives it.
 */
export default class extends Controller {
    static targets = ['view', 'tab'];

    choose(event) {
        const status = event.params.status;
        this.viewTarget.dataset.status = status;
        for (const tab of this.tabTargets) {
            tab.setAttribute(
                'aria-selected',
                String(tab.dataset.commentsSheetStatusParam === status),
            );
        }
    }
}
