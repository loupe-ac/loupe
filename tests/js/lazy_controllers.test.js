import { readdirSync, readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const EAGER = [
    'autosearch',
    'board_deck',
    'board_drag',
    'board_lane',
    'card_drawer',
    'csrf_protection',
    'details_memory',
    'device_code_input',
    'drawer',
    'inbox_anchor',
    'inbox_navigation',
    'keyboard_submit',
    'panel_tabs',
    'popover',
    'presence',
    'push_unsubscribe',
    'reference_link',
    'remember_project',
    'sidebar_counts',
];

const SUFFIX = '_controller.js';
const names = readdirSync('assets/controllers')
    .filter((file) => file.endsWith(SUFFIX))
    .map((file) => file.slice(0, -SUFFIX.length));

function fetchMode(name) {
    const source = readFileSync(`assets/controllers/${name}${SUFFIX}`, 'utf8');
    return source.match(/\/\* stimulusFetch: '(\w+)' \*\//)?.[1] ?? null;
}

describe('controller fetch mode', () => {
    it.each(EAGER)('%s is a controller file', (name) => {
        expect(names).toContain(name);
    });

    it.each(EAGER)('%s is marked eager', (name) => {
        expect(fetchMode(name)).toBe('eager');
    });

    it.each(names.filter((name) => !EAGER.includes(name)))(
        '%s is marked lazy',
        (name) => {
            expect(fetchMode(name)).toBe('lazy');
        },
    );
});
