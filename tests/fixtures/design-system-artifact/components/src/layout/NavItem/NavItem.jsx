import React from 'react';

export function NavItem({ label, icon, active = false, count, href = '#', className: extra, children, ...rest }) {
    const classes = ['lp-sidebar__link'];
    if (active) {
        classes.push('lp-sidebar__link--active');
    }
    if (extra) {
        classes.push(extra);
    }

    return (
        <a {...rest} href={href} className={classes.join(' ')} aria-current={active ? 'page' : undefined}>
            {icon}
            <span className="lp-sidebar__link-label">{label}</span>
            {undefined !== count && <span className="lp-sidebar__pill">{count}</span>}
            {children}
        </a>
    );
}
