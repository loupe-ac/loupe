/** @vitest-environment jsdom */
import { Application, Controller } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import ReviewStopConfirmController from '../../assets/controllers/review_stop_confirm_controller.js';

let application;
let form;
let reached;
let asked;

const page = (active) => `
<form data-controller="review-stop-confirm probe" data-review-stop-confirm-active-value="${active}" data-review-stop-confirm-dialog-value="stop-confirm"
      data-action="submit->review-stop-confirm#check submit->probe#reached">
    <div id="draft-review-warning">
        <span hidden data-review-stop-confirm-copy="approved" data-lead="Approve lead" data-body="Approve body" data-confirm="Stop the review and approve"></span>
        <span hidden data-review-stop-confirm-copy="changes-requested" data-lead="Changes lead" data-body="Changes body" data-confirm="Stop the review and request changes"></span>
    </div>
    <input type="radio" name="verdict" value="approved">
    <input type="radio" name="verdict" value="changes-requested">
    <button type="submit">Submit review</button>
</form>
<div id="stop-confirm">
    <p data-review-stop-confirm-lead></p>
    <p data-review-stop-confirm-body></p>
    <button type="button" data-review-stop-confirm-confirm></button>
    <button type="button" id="back">Go back</button>
</div>`;

class ProbeController extends Controller {
    reached() {
        reached();
    }
}

async function mount(active = true) {
    document.body.innerHTML = page(active);
    reached = vi.fn();
    asked = vi.fn();
    window.addEventListener('review:stop-confirm', asked);
    application = Application.start();
    application.register('review-stop-confirm', ReviewStopConfirmController);
    application.register('probe', ProbeController);
    await new Promise((resolve) => setTimeout(resolve, 0));
    form = document.querySelector('form');
}

function choose(value) {
    document.querySelector(`input[value="${value}"]`).checked = true;
}

function submit() {
    const event = new Event('submit', { bubbles: true, cancelable: true });
    form.dispatchEvent(event);
    return event;
}

afterEach(async () => {
    document.body.replaceChildren();
    await new Promise((resolve) => setTimeout(resolve, 0));
    application.stop();
    window.removeEventListener('review:stop-confirm', asked);
    vi.restoreAllMocks();
});

beforeEach(() => {
    vi.spyOn(HTMLFormElement.prototype, 'requestSubmit').mockImplementation(
        function () {
            this.dispatchEvent(
                new Event('submit', { bubbles: true, cancelable: true }),
            );
        },
    );
});

it('stops the submit, fills the dialog for the chosen verdict and opens it', async () => {
    await mount();
    choose('approved');

    const event = submit();

    expect(event.defaultPrevented).toBe(true);
    expect(reached).not.toHaveBeenCalled();
    expect(asked).toHaveBeenCalledTimes(1);
    expect(
        document.querySelector('[data-review-stop-confirm-lead]').textContent,
    ).toBe('Approve lead');
    expect(
        document.querySelector('[data-review-stop-confirm-body]').textContent,
    ).toBe('Approve body');
    expect(
        document.querySelector('[data-review-stop-confirm-confirm]')
            .textContent,
    ).toBe('Stop the review and approve');
});

it('uses the text of the other verdict when that one is chosen', async () => {
    await mount();
    choose('changes-requested');

    submit();

    expect(
        document.querySelector('[data-review-stop-confirm-confirm]')
            .textContent,
    ).toBe('Stop the review and request changes');
    expect(
        document.querySelector('[data-review-stop-confirm-lead]').textContent,
    ).toBe('Changes lead');
});

it('lets the submit through once the confirm button is pressed', async () => {
    await mount();
    choose('approved');
    submit();

    document.querySelector('[data-review-stop-confirm-confirm]').click();

    expect(HTMLFormElement.prototype.requestSubmit).toHaveBeenCalledTimes(1);
    expect(reached).toHaveBeenCalledTimes(1);
});

it('asks again for a later submit after a confirmed one', async () => {
    await mount();
    choose('approved');
    submit();
    document.querySelector('[data-review-stop-confirm-confirm]').click();
    asked.mockClear();

    const event = submit();

    expect(event.defaultPrevented).toBe(true);
    expect(asked).toHaveBeenCalledTimes(1);
});

it('does not submit when the person goes back', async () => {
    await mount();
    choose('approved');
    submit();

    document.getElementById('back').click();

    expect(HTMLFormElement.prototype.requestSubmit).not.toHaveBeenCalled();
    expect(reached).not.toHaveBeenCalled();
});

it('does not stop the submit when the review is not active', async () => {
    await mount(false);
    choose('approved');

    const event = submit();

    expect(event.defaultPrevented).toBe(false);
    expect(asked).not.toHaveBeenCalled();
    expect(reached).toHaveBeenCalledTimes(1);
});

it('does not stop a submit that has no verdict yet', async () => {
    await mount();

    const event = submit();

    expect(event.defaultPrevented).toBe(false);
    expect(asked).not.toHaveBeenCalled();
});

it('does not stop a submit when the warning holds no text for the verdict', async () => {
    await mount();
    document.getElementById('draft-review-warning').replaceChildren();
    choose('approved');

    const event = submit();

    expect(event.defaultPrevented).toBe(false);
    expect(asked).not.toHaveBeenCalled();
});

it('follows the active value when it changes', async () => {
    await mount(false);
    choose('approved');
    form.dataset.reviewStopConfirmActiveValue = 'true';
    await new Promise((resolve) => setTimeout(resolve, 0));

    const event = submit();

    expect(event.defaultPrevented).toBe(true);
});
