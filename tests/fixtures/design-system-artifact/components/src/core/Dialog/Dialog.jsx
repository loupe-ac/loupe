import React from 'react';

export const DIALOG_VARIANTS = ['document', 'search'];
export const DIALOG_SIZES = [];

export function Dialog({ size, className: extra, children, ...rest }) {
    const classes = ['lp-dialog'];
    for (const names of [size]) {
        for (const modifier of (names || '').split(/\s+/).filter(Boolean)) {
            classes.push('lp-dialog--' + modifier);
        }
    }
    if (extra) {
        classes.push(extra);
    }
    const className = classes.join(' ');


    return (
        <dialog data-modal-target="dialog" {...rest} className={className}>
            {children}
        </dialog>
    );
}
