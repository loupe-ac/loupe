import React from 'react';

export const BUTTON_VARIANTS = ['primary', 'inverse', 'outline', 'success', 'danger', 'ghost', 'danger-ghost', 'on-card', 'icon', 'compact', 'open'];
export const BUTTON_SIZES = ['sm', 'lg'];

export function Button({ variant, size, disabled = false, type = 'button', href, onClick, className: extra, children, ...rest }) {
    const classes = ['lp-btn'];
    for (const names of [variant, size]) {
        for (const modifier of (names || '').split(/\s+/).filter(Boolean)) {
            classes.push('lp-btn--' + modifier);
        }
    }
    if (extra) {
        classes.push(extra);
    }
    const className = classes.join(' ');

    if (href) {
        return (
            <a {...rest} className={className} href={href} onClick={onClick}>
                {children}
            </a>
        );
    }

    return (
        <button {...rest} className={className} type={type} disabled={disabled} onClick={onClick}>
            {children}
        </button>
    );
}
