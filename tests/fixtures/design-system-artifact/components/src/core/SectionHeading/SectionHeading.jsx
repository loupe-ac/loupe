import React from 'react';

export const SECTIONHEADING_VARIANTS = [];
export const SECTIONHEADING_SIZES = [];

export function SectionHeading({ className: extra, children, ...rest }) {
    const classes = ['lp-section-heading'];
    if (extra) {
        classes.push(extra);
    }
    const className = classes.join(' ');


    return (
        <div {...rest} className={className}>
            {children}
        </div>
    );
}
