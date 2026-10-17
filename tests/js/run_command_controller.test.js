/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import RunCommandController from '../../assets/controllers/run_command_controller.js';

let application;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

const mount = async (selectedBranch) => {
    document.body.innerHTML = `<div data-controller="run-command"
        data-run-command-template-value='["bin/console","export","--out={out}","{keep}"]'
        data-run-command-label-value="Command"
        data-run-command-label-in-value="Command, in %folder%">
        <select data-run-command-target="branch" data-action="change->run-command#refresh">
            <option value="" disabled>Pick a branch</option>
            <option value="main" data-folder="/work/loupe">main</option>
            <option value="epic/42" data-folder=".worktrees/card-42">epic/42</option>
        </select>
        <p data-run-command-target="noWorktree" hidden>No worktree</p>
        <input data-run-command-target="param" data-name="out" value="var/out" data-action="input->run-command#refresh">
        <div data-run-command-target="command"><span class="lp-code-dark__label">Command</span><pre><code>start</code></pre></div>
        <button type="submit" data-run-command-target="run" disabled>Run</button>
    </div>`;
    document.querySelector('select').value = selectedBranch;
    application = Application.start();
    application.register('run-command', RunCommandController);
    await settle();
};

const change = async (element, value) => {
    element.value = value;
    element.dispatchEvent(
        new Event(element.tagName === 'SELECT' ? 'change' : 'input', {
            bubbles: true,
        }),
    );
    await settle();
};

beforeEach(() => {
    document.body.replaceChildren();
});

afterEach(async () => {
    document.body.replaceChildren();
    await settle();
    application.stop();
});

it('keeps Run off and shows the warning until a branch with a worktree is picked', async () => {
    await mount('');
    const run = document.querySelector('[data-run-command-target="run"]');
    const warning = document.querySelector(
        '[data-run-command-target="noWorktree"]',
    );
    await change(document.querySelector('select'), '');
    expect(run.disabled).toBe(true);
    expect(warning.hidden).toBe(false);

    await change(document.querySelector('select'), 'epic/42');
    expect(run.disabled).toBe(false);
    expect(warning.hidden).toBe(true);
    expect(document.querySelector('.lp-code-dark__label').textContent).toBe(
        'Command, in .worktrees/card-42',
    );
});

it('fills the placeholders of the command line from the typed values', async () => {
    await mount('main');
    await change(document.querySelector('input'), 'var/other');
    expect(document.querySelector('code').textContent).toBe(
        'bin/console export --out=var/other {keep}',
    );
});
