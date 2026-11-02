#!/usr/bin/env node
// Minimal stdio MCP server that stands in for the loupe MCP.
// Every call is appended as one JSON line to $LOUPE_STUB_LOG.
import { appendFileSync } from 'node:fs';
import { createInterface } from 'node:readline';

const log = process.env.LOUPE_STUB_LOG || '/dev/null';
const columns = [
    'Backlog',
    'Product design',
    'Tech design',
    'Implementation',
    'Review',
    'Done',
];
const card = {
    id: 'card-stub-1',
    number: 7,
    title: 'Add tags to notes',
    status: 'Backlog',
    body: 'Owners want to label notes with tags and filter the note list by tag.',
    tags: [],
    documentIds: [],
    pullRequestUrls: [],
    requiresHumanReview: false,
};
const docs = {};
const tools = {
    card_get: () => card,
    card_search: () => ({ cards: [card] }),
    card_list: () => ({ cards: [card] }),
    card_update: (a) => {
        if (a.documentIds !== undefined)
            card.documentIds =
                typeof a.documentIds === 'string'
                    ? JSON.parse(a.documentIds)
                    : a.documentIds;
        if (a.status) card.status = a.status;
        return card;
    },
    card_create: (a) => ({ ...card, ...a }),
    card_run_open: (a) => {
        if (a.status) card.status = a.status;
        return { sessionId: a.sessionId || 'stub-session-1', card };
    },
    card_run_close: () => ({ closed: true }),
    board_columns: () => ({
        columns: columns.map((name, i) => ({
            id: `col-${i}`,
            name,
            slug: name.toLowerCase().replace(/ /g, '-'),
        })),
    }),
    workflow_get: () => ({
        template: 'Lifecycle',
        epicBranch: 'epic/{number}',
        columns,
    }),
    project_current: () => ({
        id: 'proj-stub',
        name: 'Notes app',
        workflow: 'Lifecycle',
    }),
    tag_list: () => ({
        tags: [
            { name: 'product-design' },
            { name: 'tech-design' },
            { name: 'decisions' },
            { name: 'design' },
        ],
    }),
    document_list: () => ({
        documents: Object.values(docs).map((d) => ({
            documentId: d.documentId,
            title: d.title,
            tags: d.tags,
            status: d.status,
        })),
    }),
    document_get: (a) => docs[a.documentId || a.id] || { error: 'not found' },
    document_create: (a) => {
        const n = `doc-stub-${Object.keys(docs).length + 1}`;
        const draft = a.draft === true || a.draft === 'true';
        docs[n] = {
            documentId: n,
            title: a.title,
            markdown: a.markdown ?? a.content,
            tags: typeof a.tags === 'string' ? JSON.parse(a.tags) : a.tags,
            status: draft ? 'draft' : 'published',
            version: 1,
        };
        return {
            documentId: n,
            url: `http://stub.invalid/documents/${n}`,
            status: docs[n].status,
        };
    },
    document_revise: () => ({ version: 2 }),
    document_set_tags: () => ({ ok: true }),
    document_set_references: () => ({ ok: true }),
    document_publish: () => ({ status: 'published' }),
    artifact_list: () => ({ artifacts: [] }),
    inbox_search: () => ({ items: [] }),
    series_list: () => ({ series: [] }),
};
const defs = Object.keys(tools).map((name) => ({
    name,
    description: `Stub of the loupe ${name} tool.`,
    inputSchema: { type: 'object', additionalProperties: true },
}));
const send = (m) =>
    process.stdout.write(JSON.stringify({ jsonrpc: '2.0', ...m }) + '\n');

createInterface({ input: process.stdin }).on('line', (line) => {
    let req;
    try {
        req = JSON.parse(line);
    } catch {
        return;
    }
    const { id, method, params } = req;
    if (method === 'initialize') {
        send({
            id,
            result: {
                protocolVersion: params?.protocolVersion || '2025-06-18',
                capabilities: { tools: {} },
                serverInfo: { name: 'loupe-stub', version: '0' },
            },
        });
    } else if (method === 'tools/list') {
        send({ id, result: { tools: defs } });
    } else if (method === 'tools/call') {
        appendFileSync(
            log,
            JSON.stringify({ tool: params.name, args: params.arguments }) +
                '\n',
        );
        const fn = tools[params.name];
        const out = fn ? fn(params.arguments || {}) : { ok: true };
        send({
            id,
            result: { content: [{ type: 'text', text: JSON.stringify(out) }] },
        });
    } else if (id !== undefined) {
        send({ id, result: {} });
    }
});
