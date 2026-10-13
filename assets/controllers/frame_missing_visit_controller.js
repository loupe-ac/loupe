import { Controller } from '@hotwired/stimulus';

/**
 * A turbo-frame whose submit can answer with a page that holds no matching
 * frame, such as the sign-up page when registration is open. The response
 * then replaces the whole page instead of "Content missing".
 */
export default class extends Controller {
    visit(event) {
        event.preventDefault();
        event.detail.visit(event.detail.response);
    }
}
