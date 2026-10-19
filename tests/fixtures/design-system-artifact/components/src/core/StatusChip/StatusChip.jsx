import React from 'react';

export function StatusChip({ modifier, label, dot = true, as: Element = 'span', reason, id, className: extra, children, ...rest }) {
    const generated = React.useId();
    const tooltipId = id ?? 'status-chip-' + generated;
    const classes = ['lp-status-chip'];
    if (modifier) {
        classes.push('lp-status-chip--' + modifier);
    }
    if (reason) {
        classes.push('lp-status-chip--reason');
    }
    if (extra) {
        classes.push(extra);
    }
    const className = classes.join(' ');
    const reasonAttributes = reason ? { tabIndex: 0, 'aria-describedby': tooltipId } : {};

    return (
        <Element {...reasonAttributes} {...rest} className={className}>
            {dot && <span className="lp-status-chip__dot"></span>}
            {label ?? children}
            {reason && (
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="1em" height="1em" className="lp-status-chip__help" aria-hidden="true">
                    <g fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2">
                        <circle cx="12" cy="12" r="10" />
                        <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3m.08 4h.01" />
                    </g>
                </svg>
            )}
            {reason && <span role="tooltip" id={tooltipId} className="lp-tooltip">{reason}</span>}
        </Element>
    );
}
