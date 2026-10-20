import React from 'react';

export const WORKSPACE_WIDTHS = ['default', 'narrow', 'full'];

export function Workspace({ width = 'default', children, className: extra, ...rest }) {
    const classes = ['lp-workspace'];
    if ('default' !== width) {
        classes.push('lp-workspace--' + width);
    }
    if (extra) {
        classes.push(extra);
    }

    return (
        <div {...rest} className={classes.join(' ')}>
            {children}
        </div>
    );
}
