import React from 'react';

export const MENU_VARIANTS = ['light', 'chrome'];
export const MENU_SIZES = ['sm', 'md', 'lg'];

export function Menu({ variant, size, className: extra, children, ...rest }) {
    const classes = ['lp-menu'];
    for (const names of [variant, size]) {
        for (const modifier of (names || '').split(/\s+/).filter(Boolean)) {
            classes.push('lp-menu--' + modifier);
        }
    }
    if (extra) {
        classes.push(extra);
    }
    const className = classes.join(' ');


    return (
        <div {...rest} className={className}>
            {children}
        </div>
    );
}
