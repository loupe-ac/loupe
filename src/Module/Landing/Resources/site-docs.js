(function () {
    var config = loupeSiteDocs;
    var match = document.cookie.match(/(?:^|; )loupe_signed_in=([^;]*)/);
    var signal = match ? match[1] : '';
    var signedIn = signal === '1' || signal === 'admin';

    function load(src, attributes) {
        var script = document.createElement('script');
        script.src = src;
        script.defer = true;
        Object.keys(attributes).forEach(function (name) {
            if (attributes[name]) {
                script.setAttribute(name, attributes[name]);
            }
        });
        document.head.appendChild(script);
    }

    function chromeRoots() {
        var roots = [document];
        document.querySelectorAll('*').forEach(function (element) {
            if (element.shadowRoot) {
                roots.push(element.shadowRoot);
            }
        });

        return roots;
    }

    function swap() {
        chromeRoots().forEach(function (root) {
            root.querySelectorAll('[data-site-chrome="go-to-app"]').forEach(function (element) {
                element.hidden = !signedIn;
                if (signedIn) {
                    element.href = config.appOrigin + '/';
                }
            });
            root.querySelectorAll('[data-site-chrome="signed-out"]').forEach(function (element) {
                element.hidden = signedIn;
            });
        });
    }

    var EVENT_ATTRIBUTE = 'data-umami-event';
    var SETTLE_LIMIT = 1000;

    // Umami's click listener sits on the document, where a click inside a shadow
    // root arrives with the host as its target and finds no attribute.
    function countClick(event) {
        var tracker = globalThis.umami;
        var element = event.target && event.target.closest ? event.target.closest('[' + EVENT_ATTRIBUTE + ']') : null;
        if (!element || !tracker || typeof tracker.track !== 'function') {
            return;
        }

        var data = {};
        Array.prototype.forEach.call(element.attributes, function (attribute) {
            if (attribute.name.indexOf(EVENT_ATTRIBUTE + '-') === 0) {
                data[attribute.name.slice(EVENT_ATTRIBUTE.length + 1)] = attribute.value;
            }
        });

        var sameTab =
            element.tagName === 'A' &&
            element.href &&
            (!element.target || element.target === '_self') &&
            !event.ctrlKey &&
            !event.shiftKey &&
            !event.metaKey &&
            !event.button;
        if (sameTab) {
            event.preventDefault();
        }

        var settled;
        try {
            settled = Promise.resolve(tracker.track(element.getAttribute(EVENT_ATTRIBUTE), data));
        } catch (error) {
            settled = Promise.resolve();
        }
        if (sameTab) {
            var limit = new Promise(function (resolve) {
                setTimeout(resolve, SETTLE_LIMIT);
            });
            Promise.race([settled.catch(function () {}), limit]).then(function () {
                globalThis.location.href = element.href;
            });
        }
    }

    function listen() {
        chromeRoots().forEach(function (root) {
            if (root !== document) {
                root.addEventListener('click', countClick);
            }
        });
    }

    function start() {
        swap();
        listen();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }

    if (config.umami && !signedIn) {
        load(config.umami.src, { 'data-website-id': config.umami.websiteId, 'data-do-not-track': 'true' });
    }
    if (config.widget && (config.widget.public || signal === 'admin')) {
        load(config.widget.backend + '/live-review/widget.js', {
            'data-project': config.widget.project,
            'data-context': config.widget.context,
        });
    }
})();
