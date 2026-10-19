import React from 'react';

export const COPYBUTTON_VARIANTS = ['text', 'icon'];
export const COPYBUTTON_SIZES = [];

export function CopyButton({ variant, className: extra, children, ...rest }) {
    const classes = ['lp-copy-button'];
    for (const names of [variant]) {
        for (const modifier of (names || '').split(/\s+/).filter(Boolean)) {
            classes.push('lp-copy-button--' + modifier);
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
