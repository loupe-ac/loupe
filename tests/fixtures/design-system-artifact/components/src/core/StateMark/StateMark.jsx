import React from 'react';

const GLYPHS = {
    stuck: (
        <g fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2">
            <circle cx="12" cy="12" r="10" />
            <path d="M12 8v4m0 4h.01" />
        </g>
    ),
    'needs-you': (
        <g fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2">
            <circle cx="12" cy="12" r="10" />
            <circle cx="12" cy="10" r="3" />
            <path d="M7 20.662V19a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v1.662" />
        </g>
    ),
    working: <path fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 12a9 9 0 1 1-6.219-8.56" />,
    waiting: <path fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 22h14M5 2h14m-2 20v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2" />,
    chip: <path fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10.1 2.182a10 10 0 0 1 3.8 0m0 19.636a10 10 0 0 1-3.8 0m7.509-18.097a10 10 0 0 1 2.69 2.7M2.182 13.9a10 10 0 0 1 0-3.8m18.097 7.509a10 10 0 0 1-2.7 2.69M21.818 10.1a10 10 0 0 1 0 3.8M3.721 6.391a10 10 0 0 1 2.7-2.69m-.03 16.578a10 10 0 0 1-2.69-2.7" />,
};

const CLOSE_DELAY = 150;
const GAP = 8;
const MARGIN = 8;
const OVERHANG = 4;

const focusVisible = (element) => {
    try {
        return element.matches(':focus-visible');
    } catch {
        return true;
    }
};

// The app opens this tooltip with a Stimulus controller, which Claude Design does not load.
function attachTooltip(root, tooltip) {
    let closeTimer = null;
    let returningFocus = false;
    const cancelClose = () => {
        clearTimeout(closeTimer);
        closeTimer = null;
    };
    const place = () => {
        tooltip.style.left = '0px';
        tooltip.style.top = '0px';
        const origin = tooltip.getBoundingClientRect();
        const anchor = root.getBoundingClientRect();
        const left = Math.max(MARGIN, Math.min(anchor.right + OVERHANG - origin.width, window.innerWidth - origin.width - MARGIN));
        let top = anchor.bottom + GAP;
        const above = anchor.top - GAP - origin.height;
        if (top + origin.height > window.innerHeight - MARGIN && above >= MARGIN) {
            top = above;
        }
        top = Math.max(MARGIN, Math.min(top, window.innerHeight - origin.height - MARGIN));
        tooltip.style.left = left - origin.left + 'px';
        tooltip.style.top = top - origin.top + 'px';
    };
    const onOutsidePress = (event) => {
        if (!root.contains(event.target)) {
            close();
        }
    };
    const close = () => {
        cancelClose();
        window.removeEventListener('scroll', close, true);
        document.removeEventListener('pointerdown', onOutsidePress, true);
        tooltip.removeAttribute('data-open');
    };
    const open = (event) => {
        if (returningFocus) {
            return;
        }
        if (event.type === 'focusin' && !focusVisible(event.target)) {
            return;
        }
        cancelClose();
        if (tooltip.hasAttribute('data-open')) {
            return;
        }
        place();
        tooltip.setAttribute('data-open', '');
        window.addEventListener('scroll', close, true);
        document.addEventListener('pointerdown', onOutsidePress, true);
    };
    const scheduleClose = (event) => {
        if (event.pointerType === 'touch') {
            return;
        }
        cancelClose();
        closeTimer = setTimeout(() => {
            const active = document.activeElement;
            if (!root.contains(active) || !focusVisible(active)) {
                close();
            }
        }, CLOSE_DELAY);
    };
    const leaveFocus = (event) => {
        if (!root.contains(event.relatedTarget)) {
            close();
        }
    };
    const onKeydown = (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        const inside = tooltip.contains(document.activeElement);
        close();
        if (inside) {
            returningFocus = true;
            root.querySelector('[tabindex]')?.focus();
            returningFocus = false;
        }
    };
    const onPress = (event) => {
        if (event.pointerType === 'mouse' && !tooltip.contains(event.target)) {
            close();
        }
    };
    const listeners = [['pointerenter', open], ['pointerleave', scheduleClose], ['focusin', open], ['focusout', leaveFocus], ['keydown', onKeydown], ['pointerdown', onPress]];
    listeners.forEach(([type, listener]) => root.addEventListener(type, listener));

    return () => {
        close();
        listeners.forEach(([type, listener]) => root.removeEventListener(type, listener));
    };
}

export function StateMark({ kind, label, id, className: extra, children, ...rest }) {
    const generated = React.useId();
    const tooltipId = id ?? 'state-mark-' + generated;
    const rootRef = React.useRef(null);
    const tooltipRef = React.useRef(null);
    React.useEffect(() => attachTooltip(rootRef.current, tooltipRef.current), []);
    const classes = ['lp-state-mark', 'lp-state-mark--' + kind];
    if (extra) {
        classes.push(extra);
    }

    return (
        <span {...rest} ref={rootRef} className={classes.join(' ')}>
            <span className="lp-state-mark__icon" tabIndex={0} role="img" aria-label={label} aria-describedby={tooltipId}>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="1em" height="1em" className="lp-state-mark__glyph" aria-hidden="true">
                    {GLYPHS[kind]}
                </svg>
            </span>
            <span role="tooltip" id={tooltipId} ref={tooltipRef} className="lp-tooltip lp-tooltip--interactive">{children}</span>
        </span>
    );
}
