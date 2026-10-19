import React from 'react';

const GLYPHS = {
    stuck: (
        <g fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2">
            <circle cx="12" cy="12" r="10" />
            <path d="M12 8v4m0 4h.01" />
        </g>
    ),
    'needs-you': (
        <g fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2">
            <circle cx="12" cy="12" r="10" />
            <circle cx="12" cy="10" r="3" />
            <path d="M7 20.662V19a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v1.662" />
        </g>
    ),
    working: <path fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 12a9 9 0 1 1-6.219-8.56" />,
    waiting: <path fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 22h14M5 2h14m-2 20v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2" />,
    chip: <path fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10.1 2.182a10 10 0 0 1 3.8 0m0 19.636a10 10 0 0 1-3.8 0m7.509-18.097a10 10 0 0 1 2.69 2.7M2.182 13.9a10 10 0 0 1 0-3.8m18.097 7.509a10 10 0 0 1-2.7 2.69M21.818 10.1a10 10 0 0 1 0 3.8M3.721 6.391a10 10 0 0 1 2.7-2.69m-.03 16.578a10 10 0 0 1-2.69-2.7" />,
};

const CHIPS = { stuck: 'failed', 'needs-you': 'pending', working: 'active', waiting: 'neutral', chip: 'neutral' };

export function StatusBox({ kind, title, chip, lead, fields = [], others = [], othersTitle, className: extra, children, ...rest }) {
    const classes = ['lp-status-box', 'lp-status-box--' + kind];
    if (extra) {
        classes.push(extra);
    }

    return (
        <section {...rest} className={classes.join(' ')}>
            <div className="lp-status-box__head">
                <h2 className="lp-status-box__title">{title}</h2>
                <span className={'lp-status-chip lp-status-chip--' + CHIPS[kind] + ' lp-status-box__chip'}>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="1em" height="1em" className="lp-status-box__icon" aria-hidden="true">
                        {GLYPHS[kind]}
                    </svg>
                    {chip}
                </span>
            </div>
            <p className="lp-status-box__lead">{lead}</p>
            {fields.length > 0 && (
                <dl className="lp-status-box__fields">
                    {fields.map((field, index) => (
                        <div key={index} className={'lp-status-box__field' + (field.wide ? ' lp-status-box__field--wide' : '')}>
                            <dt>{field.label}</dt>
                            <dd>{field.value}</dd>
                        </div>
                    ))}
                </dl>
            )}
            {others.length > 0 && (
                <div className="lp-status-box__others">
                    {othersTitle && <p className="lp-status-box__others-title">{othersTitle}</p>}
                    <ul className="lp-status-box__list">
                        {others.map((other, index) => (
                            <li key={index} className={'lp-status-box__other lp-status-box__other--' + other.kind}>
                                <span className="lp-status-box__dot"></span>
                                <span>
                                    <strong>{other.label}</strong>
                                    {other.since && <> <span className="lp-status-box__since">{other.since}</span></>}
                                    <br />
                                    {other.lead}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}
