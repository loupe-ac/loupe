import React from 'react';

export const RELATIVETIME_VARIANTS = [];
export const RELATIVETIME_SIZES = [];

export function RelativeTime({ className: extra, children, ...rest }) {
    const classes = ['lp-relative-time'];
    if (extra) {
        classes.push(extra);
    }
    const className = classes.join(' ');


    return (
        <time {...rest} className={className}>
            {children}
        </time>
    );
}
