// canvas-fidelity.js <playwright path> <command> ...
//
//   render   <canvas folder> <out folder> [--index <artboard>] [--runtime <dc-runtime.js>] [--boards a,b]
//   scan     <canvas folder> [--index <artboard>] [--runtime <dc-runtime.js>] [--boards a,b]
//   manifest <folder>
//   verify   <expected folder> <entry page link>
//   shoot    <canvas folder> <out folder> [<entry page link>] [--index <artboard>] [--runtime <dc-runtime.js>] [--boards a,b] [--stored-only]
//
// The canvas folder is the folder the session pushed, with the canvas files at
// the paths the Artifact tool reads. Needs Node 18 or later and Playwright.

const crypto = require('node:crypto');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');

const CANVAS_SUFFIX = '.dc.html';
const NOT_PAGE_FILES = new Set(['support.js', 'canvas.json']);
const DEFAULT_SIZE = { w: 1280, h: 800 };
const TYPES = {
    '.html': 'text/html; charset=utf-8',
    '.css': 'text/css',
    '.js': 'text/javascript',
    '.json': 'application/json',
    '.svg': 'image/svg+xml',
    '.png': 'image/png',
    '.jpg': 'image/jpeg',
    '.jpeg': 'image/jpeg',
    '.gif': 'image/gif',
    '.webp': 'image/webp',
    '.woff': 'font/woff',
    '.woff2': 'font/woff2',
    '.ttf': 'font/ttf',
};

// The three functions below run inside the page. The page gets the source of
// each, so serializeDocument may call findHtmlInSvg and nothing else from outside.

// Lists each element of the HTML namespace that sits inside an <svg> and
// outside a <foreignObject>. A browser draws none of them.
function findHtmlInSvg(doc) {
    const HTML = 'http://www.w3.org/1999/xhtml';
    const found = [];
    for (const svg of doc.querySelectorAll('svg')) {
        for (const el of svg.querySelectorAll('*')) {
            if (el.namespaceURI !== HTML) continue;
            let inForeign = false;
            for (let up = el.parentNode; up && up !== svg; up = up.parentNode) {
                if (up.localName === 'foreignObject') inForeign = true;
            }
            if (!inForeign) found.push(el);
        }
    }

    return found;
}

// Makes the DOM of a live page safe to save as a static page. Changes the
// document in place.
function serializeDocument(doc) {
    for (const select of doc.querySelectorAll('select')) {
        for (const option of select.querySelectorAll('option')) {
            if (option.selected) option.setAttribute('selected', '');
            else option.removeAttribute('selected');
        }
    }
    for (const wrapper of Array.from(doc.querySelectorAll('sc-for, sc-if'))) {
        wrapper.replaceWith(...Array.from(wrapper.childNodes));
    }
    for (const el of findHtmlInSvg(doc)) {
        if (el.localName === 'span') el.replaceWith(doc.createTextNode(el.textContent));
    }
}

// Points each relative link of a page that moves from the folder `from` to the
// folder `to`, so it still reaches the same file. The rule of rebase.go in
// cli/internal/claudedesign. Runs inside the page, so it uses no import.
function rebaseDocument(doc, from, to) {
    if (from === to) return;
    const split = (dir) => (dir === '.' || dir === '' ? [] : dir.split('/'));
    const join = (dir, rel) => {
        const parts = split(dir);
        for (const part of rel.split('/')) {
            if (part === '' || part === '.') continue;
            if (part === '..' && parts.length > 0 && parts[parts.length - 1] !== '..') parts.pop();
            else parts.push(part);
        }

        return parts.join('/');
    };
    const relTo = (dir, target) => {
        const a = split(dir);
        const b = target.split('/');
        let i = 0;
        while (i < a.length && i < b.length && a[i] === b[i]) i++;

        return [...a.slice(i).map(() => '..'), ...b.slice(i)].join('/') || '.';
    };
    const url = (u) => {
        if (u === '' || u.startsWith('#') || u.startsWith('/') || u.includes('{{')) return u;
        const cut = u.search(/[?#]/);
        const base = cut >= 0 ? u.slice(0, cut) : u;
        if (base === '' || base.includes(':')) return u;
        const target = join(from, base);
        if (target === '..' || target.startsWith('../')) return u;

        return relTo(to, target) + (cut >= 0 ? u.slice(cut) : '');
    };
    const css = (s) => s.replace(/url\(\s*(['"]?)([^'")\s]*)(['"]?)\s*\)/g, (_, open, u, close) => `url(${open}${url(u)}${close})`);
    const srcset = (s) => {
        let out = '';
        let i = 0;
        while (i < s.length) {
            let start = i;
            while (i < s.length && /[\s,]/.test(s[i])) i++;
            out += s.slice(start, i);
            start = i;
            while (i < s.length && !/\s/.test(s[i])) i++;
            const u = s.slice(start, i).replace(/,+$/, '');
            out += url(u) + s.slice(start + u.length, i);
            if (u.length < i - start) continue;
            start = i;
            for (let depth = 0; i < s.length && (s[i] !== ',' || depth > 0); i++) {
                if (s[i] === '(') depth++;
                else if (s[i] === ')') depth--;
            }
            out += s.slice(start, i);
        }

        return out;
    };
    const attrs = { href: url, src: url, poster: url, action: url, data: url, srcset, style: css };
    for (const el of doc.querySelectorAll('*')) {
        for (const attr of Array.from(el.attributes)) {
            const rebase = attrs[attr.name];
            if (rebase) el.setAttribute(attr.name, rebase(attr.value));
        }
        if (el.localName === 'style') el.textContent = css(el.textContent);
    }
}

function walk(folder, prefix = '') {
    const out = [];
    for (const entry of fs.readdirSync(path.join(folder, prefix), { withFileTypes: true })) {
        const rel = prefix === '' ? entry.name : `${prefix}/${entry.name}`;
        if (entry.isDirectory()) out.push(...walk(folder, rel));
        else out.push(rel);
    }

    return out.sort();
}

function manifest(folder) {
    return walk(folder).map((rel) => {
        const bytes = fs.readFileSync(path.join(folder, rel));

        return {
            path: rel,
            size: bytes.length,
            sha256: crypto.createHash('sha256').update(bytes).digest('hex'),
        };
    });
}

// The rule of pagePath() in cli/internal/claudedesign/compile.go.
function pagePath(board, isIndex) {
    if (isIndex) return 'index.html';
    const dir = path.posix.dirname(board);

    return path.posix.join(dir, `${path.posix.basename(board, CANVAS_SUFFIX)}.html`);
}

function chooseIndex(boards, name) {
    if (!name) return boards.length === 1 ? 0 : -1;
    const want = path.posix.normalize(name.replace(/\\/g, '/')).replace(/\.dc\.html$/, '');
    let found = boards.map((b, i) => [b, i]).filter(([b]) => b.replace(/\.dc\.html$/, '') === want);
    if (found.length === 0 && !want.includes('/')) {
        found = boards.map((b, i) => [b, i]).filter(([b]) => path.posix.basename(b, CANVAS_SUFFIX) === want);
    }
    if (found.length !== 1) {
        throw new Error(`--index ${name} names ${found.length} artboards. The canvas holds: ${boards.join(', ')}`);
    }

    return found[0][1];
}

function boardSizes(folder, files) {
    const layout = files.find((f) => path.posix.basename(f) === 'canvas.json');
    if (!layout) return () => DEFAULT_SIZE;
    const dir = path.posix.dirname(layout);
    const boards = JSON.parse(fs.readFileSync(path.join(folder, layout), 'utf8')).boards ?? {};

    return (board) => {
        const entry = boards[path.posix.relative(dir, board)];

        return entry && entry.w > 0 && entry.h > 0 ? { w: entry.w, h: entry.h } : DEFAULT_SIZE;
    };
}

// One entry for each picked artboard: where the push stores its page, and the
// size it draws at. Throws when the output path of an artboard is also the
// path of a file that is not a canvas file.
function pageMap(folder, { index = '', boards: picked = null } = {}) {
    const files = walk(folder);
    const all = files.filter((f) => f.endsWith(CANVAS_SUFFIX));
    const indexAt = chooseIndex(all, index);
    const sizeOf = boardSizes(folder, files);
    const companions = new Set(files.filter((f) => !f.endsWith(CANVAS_SUFFIX) && !NOT_PAGE_FILES.has(path.posix.basename(f))));
    const map = [];
    all.forEach((board, i) => {
        const out = pagePath(board, i === indexAt);
        if (companions.has(out)) {
            throw new Error(`Artboard ${board} would be stored as ${out}, and the canvas folder already holds a file ${out}. Rename one of them on the canvas.`);
        }
        map.push({ board, out, ...sizeOf(board) });
    });
    if (!picked) return map;
    const missing = picked.filter((p) => !map.some((m) => m.board === p || m.board === p + CANVAS_SUFFIX));

    if (missing.length > 0) throw new Error(`No artboard named ${missing.join(', ')}. The canvas holds: ${all.join(', ')}`);

    return map.filter((m) => picked.some((p) => m.board === p || m.board === p + CANVAS_SUFFIX));
}

// The link of a stored page, from the link of the entry page. One signature
// covers every file of a version, so only the last segment changes.
function storedLink(entryLink, outPath) {
    if (!/^https?:\/\//.test(entryLink)) {
        throw new Error(`The stored page link must be an absolute https:// or http:// URL, and ${entryLink} is not. A browser cannot open a bare path.`);
    }
    if (!entryLink.endsWith('/index.html')) throw new Error(`The entry page link must end in /index.html, and ${entryLink} does not.`);

    return entryLink.slice(0, -'index.html'.length) + outPath.split('/').map(encodeURIComponent).join('/');
}

// Fetches each file of the expected folder from the stored version and compares
// its SHA-256 hash, binary files included. Never prints the link, which is a
// credential.
async function verify(folder, entryLink) {
    const problems = [];
    for (const row of manifest(folder)) {
        const response = await fetch(storedLink(entryLink, row.path));
        if (!response.ok) {
            problems.push(`${row.path}: the stored version answered ${response.status}`);
            continue;
        }
        const bytes = Buffer.from(await response.arrayBuffer());
        const sha256 = crypto.createHash('sha256').update(bytes).digest('hex');
        if (sha256 !== row.sha256) problems.push(`${row.path}: stored ${sha256} ${bytes.length}, expected ${row.sha256} ${row.size}`);
    }

    return problems;
}

function serve(folder, runtime) {
    const root = path.resolve(folder);
    const server = http.createServer((req, res) => {
        const rel = decodeURIComponent(new URL(req.url, 'http://x').pathname).replace(/^\/+/, '');
        let file = path.resolve(root, rel);
        if (runtime && path.posix.basename(rel) === 'support.js') file = path.resolve(runtime);
        else if (file !== root && !file.startsWith(root + path.sep)) {
            res.writeHead(403).end();

            return;
        }
        fs.readFile(file, (err, bytes) => {
            if (err) {
                res.writeHead(404).end();

                return;
            }
            res.writeHead(200, { 'content-type': TYPES[path.extname(file).toLowerCase()] ?? 'application/octet-stream' }).end(bytes);
        });
    });

    return new Promise((resolve) => {
        server.listen(0, '127.0.0.1', () => resolve({ server, origin: `http://127.0.0.1:${server.address().port}` }));
    });
}

async function openLive(browser, origin, entry) {
    const page = await browser.newPage({ viewport: { width: entry.w, height: entry.h } });
    await page.goto(`${origin}/${entry.board.split('/').map(encodeURIComponent).join('/')}`, { waitUntil: 'networkidle' });
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(500);

    return page;
}

async function withLive(playwrightPath, folder, options, run) {
    const { chromium } = require(playwrightPath);
    const map = pageMap(folder, options);
    const { server, origin } = await serve(folder, options.runtime);
    const browser = await chromium.launch();
    try {
        return await run({ browser, origin, map });
    } finally {
        await browser.close();
        server.close();
    }
}

function nameOf(entry) {
    return entry.out.replace(/\.html$/, '').replace(/\//g, '__');
}

async function render(playwrightPath, folder, outFolder, options) {
    await withLive(playwrightPath, folder, options, async ({ browser, origin, map }) => {
        for (const file of walk(folder)) {
            if (file.endsWith(CANVAS_SUFFIX) || NOT_PAGE_FILES.has(path.posix.basename(file))) continue;
            fs.mkdirSync(path.join(outFolder, path.dirname(file)), { recursive: true });
            fs.copyFileSync(path.join(folder, file), path.join(outFolder, file));
        }
        for (const entry of map) {
            const page = await openLive(browser, origin, entry);
            const html = await page.evaluate(
                `(() => { ${findHtmlInSvg.toString()}\n(${serializeDocument.toString()})(document); (${rebaseDocument.toString()})(document, ${JSON.stringify(path.posix.dirname(entry.board))}, ${JSON.stringify(path.posix.dirname(entry.out))}); document.querySelectorAll('script').forEach((s) => s.remove()); return '<!doctype html>\\n' + document.documentElement.outerHTML; })()`,
            );
            const target = path.join(outFolder, entry.out);
            fs.mkdirSync(path.dirname(target), { recursive: true });
            fs.writeFileSync(target, html);
            await page.close();
            console.log(`${entry.board} -> ${entry.out}`);
        }
    });
}

// Lists each {{value}} that the source of a board puts in the text of an SVG
// <text>, <tspan> or <textPath>. The runtime wraps it in a <span> that a browser
// does not draw. Reads the source, so it also finds one in a branch that the
// first render of the board leaves out, such as a chart type the board starts without.
function findTemplateInSvgText(source) {
    const found = [];
    for (const svg of source.match(/<svg\b[\s\S]*?<\/svg>/gi) ?? []) {
        for (const hit of svg.matchAll(/<(text|tspan|textPath)\b[^>]*>([^<]*\{\{[^<]*)/gi)) {
            found.push({ tag: hit[1], text: hit[2].trim().slice(0, 60) });
        }
    }

    return found;
}

async function scan(playwrightPath, folder, options) {
    let count = 0;
    for (const entry of pageMap(folder, options)) {
        for (const hit of findTemplateInSvgText(fs.readFileSync(path.join(folder, entry.board), 'utf8'))) {
            console.log(`${entry.board}: "${hit.text}" in an SVG <${hit.tag}> of the source`);
            count++;
        }
    }
    await withLive(playwrightPath, folder, options, async ({ browser, origin, map }) => {
        for (const entry of map) {
            const page = await openLive(browser, origin, entry);
            const hits = await page.evaluate(
                `(() => (${findHtmlInSvg.toString()})(document).map((el) => ({ tag: el.localName, text: (el.textContent || '').trim().slice(0, 60) })))()`,
            );
            for (const hit of hits) console.log(`${entry.board}: <${hit.tag}> inside an <svg>, near "${hit.text}"`);
            count += hits.length;
            await page.close();
        }
    });
    console.log(count === 0 ? 'pass: no HTML or {{value}} inside an <svg>' : `fail: ${count} place(s) with HTML or a {{value}} inside an <svg>`);

    return count === 0 ? 0 : 1;
}

async function shoot(playwrightPath, folder, outFolder, entryLink, options) {
    // Check the map and the link before any browser starts.
    const map = pageMap(folder, options);
    const links = entryLink ? map.map((entry) => storedLink(entryLink, entry.out)) : [];
    fs.mkdirSync(outFolder, { recursive: true });
    await withLive(playwrightPath, folder, options, async ({ browser, origin }) => {
        for (const [i, entry] of map.entries()) {
            if (!options.storedOnly) {
                const page = await openLive(browser, origin, entry);
                await page.screenshot({ path: path.join(outFolder, `${nameOf(entry)}-canvas.png`) });
                await page.close();
            }
            if (links[i]) {
                const page = await browser.newPage({ viewport: { width: entry.w, height: entry.h } });
                const response = await page.goto(links[i], { waitUntil: 'networkidle' });
                if (!response || !response.ok()) console.log(`${entry.board}: the stored page ${entry.out} answered ${response ? response.status() : 'nothing'}`);
                await page.evaluate(() => document.fonts.ready);
                await page.waitForTimeout(500);
                await page.screenshot({ path: path.join(outFolder, `${nameOf(entry)}-stored.png`) });
                await page.close();
            }
            console.log(`${entry.board}: ${nameOf(entry)}`);
        }
    });
}

function parse(args) {
    const positional = [];
    const options = { index: '', boards: null, runtime: '', storedOnly: false };
    for (let i = 0; i < args.length; i++) {
        if (args[i] === '--index') options.index = args[++i];
        else if (args[i] === '--runtime') options.runtime = args[++i];
        else if (args[i] === '--boards') options.boards = args[++i].split(',').filter(Boolean);
        else if (args[i] === '--stored-only') options.storedOnly = true;
        else positional.push(args[i]);
    }

    return { positional, options };
}

async function main(argv) {
    const [playwrightPath, command, ...rest] = argv;
    const { positional, options } = parse(rest);
    if (command === 'manifest') {
        for (const row of manifest(positional[0])) console.log(`${row.sha256}  ${row.size}  ${row.path}`);

        return 0;
    }
    if (command === 'verify') {
        const problems = await verify(positional[0], positional[1]);
        for (const line of problems) console.log(line);
        console.log(problems.length === 0 ? 'pass: each stored file has the expected hash' : `fail: ${problems.length} file(s) differ`);

        return problems.length === 0 ? 0 : 1;
    }
    if (command === 'render') await render(playwrightPath, positional[0], positional[1], options);
    else if (command === 'scan') return scan(playwrightPath, positional[0], options);
    else if (command === 'shoot') await shoot(playwrightPath, positional[0], positional[1], positional[2], options);
    else throw new Error('Commands: render, scan, manifest, verify, shoot');

    return 0;
}

module.exports = { findHtmlInSvg, findTemplateInSvgText, serializeDocument, rebaseDocument, manifest, pageMap, pagePath, serve, storedLink, verify };

if (require.main === module) {
    main(process.argv.slice(2)).then(
        (code) => process.exit(code),
        (error) => {
            console.error(error.message);
            process.exit(2);
        },
    );
}
