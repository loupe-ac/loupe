import React from 'react';

export const CODEBLOCK_VARIANTS = [];
export const CODEBLOCK_SIZES = [];

export function CodeBlock({ className: extra, children, ...rest }) {
    const classes = ['lp-code-dark'];
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
