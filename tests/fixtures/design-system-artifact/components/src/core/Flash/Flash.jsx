import React from 'react';

export const FLASH_SEVERITIES = ['success', 'error', 'warning', 'info'];

export function Flash({ severity = 'info', dismissLabel, dot = true, className: extra, children, ...rest }) {
    const classes = ['lp-flash', 'lp-flash--' + severity];
    if (extra) {
        classes.push(extra);
    }
    const className = classes.join(' ');

    return (
        <div role="status" data-controller="flash" {...rest} className={className}>
            {dot && <span className="lp-flash__dot"></span>}
            <span className="lp-flash__message">{children}</span>
            {dismissLabel && <button type="button" className="lp-flash__dismiss" data-action="click->flash#dismiss">{dismissLabel}</button>}
        </div>
    );
}
