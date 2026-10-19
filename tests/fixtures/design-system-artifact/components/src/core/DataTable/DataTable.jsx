import React from 'react';

export const DATATABLE_VARIANTS = [];
export const DATATABLE_SIZES = [];

export function DataTable({ className: extra, children, ...rest }) {
    const classes = ['lp-data-table'];
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
