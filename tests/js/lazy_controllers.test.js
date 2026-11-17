import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const LAZY = [
    'analytics',
    'analytics_join',
    'comment_reply',
    'comments_sheet',
    'contents_rail',
    'diff_bar',
    'diff_columns',
    'diff_navigation',
    'graph_drag',
    'graph_tile',
    'landing_ask',
    'landing_explainer',
    'landing_features',
    'review_draft_live',
    'review_finish',
    'review_finish_verdict',
    'review_jump',
    'review_live',
    'review_new_text',
    'review_panels',
    'review_stop_confirm',
];

describe('single-page controllers', () => {
    it.each(LAZY)('%s is marked lazy', (name) => {
        const source = readFileSync(
            `assets/controllers/${name}_controller.js`,
            'utf8',
        );
        expect(source).toContain("/* stimulusFetch: 'lazy' */");
    });
});
