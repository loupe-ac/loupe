import React from 'react';

export function ListRow({ title, icon, meta, current, href, className: extra, ...rest }) {
    const classes = ['lp-list-row'];
    if (extra) {
        classes.push(extra);
    }

    return (
        <a {...rest} href={href} className={classes.join(' ')} aria-current={current ? 'true' : undefined}>
            <span className="lp-list-row__icon">{icon}</span>
            <span className="lp-list-row__title">{title}</span>
            <span className="lp-list-row__meta">{meta}</span>
        </a>
    );
}
