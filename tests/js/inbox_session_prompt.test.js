/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, expect, it } from 'vitest';
import InboxSessionPromptController from '../../assets/controllers/inbox_session_prompt_controller.js';

let application;

async function mount() {
    document.body.innerHTML = `
        <form data-controller="inbox-session-prompt">
            <div data-inbox-session-prompt-target="field" hidden>
                <textarea>Use the skill.</textarea>
            </div>
            <button type="button" data-action="click->inbox-session-prompt#reveal">Start session</button>
        </form>`;
    application = Application.start();
    application.register('inbox-session-prompt', InboxSessionPromptController);
    await new Promise((resolve) => setTimeout(resolve, 0));
}

afterEach(() => {
    application?.stop();
    document.body.replaceChildren();
});

it('keeps the prompt field hidden until the owner picks Start session', async () => {
    await mount();

    expect(document.querySelector('[hidden]')).not.toBeNull();
});

it('shows the prompt field and focuses it when the owner picks Start session', async () => {
    await mount();

    document.querySelector('button').click();

    const field = document.querySelector(
        '[data-inbox-session-prompt-target="field"]',
    );
    expect(field.hidden).toBe(false);
    expect(document.activeElement).toBe(field.querySelector('textarea'));
});
