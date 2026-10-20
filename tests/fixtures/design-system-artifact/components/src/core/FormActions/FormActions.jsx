import React from 'react';

export const FORMACTIONS_VARIANTS = [];
export const FORMACTIONS_SIZES = [];

export function FormActions({ className: extra, children, ...rest }) {
    const classes = ['lp-form-actions'];
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
