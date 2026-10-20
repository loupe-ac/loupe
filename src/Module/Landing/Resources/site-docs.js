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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', swap);
    } else {
        swap();
    }

    if (config.umami && !signedIn) {
        load(config.umami.src, { 'data-website-id': config.umami.websiteId, 'data-do-not-track': 'true' });
    }
    if (config.widget && (config.widget.public || signal === 'admin')) {
        load(config.widget.backend + '/site-review/widget.js', {
            'data-project': config.widget.project,
            'data-context': config.widget.context,
        });
    }
})();
