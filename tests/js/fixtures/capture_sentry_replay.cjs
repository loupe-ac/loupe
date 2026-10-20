// Records one real replay envelope from SDK 11.4.0 in Chromium, so the scrub
// tests run against the real payload shape. Run it again at each SDK upgrade:
//   node tests/js/fixtures/capture_sentry_replay.cjs
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const { chromium } = require(path.join(root, 'e2e/node_modules/playwright'));

const MARKERS = {
    urlPath: 'MARKERURLPATH',
    urlQuery: 'MARKERURLQUERY',
    pushPath: 'MARKERPUSHPATH',
    linkPath: 'MARKERLINKPATH',
    hiddenValue: 'MARKERHIDDENTOKEN',
    dataValue: 'MARKERDATAVALUE',
    ariaLabel: 'MARKERARIALABEL',
    title: 'MARKERTITLE',
    imagePath: 'MARKERIMAGEPATH',
    headingId: 'markerheadingid',
    text: 'MARKERVISIBLETEXT',
    changedLink: 'MARKERCHANGEDLINK',
    quoted: 'MARKERQUOTED',
    comment: 'MARKERCOMMENT',
    cssUrlPath: 'MARKERCSSURLPATH',
    customText: 'MARKERCUSTOMTEXT',
    fontText: 'MARKERFONTTEXT',
    accountId: '987654321',
    tabSize: '987654322',
    tabSizeRounded: '9.87654e+08',
    ruleQuoted: 'MARKERRULEQUOTED',
    ruleCustom: 'MARKERRULECUSTOM',
};

const CSS_BLOCK = (suffix) => `
    content: "${MARKERS.quoted}${suffix}";
    /* ${MARKERS.comment}${suffix} */
    background: url(/${MARKERS.cssUrlPath}${suffix}/x.png);
    --private-note: ${MARKERS.customText}${suffix};
    font-family: ${MARKERS.fontText}${suffix};
    --account-id: ${MARKERS.accountId};
    tab-size: ${MARKERS.tabSize};
    top: 120px;
`;

const PAGE = `<!doctype html>
<html><head><title>${MARKERS.title}</title>
<link rel="stylesheet" href="/assets/app-abc123.css">
<style id="inline-style">.styled { ${CSS_BLOCK('S')} }</style>
</head><body>
<h1 id="${MARKERS.headingId}">${MARKERS.text}</h1>
<a id="link" href="/${MARKERS.linkPath}/9?x=1" aria-label="${MARKERS.ariaLabel}" title="${MARKERS.title}">${MARKERS.text}</a>
<form><input type="hidden" name="_token" value="${MARKERS.hiddenValue}"></form>
<div class="styled" data-controller="x" data-x-topic-value='{"title":"${MARKERS.dataValue}"}' style='${CSS_BLOCK('A').replace(/\n/g, ' ')}'>${MARKERS.text}</div>
<img src="/${MARKERS.imagePath}/pic.png" alt="${MARKERS.text}">
<button id="go" class="button" aria-label="${MARKERS.ariaLabel}">${MARKERS.text}</button>
<script src="/sentry/bundle.js"></script>
<script src="/sentry/replay.js"></script>
<script>
Sentry.init({
    dsn: 'https://abc@o1.ingest.sentry.io/2',
    tracesSampleRate: 0,
    replaysSessionSampleRate: 1,
    replaysOnErrorSampleRate: 0,
    integrations: [Sentry.replayIntegration({
        maskAllText: true, maskAllInputs: true, blockAllMedia: true, useCompression: false,
    })],
});
</script>
</body></html>`;

const BUILT_CSS =
    ':root { --spacing: 0.25rem; --accent: #d4e94c; } .button { padding: calc(var(--spacing) * 2); }';
const PNG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
    'base64',
);

const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://127.0.0.1');
    const send = (type, body) => {
        response.writeHead(200, { 'content-type': type });
        response.end(body);
    };
    if (url.pathname === '/sentry/bundle.js') {
        return send(
            'text/javascript',
            fs.readFileSync(
                path.join(root, 'assets/sentry/bundle.tracing.min.js'),
            ),
        );
    }
    if (url.pathname === '/sentry/replay.js') {
        return send(
            'text/javascript',
            fs.readFileSync(path.join(root, 'assets/sentry/replay.min.js')),
        );
    }
    if (url.pathname.startsWith('/assets/')) {
        return send('text/css', BUILT_CSS);
    }
    if (url.pathname.endsWith('.png')) {
        return send('image/png', PNG);
    }
    return send('text/html', PAGE);
});

(async () => {
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const origin = `http://127.0.0.1:${server.address().port}`;
    const browser = await chromium.launch();
    const context = await browser.newContext({
        locale: 'en-US',
        timezoneId: 'UTC',
    });
    const page = await context.newPage();
    const bodies = [];
    await page.route(/\/api\/2\/envelope\//, (route) => {
        bodies.push(route.request().postData());
        route.fulfill({ status: 200, body: '{}' });
    });
    await page.goto(
        `${origin}/reset/${MARKERS.urlPath}?token=${MARKERS.urlQuery}`,
    );
    await page.click('#go');
    await page.evaluate((markers) => {
        history.pushState({}, '', `/${markers.pushPath}/1`);
        document
            .getElementById('link')
            .setAttribute('href', `/${markers.changedLink}`);
        document.styleSheets[1].insertRule(
            `.late { content: "${markers.ruleQuoted}"; --rule-note: ${markers.ruleCustom}; }`,
        );
    }, MARKERS);
    // The SDK flushes the first segment five seconds after it starts.
    await page.waitForTimeout(7000);
    await browser.close();
    server.close();

    const replays = bodies.filter((body) =>
        body?.includes('"type":"replay_recording"'),
    );
    if (replays.length === 0) {
        throw new Error('No replay envelope was captured');
    }
    const first = replays[0];
    fs.writeFileSync(path.join(__dirname, 'sentry_replay_envelope.txt'), first);
    fs.writeFileSync(
        path.join(__dirname, 'sentry_replay_markers.json'),
        JSON.stringify({ origin, markers: MARKERS }, null, 4) + '\n',
    );
    console.log(
        `Captured ${replays.length} replay envelope(s); wrote the first, ${first.length} bytes`,
    );
})();
