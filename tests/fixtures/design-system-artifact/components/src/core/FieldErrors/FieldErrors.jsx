import React from 'react';

export const FIELDERRORS_VARIANTS = [];
export const FIELDERRORS_SIZES = [];

export function FieldErrors({ as: Element = 'div', className: extra, children, ...rest }) {
    const classes = ['lp-field-errors'];
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
