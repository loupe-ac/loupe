import React from 'react';

export const FORMFIELD_VARIANTS = [];
export const FORMFIELD_SIZES = [];

export function FormField({ className: extra, children, ...rest }) {
    const classes = ['lp-form-field'];
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
