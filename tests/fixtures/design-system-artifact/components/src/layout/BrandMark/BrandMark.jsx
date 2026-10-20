import React from 'react';

export const BRANDMARK_SIZES = ['sm', 'md'];

export function BrandMark({ size = 'md', markOnly = false, className: extra, ...rest }) {
    const classes = ['lp-brand', 'lp-brand--' + size];
    if (markOnly) {
        classes.push('lp-brand--mark-only');
    }
    if (extra) {
        classes.push(extra);
    }

    return (
        <span {...rest} className={classes.join(' ')}>
            {markOnly ? (
                <span className="lp-brand__mark" role="img" aria-label="loupe"></span>
            ) : (
                <>
                    <span className="lp-brand__mark" aria-hidden="true"></span>
                    <span className="lp-brand__word">loupe</span>
                </>
            )}
        </span>
    );
}
