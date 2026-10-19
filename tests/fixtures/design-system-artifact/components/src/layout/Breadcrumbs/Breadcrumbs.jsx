import React from 'react';

export function Breadcrumbs({ items = [], className: extra, children, ...rest }) {
    const trail = items.map((item) => (typeof item === 'string' ? { label: item } : item));
    const classes = ['lp-crumbs'];
    if (extra) {
        classes.push(extra);
    }

    return (
        <nav {...rest} className={classes.join(' ')} aria-label="Breadcrumb">
            <ol className="lp-crumbs__list">
                {trail.map((item, index) => (
                    index === trail.length - 1 ? (
                        <li key={item.label} className="lp-crumbs__item lp-crumbs__item--current">
                            <span className="lp-crumbs__here" aria-current="page">{item.label}</span>
                        </li>
                    ) : (
                        <li key={item.label} className="lp-crumbs__item">
                            {item.href
                                ? <a className="lp-crumbs__link" href={item.href}>{item.label}</a>
                                : <span className="lp-crumbs__link">{item.label}</span>}
                            <span className="lp-crumbs__sep" aria-hidden="true">/</span>
                        </li>
                    )
                ))}
            </ol>
            {children}
        </nav>
    );
}
