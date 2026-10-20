import React from 'react';

export function SplitView({ header, list, detail, className: extra, ...rest }) {
    const classes = ['lp-split-page'];
    if (extra) {
        classes.push(extra);
    }

    return (
        <div {...rest} className={classes.join(' ')}>
            {header}
            <div className="lp-split-region">
                <div className="lp-split-view">
                    <div className="lp-split-pane">{list}</div>
                    <div className="lp-split-pane">{detail}</div>
                </div>
            </div>
        </div>
    );
}
