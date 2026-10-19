import React from 'react';

export const AVATAR_VARIANTS = ['accent', 'tint', 'ink', 'danger', 'chrome'];
export const AVATAR_SIZES = ['xs', 'sm', 'md', 'lg'];

export function Avatar({ variant, size, className: extra, children, ...rest }) {
    const classes = ['lp-avatar'];
    for (const names of [variant, size]) {
        for (const modifier of (names || '').split(/\s+/).filter(Boolean)) {
            classes.push('lp-avatar--' + modifier);
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
