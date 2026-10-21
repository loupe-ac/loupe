/**
 * @vitest-environment jsdom
 * @vitest-environment-options {"url": "https://app.test/forgot-password/reset/SECRET123?x=1"}
 */
import { readFileSync } from 'node:fs';
import { beforeAll, expect, it } from 'vitest';
import { initSentry } from '../../assets/lib/sentry.js';

const BUNDLE = readFileSync('assets/sentry/bundle.tracing.min.js', 'utf8');
const REPLAY = readFileSync('assets/sentry/replay.min.js', 'utf8');

beforeAll(() => {
    // jsdom has no Performance Timeline API, which the SDK reads at start.
    performance.getEntriesByType = () => [];
    performance.getEntriesByName = () => [];
    performance.mark = () => {};
    performance.measure = () => {};
    (0, eval)(BUNDLE);
    (0, eval)(REPLAY);

    for (const [name, content] of Object.entries({
        'loupe-route': 'app_reset_password',
        'sentry-browser-dsn': 'https://abc@o1.ingest.sentry.io/2',
        'sentry-browser-traces-sample-rate': '1',
        'sentry-environment': 'prod',
    })) {
        const meta = document.createElement('meta');
        meta.name = name;
        meta.content = content;
        document.head.append(meta);
    }
});

it('adds the replay integration to the SDK object', () => {
    expect(typeof window.Sentry.replayIntegration).toBe('function');
    expect(typeof window.Sentry.makeFetchTransport).toBe('function');
});

it('sends no part of the page path or query with the real SDK', async () => {
    const Sentry = window.Sentry;
    expect(typeof Sentry.createTransport).toBe('function');
    expect(window.location.pathname).toContain('SECRET123');

    const bodies = [];
    const transport = (options) =>
        Sentry.createTransport(options, (request) => {
            bodies.push(
                typeof request.body === 'string'
                    ? request.body
                    : new TextDecoder().decode(request.body),
            );
            return Promise.resolve({ statusCode: 200 });
        });

    expect(
        initSentry({
            ...Sentry,
            init: (options) => Sentry.init({ ...options, transport }),
        }),
    ).toBe(true);

    Sentry.captureException(new Error('boom'));
    Sentry.getActiveSpan()?.end();
    await Sentry.flush(2000);

    const sent = bodies.join('\n');
    expect(sent).toContain('"type":"event"');
    expect(sent).toContain('"type":"transaction"');
    expect(sent).toContain('app_reset_password');
    expect(sent).not.toContain('SECRET123');
    expect(sent).not.toContain('?x=1');
    expect(sent).not.toContain('/forgot-password');
});

it('vendors the replay add-on at the SDK version of the tracing bundle', () => {
    const version = (file) =>
        readFileSync(file, 'utf8')
            .split('\n', 1)[0]
            .match(/\b(\d+\.\d+\.\d+)\b/)?.[1];

    const tracing = version('assets/sentry/bundle.tracing.min.js');
    expect(tracing).toMatch(/^\d+\.\d+\.\d+$/);
    expect(version('assets/sentry/replay.min.js')).toBe(tracing);
});
