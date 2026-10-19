import React from 'react';

export function Input({ mono = false, className: extra, ...rest }) {
    const classes = ['lp-input'];
    if (mono) {
        classes.push('lp-input--mono');
    }
    if (extra) {
        classes.push(extra);
    }
    const className = classes.join(' ');

    return <input {...rest} className={className} />;
}
