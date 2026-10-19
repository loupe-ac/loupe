import React from 'react';

export const ICON_VARIANTS = ['xs', 'sm', 'md', 'lg', 'xl', '2xl'];

export function Icon({ variant, className: extra, children, ...rest }) {
    const classes = ['lp-icon'];
    for (const modifier of (variant || '').split(/\s+/).filter(Boolean)) {
        classes.push('lp-icon--' + modifier);
    }
    if (extra) {
        classes.push(extra);
    }
    const className = classes.join(' ');

    return (
        <svg {...rest} className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            {children || <path d="M20 6 9 17l-5-5" />}
        </svg>
    );
}
