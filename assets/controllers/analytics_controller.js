import { Controller } from '@hotwired/stimulus';
import { track } from '../lib/analytics.js';

/**
 * Sends one named analytics event for a click. The button gives the event
 * name and its own label as action params. A feature param joins them when
 * the button belongs to one feature.
 */
export default class extends Controller {
    track(event) {
        const data = { button: event.params.button };
        if (event.params.feature) {
            data.feature = event.params.feature;
        }
        track(event.params.name, data);
    }
}
