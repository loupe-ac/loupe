import React from 'react';

export function Drawer({ header, children, footer, className: extra, ...rest }) {
    const classes = ['lp-drawer'];
    if (extra) {
        classes.push(extra);
    }

    return (
        <dialog {...rest} className={classes.join(' ')}>
            {header ? <header className="lp-drawer__header">{header}</header> : null}
            <div className="lp-drawer__body">{children}</div>
            {footer ? <footer className="lp-drawer__footer">{footer}</footer> : null}
        </dialog>
    );
}
