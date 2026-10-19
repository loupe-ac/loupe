import React from 'react';

export function Textarea({ defaultValue, className: extra, children, ...rest }) {
    const classes = ['lp-input', 'lp-textarea'];
    if (extra) {
        classes.push(extra);
    }
    const className = classes.join(' ');

    return <textarea {...rest} className={className} defaultValue={defaultValue ?? children} />;
}
