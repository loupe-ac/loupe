import React from 'react';

export const FILTERBAR_VARIANTS = [];
export const FILTERBAR_SIZES = [];

export function FilterBar({ className: extra, children, ...rest }) {
    const classes = ['lp-list-filters'];
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
