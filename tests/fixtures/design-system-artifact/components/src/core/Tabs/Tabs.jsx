import React from 'react';

export const TABS_VARIANTS = ['segmented'];
export const TABS_SIZES = [];

export function Tabs({ variant, className: extra, children, ...rest }) {
    const classes = ['lp-tabs'];
    for (const names of [variant]) {
        for (const modifier of (names || '').split(/\s+/).filter(Boolean)) {
            classes.push('lp-tabs--' + modifier);
        }
    }
    if (extra) {
        classes.push(extra);
    }
    const className = classes.join(' ');


    return (
        <nav {...rest} className={className}>
            {children}
        </nav>
    );
}
