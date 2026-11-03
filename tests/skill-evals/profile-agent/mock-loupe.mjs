// A stdio MCP server with one tool, workflow_get. It answers with the mock of the
// foreign-profile-agent case, and logs each call, so the run reaches no real project.
import { appendFileSync, readFileSync } from 'node:fs';
import readline from 'node:readline';

const [bodyPath, logPath] = process.argv.slice(2);
const answer = (id, result) => process.stdout.write(`${JSON.stringify({ jsonrpc: '2.0', id, result })}\n`);
const tool = {
    name: 'workflow_get',
    description: 'Read the workflow of the Loupe project: the template key, the columns and the epicBranch pattern.',
    inputSchema: { type: 'object', properties: {} },
};

readline.createInterface({ input: process.stdin }).on('line', (line) => {
    const message = JSON.parse(line);
    if (undefined === message.id) {
        return;
    }
    switch (message.method) {
        case 'initialize':
            answer(message.id, {
                protocolVersion: message.params.protocolVersion,
                capabilities: { tools: {} },
                serverInfo: { name: 'loupe', version: '0' },
            });
            break;
        case 'tools/list':
            answer(message.id, { tools: [tool] });
            break;
        case 'tools/call':
            appendFileSync(logPath, `${message.params.name}\n`);
            answer(message.id, { content: [{ type: 'text', text: readFileSync(bodyPath, 'utf8') }] });
            break;
        default:
            answer(message.id, {});
    }
});
