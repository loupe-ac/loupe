import React from 'react';

export const TAG_VARIANTS = ['neutral', 'lime', 'purple', 'green', 'amber', 'red', 'teal', 'sky', 'blue', 'indigo', 'pink', 'orange'];
export const TAG_SIZES = [];

export function Tag({ tone, as: Element = 'span', className: extra, children, ...rest }) {
    const classes = ['lp-tag'];
    for (const names of [tone]) {
        for (const modifier of (names || '').split(/\s+/).filter(Boolean)) {
            classes.push('lp-tag--' + modifier);
        }
    }
    if (extra) {
        classes.push(extra);
    }
    const className = classes.join(' ');


    return (
        <Element {...rest} className={className}>
            {children}
        </Element>
    );
}
