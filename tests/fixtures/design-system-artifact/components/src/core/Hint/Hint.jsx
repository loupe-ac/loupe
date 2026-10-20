import React from 'react';

export const HINT_VARIANTS = [];
export const HINT_SIZES = [];

export function Hint({ as: Element = 'p', className: extra, children, ...rest }) {
    const classes = ['lp-form-hint'];
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
