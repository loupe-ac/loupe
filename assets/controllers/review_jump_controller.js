import { Controller } from '@hotwired/stimulus';

/**
 * Scrolls to the heading or block that a sheet row links to. The paper scrolls
 * inside .lp-main rather than the window, so the jump names an element and lets
 * scrollIntoView find the scroller. The row also closes its sheet, and that
 * action cancels the link.
 */
export default class extends Controller {
    go(event) {
        const id = event.currentTarget.getAttribute('href')?.slice(1);
        const target = id ? document.getElementById(id) : null;
        event.preventDefault();
        target?.scrollIntoView({ block: 'start' });
    }
}
