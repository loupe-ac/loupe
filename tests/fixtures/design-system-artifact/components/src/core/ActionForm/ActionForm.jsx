import React from 'react';

export const ACTIONFORM_VARIANTS = [];
export const ACTIONFORM_SIZES = [];

export function ActionForm({ className: extra, children, ...rest }) {
    const classes = ['lp-action-form'];
    if (extra) {
        classes.push(extra);
    }
    const className = classes.join(' ');


    return (
        <form {...rest} className={className}>
            {children}
        </form>
    );
}
