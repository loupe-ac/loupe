(function () {
    var TAG = 'loupe-mount';
    var React = window.React;
    var ReactDOM = window.ReactDOM;
    if (!React || !ReactDOM || !ReactDOM.createRoot) {
        return;
    }

    var RENAMED = {
        class: 'className',
        for: 'htmlFor',
        tabindex: 'tabIndex',
        readonly: 'readOnly',
        maxlength: 'maxLength',
        colspan: 'colSpan',
        rowspan: 'rowSpan',
        autocomplete: 'autoComplete',
        spellcheck: 'spellCheck',
        crossorigin: 'crossOrigin',
        srcset: 'srcSet',
        contenteditable: 'contentEditable',
    };
    var BOOLEAN = {
        disabled: 1,
        checked: 1,
        selected: 1,
        readonly: 1,
        required: 1,
        multiple: 1,
        hidden: 1,
        autofocus: 1,
        open: 1,
        controls: 1,
        loop: 1,
        muted: 1,
        autoplay: 1,
        reversed: 1,
        novalidate: 1,
        inert: 1,
        playsinline: 1,
    };
    var NO_WHITESPACE = {
        TABLE: 1,
        THEAD: 1,
        TBODY: 1,
        TFOOT: 1,
        TR: 1,
        COLGROUP: 1,
        SELECT: 1,
    };

    function lookup(name) {
        var cur = window;
        var parts = name.split('.');
        for (var i = 0; i < parts.length && cur; i++) {
            cur = cur[parts[i]];
        }

        return cur;
    }

    function camel(prop) {
        if (prop.indexOf('--') === 0) {
            return prop;
        }

        return prop
            .replace(/^-ms-/, 'ms-')
            .replace(/-([a-z])/g, function (_, c) {
                return c.toUpperCase();
            });
    }

    function parseStyle(text) {
        var out = {};
        var depth = 0;
        var quote = '';
        var start = 0;
        function add(chunk) {
            var at = chunk.indexOf(':');
            if (at > 0) {
                out[camel(chunk.slice(0, at).trim())] = chunk
                    .slice(at + 1)
                    .trim();
            }
        }
        for (var i = 0; i < text.length; i++) {
            var c = text[i];
            if (quote) {
                if (c === quote) {
                    quote = '';
                }
            } else if (c === '"' || c === "'") {
                quote = c;
            } else if (c === '(') {
                depth++;
            } else if (c === ')') {
                depth--;
            } else if (c === ';' && depth === 0) {
                add(text.slice(start, i));
                start = i + 1;
            }
        }
        add(text.slice(start));

        return out;
    }

    function fixProps(props) {
        if (typeof props.style === 'string') {
            props.style = parseStyle(props.style);
        }

        return props;
    }

    function revive(value) {
        if (Array.isArray(value)) {
            return value.map(revive);
        }
        if (value && typeof value === 'object') {
            if (value.$el) {
                var spec = value.$el;
                var type =
                    typeof spec.type === 'string' && spec.type.indexOf('.') > 0
                        ? lookup(spec.type) || React.Fragment
                        : spec.type;
                var kids = (spec.children || []).map(revive);

                return React.createElement.apply(
                    null,
                    [type, fixProps(revive(spec.props || {}))].concat(kids),
                );
            }
            var out = {};
            Object.keys(value).forEach(function (k) {
                out[k] = revive(value[k]);
            });

            return out;
        }

        return value;
    }

    function children(el) {
        var out = [];
        for (var n = el.firstChild; n; n = n.nextSibling) {
            var kid = build(n);
            if (kid !== null) {
                out.push(kid);
            }
        }

        return out;
    }

    function plainProps(el) {
        var props = {};
        var form = el.tagName === 'INPUT' || el.tagName === 'TEXTAREA';
        Array.prototype.forEach.call(el.attributes, function (a) {
            var name = a.name;
            var value = a.value;
            if (BOOLEAN[name]) {
                value = true;
            }
            if (form && name === 'value') {
                name = 'defaultValue';
            } else if (form && name === 'checked') {
                name = 'defaultChecked';
            } else if (RENAMED[name]) {
                name = RENAMED[name];
            }
            props[name] = value;
        });

        return fixProps(props);
    }

    function build(node) {
        if (node.nodeType === 3) {
            if (
                !node.data.trim() &&
                node.parentNode &&
                NO_WHITESPACE[node.parentNode.tagName]
            ) {
                return null;
            }

            return node.data;
        }
        if (node.nodeType !== 1) {
            return null;
        }
        var kids = children(node);
        if (node.localName === TAG) {
            var name = node.getAttribute('data-component');
            var Component = lookup(name);
            if (!Component) {
                console.warn('Missing component ' + name);
                Component = React.Fragment;
            }
            var raw = node.getAttribute('data-props');
            var props = fixProps(revive(raw ? JSON.parse(raw) : {}));

            return React.createElement.apply(null, [Component, props].concat(kids));
        }
        var tag = node.localName;
        var attrs = plainProps(node);
        if (tag === 'textarea') {
            attrs.defaultValue = node.textContent;

            return React.createElement(tag, attrs);
        }

        return React.createElement.apply(null, [tag, attrs].concat(kids));
    }

    var all = document.getElementsByTagName(TAG);
    var roots = [];
    for (var i = 0; i < all.length; i++) {
        var up = all[i].parentNode;
        var nested = false;
        while (up && up.nodeType === 1) {
            if (up.localName === TAG) {
                nested = true;
                break;
            }
            up = up.parentNode;
        }
        if (!nested) {
            roots.push(all[i]);
        }
    }
    roots.forEach(function (el) {
        try {
            var tree = build(el);
            ReactDOM.createRoot(el).render(tree);
        } catch (e) {
            console.error(e);
        }
    });
})();
