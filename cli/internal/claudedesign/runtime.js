class LoupeView {
    constructor(props) {
        this.props = props || {};
        this.state = {};
        this.handlers = [];
        this._render = null;
        this._links = {};
        this._base = null;
        this._blobs = {};
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

    static mount(Component, render, props, events, links, base, blobs) {
        const view = new Component(props);
        view._render = render;
        view._links = links || {};
        view._base = base || null;
        view._blobs = blobs || {};
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

    static helpers(links, base, blobs) {
        const map = links || {};
        const files = blobs || {};
        const rebase = LoupeView.rebaser(base);
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
            bl: (v) =>
                String(v).replace(
                    /(^|[^.\w])\/_blob\/([^ \t\n\f\r"'()<>,;\\?#]*)/g,
                    (m, lead, ref) =>
                        Object.prototype.hasOwnProperty.call(files, ref)
                            ? lead + files[ref]
                            : m,
                ),
            rb: (key, v) => (rebase ? rebase(key, String(v)) : v),
            raw(v) {
                if (v === null || v === undefined) {
                    return '';
                }
                const s =
                    typeof v === 'object' ? String(v.__html ?? '') : String(v);

                return LoupeView.relink(s, this.lk, rebase);
            },
        };
    }

    static relink(markup, lk, rebase) {
        if (!rebase && !/href/i.test(markup)) {
            return markup;
        }
        const template = document.createElement('template');
        template.innerHTML = markup;
        for (const el of template.content.querySelectorAll('*')) {
            for (const a of Array.from(el.attributes)) {
                let val = a.name === 'href' ? lk(a.value) : a.value;
                if (rebase && LoupeView.rebased.includes(a.name)) {
                    val = rebase(a.name, val);
                }
                if (val !== a.value) {
                    el.setAttribute(a.name, val);
                }
            }
            if (rebase && el.nodeName === 'STYLE') {
                el.textContent = rebase('style', el.textContent);
            }
        }

        return template.innerHTML;
    }

    static rebased = ['href', 'src', 'poster', 'action', 'data', 'srcset', 'style'];

    static rebaser(base) {
        if (!base) {
            return null;
        }
        const norm = (p) => {
            const out = [];
            for (const part of p.split('/')) {
                if (part === '..' && out.length > 0 && out[out.length - 1] !== '..') {
                    out.pop();
                } else if (part !== '' && part !== '.') {
                    out.push(part);
                }
            }

            return out;
        };
        const url = (u) => {
            if (u === '' || u[0] === '#' || u[0] === '/') {
                return u;
            }
            const cut = u.search(/[?#]/);
            const head = cut < 0 ? u : u.slice(0, cut);
            if (head === '' || head.includes(':')) {
                return u;
            }
            const target = norm(base.from + '/' + head);
            if (target[0] === '..') {
                return u;
            }
            const dir = norm(base.to);
            let same = 0;
            while (same < dir.length && dir[same] === target[same]) {
                same++;
            }
            const rel = dir
                .slice(same)
                .map(() => '..')
                .concat(target.slice(same))
                .join('/');

            return (rel || '.') + (cut < 0 ? '' : u.slice(cut));
        };
        const space = (c) => ' \t\n\f\r'.includes(c);
        const srcset = (s) => {
            let out = '';
            let i = 0;
            while (i < s.length) {
                let start = i;
                while (i < s.length && (space(s[i]) || s[i] === ',')) {
                    i++;
                }
                out += s.slice(start, i);
                start = i;
                while (i < s.length && !space(s[i])) {
                    i++;
                }
                const u = s.slice(start, i).replace(/,+$/, '');
                out += url(u) + s.slice(start + u.length, i);
                if (u.length < i - start) {
                    continue;
                }
                start = i;
                for (let depth = 0; i < s.length && (s[i] !== ',' || depth > 0); i++) {
                    if (s[i] === '(') {
                        depth++;
                    } else if (s[i] === ')') {
                        depth--;
                    }
                }
                out += s.slice(start, i);
            }

            return out;
        };
        const css = (s) =>
            s.replace(
                /url\(\s*(['"]?)([^'")\s]*)(['"]?)\s*\)/g,
                (m, a, u, b) => 'url(' + a + url(u) + b + ')',
            );

        return (key, v) => {
            if (key === 'srcset') {
                return srcset(v);
            }

            return key === 'style' ? css(v) : url(v);
        };
    }

    _draw() {
        const vals = this.renderVals ? this.renderVals() : {};
        this.handlers = [];
        const h = (fn) => {
            this.handlers.push(typeof fn === 'function' ? fn : null);

            return String(this.handlers.length - 1);
        };
        const html = this._render(vals, h, LoupeView.helpers(this._links, this._base, this._blobs));
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
        const act = olds.find(
            (o) => o !== document.body && o.contains(document.activeElement),
        );
        const mate = act ? LoupeView._mate(act, olds, news) : null;
        for (const n of news) {
            const k =
                n === mate
                    ? pool.indexOf(act)
                    : pool.findIndex(
                          (o) => o !== act && LoupeView._same(o, n),
                      );
            if (k < 0) {
                parent.insertBefore(n, pool[0] || before || null);
                continue;
            }
            const o = pool[k];
            const kept = [];
            for (const s of pool.slice(0, k)) {
                if (s === act || LoupeView._keyed(s)) {
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

    // The new node that keeps the node holding focus: the most alike of its
    // twins, then the one at the same place from the end.
    static _mate(act, olds, news) {
        const all = news.filter((n) => LoupeView._same(act, n));
        if (all.length < 2) {
            return all[0] || null;
        }
        const twins = olds.filter((o) => LoupeView._same(o, act));
        const near = all[all.length - twins.length + twins.indexOf(act)];
        const score = all.map((n) => LoupeView._likeness(act, n));
        const best = Math.max(...score);

        return score[all.indexOf(near)] === best
            ? near
            : all[score.indexOf(best)];
    }

    static _likeness(o, n) {
        let score = 0;
        for (const a of Array.from(o.attributes)) {
            if (n.getAttribute(a.name) === a.value) {
                score++;
            }
        }
        const field = o.nodeName === 'INPUT' || o.nodeName === 'TEXTAREA';
        const value = o.nodeName === 'TEXTAREA' ? n.value : n.getAttribute('value');

        return field && value === o.value ? score + 1 : score;
    }

    static _keyed(o) {
        return (
            o.nodeType === 1 &&
            (LoupeView._key(o) !== null || o.querySelector('[id],[name]') !== null)
        );
    }

    static _key(o) {
        return o.hasAttribute('id') || o.hasAttribute('name')
            ? o.getAttribute('id') + '\u0000' + o.getAttribute('name')
            : null;
    }

    static _inner(o) {
        return Array.from(o.querySelectorAll('[id],[name]'), LoupeView._key);
    }

    static _same(o, n) {
        if (o.nodeType !== n.nodeType || o.nodeName !== n.nodeName) {
            return false;
        }
        if (o.nodeType !== 1) {
            return true;
        }
        const key = LoupeView._key(o);
        if (key !== null || LoupeView._key(n) !== null) {
            return key === LoupeView._key(n);
        }
        const a = LoupeView._inner(o);
        const b = LoupeView._inner(n);

        return a.length === 0 ? b.length === 0 : a.some((k) => b.includes(k));
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
