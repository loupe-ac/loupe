import React from 'react';

export const PANEL_VARIANTS = [];
export const PANEL_SIZES = [];

export function Panel({ className: extra, children, ...rest }) {
    const classes = ['lp-panel'];
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
