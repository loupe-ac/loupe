import React from 'react';

export const PAGEHEADER_VARIANTS = ['workshop'];

export function PageHeader({ title, description, variant, titleClass, actions, className: extra, ...rest }) {
    const classes = ['lp-page-header'];
    if (variant) {
        classes.push('lp-page-header--' + variant);
    }
    if (extra) {
        classes.push(extra);
    }
    const titleClasses = ['workshop' === variant ? 'lp-workshop-title' : 'lp-workspace-title'];
    if (titleClass) {
        titleClasses.push(titleClass);
    }

    return (
        <header {...rest} className={classes.join(' ')}>
            <div className="lp-page-header__copy">
                <h1 className={titleClasses.join(' ')}>{title}</h1>
                {description && <p className="lp-workspace-desc">{description}</p>}
            </div>
            {actions}
        </header>
    );
}
