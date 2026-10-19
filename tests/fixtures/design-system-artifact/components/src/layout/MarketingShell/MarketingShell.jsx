import React from 'react';

export function MarketingShell({ className: extra, children, ...rest }) {
    const classes = ['lp-landing'];
    if (extra) {
        classes.push(extra);
    }

    return (
        <div {...rest} className={classes.join(' ')}>
            <header className="lp-landing-nav">
                <a href="#" className="lp-landing-nav__brand">
                    <span className="lp-brand lp-brand--md">
                        <span className="lp-brand__mark" aria-hidden="true"></span>
                        <span className="lp-brand__word">loupe</span>
                    </span>
                </a>
                <nav className="lp-landing-nav__links">
                    <a href="#" className="lp-landing-nav__link">Back to home</a>
                    <a href="#" className="lp-landing-nav__link lp-landing-nav__link--wide">Sign in</a>
                    <a href="#" className="lp-btn lp-btn--primary lp-btn--sm">Sign up free</a>
                </nav>
            </header>
            <main>{children}</main>
            <footer className="lp-landing-footer">
                <div className="lp-landing-inner lp-landing-footer__row">
                    <a href="#" className="lp-landing-nav__brand lp-landing-nav__brand--sm">
                        <span className="lp-brand lp-brand--sm">
                            <span className="lp-brand__mark" aria-hidden="true"></span>
                            <span className="lp-brand__word">loupe</span>
                        </span>
                    </a>
                    <span className="lp-landing-footer__copyright">© Geoffrey Bachelet</span>
                    <nav className="lp-landing-footer__nav">
                        <a href="#" className="lp-landing-footer__link">Source code</a>
                        <a href="#" className="lp-landing-footer__link">Docs</a>
                        <a href="#" className="lp-landing-footer__link">Privacy</a>
                        <a href="#" className="lp-landing-footer__link">Terms</a>
                        <a href="#" className="lp-landing-footer__link">AI Policy</a>
                        <a href="#" className="lp-landing-footer__link" aria-label="Geoffrey Bachelet on X">X</a>
                        <a href="#" className="lp-landing-footer__link" aria-label="Geoffrey Bachelet on Bluesky">Bluesky</a>
                    </nav>
                </div>
            </footer>
        </div>
    );
}
