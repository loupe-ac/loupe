/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, describe, expect, it } from 'vitest';
import BoardViewController from '../../assets/controllers/board_view_controller.js';
import ConfirmInputController from '../../assets/controllers/confirm_input_controller.js';
import DeviceCodeInputController from '../../assets/controllers/device_code_input_controller.js';
import DisclosureController from '../../assets/controllers/disclosure_controller.js';
import FeatureFlagFormController from '../../assets/controllers/feature_flag_form_controller.js';
import FormDirtyController from '../../assets/controllers/form_dirty_controller.js';
import PopoverController from '../../assets/controllers/popover_controller.js';
import PushDeviceController from '../../assets/controllers/push_device_controller.js';
import RepositoryPickerController from '../../assets/controllers/repository_picker_controller.js';
import ReviewFinishVerdictController from '../../assets/controllers/review_finish_verdict_controller.js';
import ReviewNewTextController from '../../assets/controllers/review_new_text_controller.js';
import ReviewPanelsController from '../../assets/controllers/review_panels_controller.js';
import SplitButtonController from '../../assets/controllers/split_button_controller.js';

let application;

async function mount(html, identifier, controllerClass) {
    document.body.innerHTML = html;
    application = Application.start();
    application.register(identifier, controllerClass);
    await new Promise((resolve) => setTimeout(resolve, 0));
}

function morph(element) {
    element.dispatchEvent(new Event('turbo:morph-element', { bubbles: true }));
}

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
    window.localStorage.clear();
});

describe('after a morph', () => {
    it('confirm-input checks the typed value again', async () => {
        await mount(
            `<div data-controller="confirm-input" data-confirm-input-expected-value="abc">
                <input data-confirm-input-target="input" value="abc">
                <button data-confirm-input-target="submit" disabled>Delete</button>
            </div>`,
            'confirm-input',
            ConfirmInputController,
        );
        const submit = document.querySelector('button');
        expect(submit.disabled).toBe(false);

        submit.disabled = true;
        morph(document.querySelector('[data-controller]'));

        expect(submit.disabled).toBe(false);
    });

    it('feature-flag-form hides the fields of the other types', async () => {
        await mount(
            `<form data-controller="feature-flag-form">
                <select data-feature-flag-form-target="typeSelect">
                    <option value="bool">bool</option>
                    <option value="int" selected>int</option>
                </select>
                <div data-feature-flag-form-target="boolField"></div>
                <div data-feature-flag-form-target="intField"></div>
                <div data-feature-flag-form-target="stringField"></div>
            </form>`,
            'feature-flag-form',
            FeatureFlagFormController,
        );
        const [boolField, intField] = document.querySelectorAll(
            '[data-feature-flag-form-target$="Field"]',
        );
        expect(boolField.hidden).toBe(true);
        expect(intField.hidden).toBe(false);

        boolField.hidden = false;
        intField.hidden = true;
        morph(document.querySelector('form'));

        expect(boolField.hidden).toBe(true);
        expect(intField.hidden).toBe(false);
    });

    it('device-code-input builds the boxes again', async () => {
        await mount(
            `<div data-controller="device-code-input"
                  data-device-code-input-character-label-value="%position% of %count%">
                <input data-device-code-input-target="field" required value="ABCD">
                <div data-device-code-input-target="boxes" hidden></div>
            </div>`,
            'device-code-input',
            DeviceCodeInputController,
        );
        const field = document.querySelector('input');
        const boxes = document.querySelector(
            '[data-device-code-input-target="boxes"]',
        );
        expect(boxes.querySelectorAll('input')).toHaveLength(8);

        boxes.replaceChildren();
        boxes.hidden = true;
        field.hidden = false;
        field.required = true;
        morph(document.querySelector('[data-controller]'));

        expect(boxes.querySelectorAll('input')).toHaveLength(8);
        expect(boxes.hidden).toBe(false);
        expect(field.hidden).toBe(true);
        expect(boxes.querySelector('input').value).toBe('A');
    });

    it('review-finish-verdict unchecks a blocked Approve', async () => {
        await mount(
            `<form data-controller="review-finish-verdict" data-form-draft-key-value="k">
                <input type="radio" name="verdict" value="approved" data-review-finish-verdict-target="approve" disabled>
                <input type="radio" name="verdict" value="changes-requested" data-review-finish-verdict-target="changes">
            </form>`,
            'review-finish-verdict',
            ReviewFinishVerdictController,
        );
        const [approve, changes] = document.querySelectorAll('input');
        expect(changes.checked).toBe(true);

        approve.checked = true;
        changes.checked = false;
        morph(document.querySelector('form'));

        expect(approve.checked).toBe(false);
        expect(changes.checked).toBe(true);
    });

    it('review-new-text turns the remembered switch back on', async () => {
        window.localStorage.setItem('loupe.review.newText', '1');
        globalThis.fetch = () =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve({ anchors: [] }),
            });
        await mount(
            `<button data-controller="review-new-text" data-review-new-text-url-value="/x" aria-checked="false"></button>`,
            'review-new-text',
            ReviewNewTextController,
        );
        const button = document.querySelector('button');
        expect(button.getAttribute('aria-checked')).toBe('true');

        button.setAttribute('aria-checked', 'false');
        morph(button);

        expect(button.getAttribute('aria-checked')).toBe('true');
    });

    it('review-panels shows the stored panels again', async () => {
        window.localStorage.setItem(
            'loupe.review.panels',
            JSON.stringify(['comments']),
        );
        await mount(
            `<div data-controller="review-panels">
                <button data-review-panels-target="button" data-review-panels-name-param="comments" aria-pressed="false"></button>
                <div data-review-panels-target="panel" data-review-panel="comments" hidden></div>
            </div>`,
            'review-panels',
            ReviewPanelsController,
        );
        const button = document.querySelector('button');
        const panel = document.querySelector('[data-review-panel]');
        expect(panel.hidden).toBe(false);

        panel.hidden = true;
        button.setAttribute('aria-pressed', 'false');
        morph(document.querySelector('[data-controller]'));

        expect(panel.hidden).toBe(false);
        expect(button.getAttribute('aria-pressed')).toBe('true');
    });

    it('board-view keeps the chosen view', async () => {
        await mount(
            `<div><div data-controller="board-view" data-board-view-list-url-value="/list">
                <div data-board-view-target="board"></div>
                <div data-board-view-target="list" hidden></div>
                <button data-board-view-target="boardButton" aria-pressed="true"></button>
                <button data-board-view-target="listButton" aria-pressed="false"></button>
            </div></div>`,
            'board-view',
            BoardViewController,
        );
        const root = document.querySelector('[data-controller]');
        const application_ = application.getControllerForElementAndIdentifier(
            root,
            'board-view',
        );
        application_.showList();
        const board = root.querySelector('[data-board-view-target="board"]');
        const list = root.querySelector('[data-board-view-target="list"]');
        expect(list.hidden).toBe(false);

        board.hidden = false;
        list.hidden = true;
        list.removeAttribute('src');
        root.querySelector(
            '[data-board-view-target="listButton"]',
        ).setAttribute('aria-pressed', 'false');
        morph(root);

        expect(board.hidden).toBe(true);
        expect(list.hidden).toBe(false);
        expect(list.getAttribute('src')).toBe('/list');
        expect(
            root
                .querySelector('[data-board-view-target="listButton"]')
                .getAttribute('aria-pressed'),
        ).toBe('true');
    });

    it('disclosure reads the open state from the new markup', async () => {
        await mount(
            `<div data-controller="disclosure" class="disclosure-open">
                <button data-disclosure-target="trigger" aria-expanded="true"></button>
                <div data-disclosure-target="content" class="open"></div>
            </div>`,
            'disclosure',
            DisclosureController,
        );
        const root = document.querySelector('[data-controller]');
        const trigger = root.querySelector('button');
        expect(trigger.getAttribute('aria-expanded')).toBe('true');

        root.classList.remove('disclosure-open');
        trigger.setAttribute('aria-expanded', 'true');
        morph(root);

        expect(root.open).toBe(false);
        expect(trigger.getAttribute('aria-expanded')).toBe('false');
    });

    it('popover rewrites aria-expanded from the panel', async () => {
        await mount(
            `<div data-controller="popover">
                <button data-popover-target="trigger" aria-expanded="false"></button>
                <div data-popover-target="panel"></div>
            </div>`,
            'popover',
            PopoverController,
        );
        const trigger = document.querySelector('button');
        expect(trigger.getAttribute('aria-expanded')).toBe('true');

        document.querySelector('[data-popover-target="panel"]').hidden = true;
        morph(document.querySelector('[data-controller]'));

        expect(trigger.getAttribute('aria-expanded')).toBe('false');
    });

    it('split-button rewrites aria-expanded from the panel', async () => {
        await mount(
            `<div data-controller="split-button">
                <button data-split-button-target="trigger" aria-expanded="false"></button>
                <div data-split-button-target="panel" hidden></div>
            </div>`,
            'split-button',
            SplitButtonController,
        );
        const trigger = document.querySelector('button');
        trigger.removeAttribute('aria-expanded');

        morph(document.querySelector('[data-controller]'));

        expect(trigger.getAttribute('aria-expanded')).toBe('false');
    });

    it('repository-picker counts the ticked boxes again', async () => {
        await mount(
            `<div data-controller="repository-picker"
                  data-repository-picker-none-value="Pick" data-repository-picker-one-value="Add one"
                  data-repository-picker-many-value="Add %count%">
                <div data-repository-picker-target="repository"><input type="checkbox" checked></div>
                <button data-repository-picker-target="submit" disabled>Pick</button>
            </div>`,
            'repository-picker',
            RepositoryPickerController,
        );
        const submit = document.querySelector('button');
        expect(submit.textContent).toBe('Add one');

        submit.disabled = true;
        submit.textContent = 'Pick';
        morph(document.querySelector('[data-controller]'));

        expect(submit.disabled).toBe(false);
        expect(submit.textContent).toBe('Add one');
    });

    it('form-dirty takes the saved values as the clean baseline', async () => {
        await mount(
            `<div data-controller="form-dirty" data-form-dirty-clean-value="clean" data-form-dirty-dirty-value="dirty">
                <form data-action="input->form-dirty#check"><input name="a" value="old"></form>
                <span data-form-dirty-target="note">clean</span>
            </div>`,
            'form-dirty',
            FormDirtyController,
        );
        const input = document.querySelector('input');
        const note = document.querySelector('span');

        input.value = 'saved';
        input.defaultValue = 'saved';
        morph(document.querySelector('[data-controller]'));
        input.dispatchEvent(new Event('input', { bubbles: true }));

        expect(note.textContent).toBe('clean');
    });

    it('push-device picks the panel again', async () => {
        await mount(
            `<div data-controller="push-device" data-push-device-preview-state-value="off">
                <div data-push-device-target="panel" data-panel="off"></div>
                <div data-push-device-target="panel" data-panel="on" hidden></div>
            </div>`,
            'push-device',
            PushDeviceController,
        );
        const [off, on] = document.querySelectorAll('[data-panel]');
        expect(off.hidden).toBe(false);

        off.hidden = true;
        on.hidden = false;
        morph(document.querySelector('[data-controller]'));
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(off.hidden).toBe(false);
        expect(on.hidden).toBe(true);
    });
});
