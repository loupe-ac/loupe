import { Controller } from '@hotwired/stimulus';

/**
 * Checks a radio when a person focuses or types in the field that belongs to
 * its row, so the submitted choice matches what the person is filling in.
 *
 * Usage:
 *   <div data-controller="select-on-input">
 *     <input type="radio" data-select-on-input-target="radio">
 *     <input type="text" data-action="focus->select-on-input#select input->select-on-input#select">
 *   </div>
 */
export default class extends Controller {
    static targets = ['radio'];

    select() {
        if (this.hasRadioTarget) {
            this.radioTarget.checked = true;
        }
    }
}
