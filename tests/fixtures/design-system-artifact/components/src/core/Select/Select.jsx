import React from 'react';

export const SELECT_VARIANTS = [];
export const SELECT_SIZES = [];

export function Select({ className: extra, children, ...rest }) {
    const classes = ['lp-input', 'lp-select'];
    if (extra) {
        classes.push(extra);
    }
    const className = classes.join(' ');


    return (
        <select {...rest} className={className}>
            {children}
        </select>
    );
}
