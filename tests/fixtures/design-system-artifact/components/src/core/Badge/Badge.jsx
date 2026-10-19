import React from 'react';

export const BADGE_VARIANTS = ['in-review', 'draft', 'approved', 'changes-requested'];
export const BADGE_SIZES = [];

export function Badge({ status, className: extra, children, ...rest }) {
    const classes = ['lp-badge'];
    for (const names of [status]) {
        for (const modifier of (names || '').split(/\s+/).filter(Boolean)) {
            classes.push('lp-badge--' + modifier);
        }
    }
    if (extra) {
        classes.push(extra);
    }
    const className = classes.join(' ');


    return (
        <span {...rest} className={className}>
            {children}
        </span>
    );
}
