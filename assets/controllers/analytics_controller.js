import { Controller } from '@hotwired/stimulus';
import { track } from '../lib/analytics.js';

/**
 * Sends one named analytics event for a click. The button gives the event
 * name and its own label as action params.
 */
export default class extends Controller {
    track(event) {
        track(event.params.name, { button: event.params.button });
    }
}
