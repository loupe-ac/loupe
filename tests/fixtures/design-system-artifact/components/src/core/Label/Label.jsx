import React from 'react';

export const LABEL_VARIANTS = [];
export const LABEL_SIZES = [];

export function Label({ as: Element = 'label', className: extra, children, ...rest }) {
    const classes = ['lp-label'];
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
