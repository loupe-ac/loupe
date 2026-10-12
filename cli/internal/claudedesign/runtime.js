class LoupeView {
    constructor(props) {
        this.props = props || {};
        this.state = {};
        this.handlers = [];
        this._render = null;
        this._links = {};
        this._queued = false;
    }

    setState(patch) {
        const next =
            typeof patch === 'function' ? patch(this.state, this.props) : patch;
        this.state = Object.assign({}, this.state, next);
        if (!this._render || this._queued) {
            return;
        }
        this._queued = true;
        queueMicrotask(() => {
            this._queued = false;
            this._draw();
        });
    }

    static mount(Component, render, props, events, links) {
        const view = new Component(props);
        view._render = render;
        view._links = links || {};
        view._draw();
        (events || []).forEach((name) => {
            document.body.addEventListener(
                name,
                (e) => view._dispatch(e),
                true,
            );
        });
        if (typeof view.componentDidMount === 'function') {
            view.componentDidMount();
        }

        return view;
    }

    static helpers(links) {
        const map = links || {};
        const escape = (value, quotes) => {
            let s = value === null || value === undefined ? '' : String(value);
            s = s
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
            return quotes
                ? s.replace(/"/g, '&quot;').replace(/'/g, '&#39;')
                : s;
        };

        return {
            g(scope, path) {
                let cur = scope;
                for (const key of String(path).split('.')) {
                    if (cur === null || cur === undefined) {
                        return undefined;
                    }
                    cur = cur[key];
                }

                return cur;
            },
            e: (v) => escape(v, false),
            q: (v) => escape(v, true),
            T: (v) => !!v,
            A: (v) => (Array.isArray(v) ? v : []),
            lk(href) {
                const text = String(href);
                const cut = text.search(/[?#]/);
                const base = cut < 0 ? text : text.slice(0, cut);
                if (!Object.prototype.hasOwnProperty.call(map, base)) {
                    return href;
                }

                return map[base] + (cut < 0 ? '' : text.slice(cut));
            },
            raw(v) {
                if (v === null || v === undefined) {
                    return '';
                }

                return typeof v === 'object'
                    ? String(v.__html ?? '')
                    : String(v);
            },
        };
    }

    _draw() {
        const vals = this.renderVals ? this.renderVals() : {};
        this.handlers = [];
        const h = (fn) => {
            this.handlers.push(typeof fn === 'function' ? fn : null);

            return String(this.handlers.length - 1);
        };
        const html = this._render(vals, h, LoupeView.helpers(this._links));
        const template = document.createElement('template');
        template.innerHTML = html;
        const body = document.body;
        const keep = body.querySelector(':scope > #loupe-runtime');
        const olds = Array.from(body.childNodes).filter((n) => n !== keep);
        LoupeView._morph(
            body,
            olds,
            Array.from(template.content.childNodes),
            keep,
        );
    }

    _dispatch(e) {
        const attr = 'data-on-' + e.type;
        let el = e.target;
        while (el && el.nodeType === 1) {
            if (el.hasAttribute(attr)) {
                const fn = this.handlers[Number(el.getAttribute(attr))];
                if (typeof fn === 'function') {
                    try {
                        fn.call(this, e);
                    } catch (err) {
                        console.error(err);
                    }
                }
            }
            if (!e.bubbles || e.cancelBubble || el === document.body) {
                break;
            }
            el = el.parentNode;
        }
    }

    static _morph(parent, olds, news, before) {
        let pool = olds.slice();
        for (const n of news) {
            const k = pool.findIndex((o) => LoupeView._same(o, n));
            if (k < 0) {
                parent.insertBefore(n, pool[0] || before || null);
                continue;
            }
            const o = pool[k];
            const kept = [];
            for (const s of pool.slice(0, k)) {
                if (LoupeView._keyed(s)) {
                    kept.push(s);
                } else {
                    parent.removeChild(s);
                }
            }
            if (kept.length > 0 && o.contains(document.activeElement)) {
                const after = o.nextSibling;
                kept.forEach((s) => parent.insertBefore(s, after));
            } else if (kept.length > 0) {
                parent.insertBefore(o, kept[0]);
            }
            pool = kept.concat(pool.slice(k + 1));
            LoupeView._update(o, n);
        }
        pool.forEach((o) => parent.removeChild(o));
    }

    static _keyed(o) {
        return (
            o.nodeType === 1 && (o.hasAttribute('id') || o.hasAttribute('name'))
        );
    }

    static _same(o, n) {
        if (o.nodeType !== n.nodeType || o.nodeName !== n.nodeName) {
            return false;
        }

        return (
            o.nodeType !== 1 ||
            (o.getAttribute('id') === n.getAttribute('id') &&
                o.getAttribute('name') === n.getAttribute('name'))
        );
    }

    static _update(o, n) {
        if (o.nodeType !== 1) {
            if (o.data !== n.data) {
                o.data = n.data;
            }

            return;
        }
        LoupeView._syncAttributes(o, n);
        if (o.nodeName === 'TEXTAREA') {
            LoupeView._setValue(o, n.value);

            return;
        }
        LoupeView._morph(
            o,
            Array.from(o.childNodes),
            Array.from(n.childNodes),
            null,
        );
        LoupeView._syncProperties(o, n);
    }

    static _syncAttributes(o, n) {
        for (const a of Array.from(o.attributes)) {
            if (!n.hasAttribute(a.name)) {
                o.removeAttribute(a.name);
            }
        }
        for (const a of Array.from(n.attributes)) {
            if (o.getAttribute(a.name) !== a.value) {
                o.setAttribute(a.name, a.value);
            }
        }
    }

    static _syncProperties(o, n) {
        const name = o.nodeName;
        if (name === 'OPTION') {
            if (n.hasAttribute('selected') && !o.selected) {
                o.selected = true;
            }
        } else if (name === 'INPUT') {
            if (o.type === 'checkbox' || o.type === 'radio') {
                if (n.hasAttribute('checked') !== o.checked) {
                    o.checked = n.hasAttribute('checked');
                }
            } else if (n.hasAttribute('value')) {
                LoupeView._setValue(o, n.getAttribute('value'));
            }
        }
    }

    static _setValue(o, value) {
        if (o.value === value) {
            return;
        }
        const focused = o === document.activeElement;
        let start = null;
        let end = null;
        if (focused) {
            try {
                start = o.selectionStart;
                end = o.selectionEnd;
            } catch (err) {
                start = null;
            }
        }
        o.value = value;
        if (focused && start !== null) {
            const max = o.value.length;
            try {
                o.setSelectionRange(Math.min(start, max), Math.min(end, max));
            } catch (err) {
                return;
            }
        }
    }
}
