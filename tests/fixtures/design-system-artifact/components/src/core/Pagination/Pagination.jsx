import React from 'react';

export function Pagination({ page, totalPages, pageList, hrefFor = () => '#', className: extra, ...rest }) {
    if (!(totalPages > 1)) {
        return null;
    }
    const classes = ['lp-pagination'];
    if (extra) {
        classes.push(extra);
    }
    const className = classes.join(' ');
    const pages = pageList || Array.from({ length: totalPages }, (_, index) => index + 1);

    return (
        <nav {...rest} className={className} aria-label="Pagination">
            <p className="lp-pagination__status">Page {page} of {totalPages}</p>
            <div className="lp-pagination__controls">
                {page > 1
                    ? <a className="lp-btn lp-btn--ghost lp-btn--sm" href={hrefFor(page - 1)} aria-label="Previous page">‹</a>
                    : <button type="button" className="lp-btn lp-btn--ghost lp-btn--sm lp-pagination__disabled" disabled aria-disabled="true" aria-label="Previous page">‹</button>}
                {pages.map((number, index) => number === null
                    ? <span key={'gap' + index} className="lp-pagination__ellipsis">…</span>
                    : <a key={number} className={'lp-btn lp-btn--sm lp-btn--' + (number === page ? 'primary' : 'ghost')} href={hrefFor(number)} aria-current={number === page ? 'page' : undefined}>{number}</a>)}
                {page < totalPages
                    ? <a className="lp-btn lp-btn--ghost lp-btn--sm" href={hrefFor(page + 1)} aria-label="Next page">›</a>
                    : <button type="button" className="lp-btn lp-btn--ghost lp-btn--sm lp-pagination__disabled" disabled aria-disabled="true" aria-label="Next page">›</button>}
            </div>
        </nav>
    );
}
