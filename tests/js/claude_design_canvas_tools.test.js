import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const file = fileURLToPath(
    new URL(
        '../../plugins/loupe-lifecycle/skills/loupe-product-design/references/claude-design.md',
        import.meta.url,
    ),
);
const NOT_CANVAS_TOOLS = [
    'artifact_get',
    'artifact_read',
    'base_version',
    'of_type',
    'subagent_type',
];

describe('claude-design.md canvas tools', () => {
    it('loads each canvas tool it names in the C1 ToolSearch call', () => {
        const text = readFileSync(file, 'utf8');
        const selection = text.match(/`select:([^`]+)`/)[1].split(',');
        const named = [...text.matchAll(/`([a-z]+(?:_[a-z]+)+)`/g)]
            .map((match) => match[1])
            .filter((name) => !NOT_CANVAS_TOOLS.includes(name));

        const loaded = selection.map((tool) =>
            tool.replace('mcp__claude-artifacts__', ''),
        );

        expect(named).toContain('copy_file');
        expect(named.filter((name) => !loaded.includes(name))).toEqual([]);
    });
});
