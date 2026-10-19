import React from 'react';

export function EmptyState({ icon, title, body, linkLabel, linkHref, compact, iconSize = compact ? 'xl' : '2xl', iconClass, className: extra, children, ...rest }) {
    const classes = ['lp-empty-state'];
    if (compact) {
        classes.push('lp-empty-state--compact');
    }
    if (extra) {
        classes.push(extra);
    }
    const className = classes.join(' ');

    return (
        <div {...rest} className={className}>
            {icon && <span className={'lp-empty-state__icon lp-icon lp-icon--' + iconSize + (iconClass ? ' ' + iconClass : '')}>{icon}</span>}
            {title && <h2 className="lp-empty-state__title">{title}</h2>}
            {body && <p className="lp-empty-state__body">{body}</p>}
            {children}
            {linkLabel && linkHref && <a className="lp-empty-state__link" href={linkHref} target="_blank" rel="noopener noreferrer">{linkLabel}</a>}
        </div>
    );
}
