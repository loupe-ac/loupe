import React from 'react';

export function Tooltip({ interactive = false, className: extra, children, ...rest }) {
    const classes = ['lp-tooltip'];
    if (interactive) {
        classes.push('lp-tooltip--interactive');
    }
    if (extra) {
        classes.push(extra);
    }
    const className = classes.join(' ');
    const target = interactive ? { 'data-interactive-tooltip-target': 'tooltip' } : {};

    return (
        <span role="tooltip" {...target} {...rest} className={className}>
            {children}
        </span>
    );
}
