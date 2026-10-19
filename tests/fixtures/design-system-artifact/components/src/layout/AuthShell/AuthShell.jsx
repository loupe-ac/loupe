import React from 'react';

const defaultFooter = (
    <>
        <span>© Geoffrey Bachelet</span>
        <div className="lp-auth__footer-links">
            <a href="#" className="lp-auth__footer-link">Source code</a>
            <a href="#" className="lp-auth__footer-link">Docs</a>
            <a href="#" className="lp-auth__footer-link">Privacy</a>
            <a href="#" className="lp-auth__footer-link">Terms</a>
            <a href="#" className="lp-auth__footer-link">AI Policy</a>
        </div>
    </>
);

export function AuthShell({ nav, footer = defaultFooter, wide = false, className: extra, children, ...rest }) {
    const card = ['lp-auth__card', wide ? 'lp-auth__card--wide' : '', extra].filter(Boolean).join(' ');

    return (
        <div {...rest} className="lp-auth">
            <div className="lp-auth__grid"></div>
            <div className="lp-auth__glow"></div>
            <div className="lp-auth__topbar">
                <a href="/" className="lp-auth__brand">
                    <span className="lp-brand lp-brand--md">
                        <span className="lp-brand__mark" aria-hidden="true"></span>
                        <span className="lp-brand__word">loupe</span>
                    </span>
                </a>
                {nav ? <div className="lp-auth__topbar-nav">{nav}</div> : null}
            </div>
            <main className="lp-auth__main">
                <div className={card}>{children}</div>
            </main>
            {footer ? <div className="lp-auth__footer">{footer}</div> : null}
        </div>
    );
}
