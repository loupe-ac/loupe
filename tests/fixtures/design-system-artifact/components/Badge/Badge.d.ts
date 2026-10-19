import type { ReactNode } from 'react';

/**
 * A small label for the review status of a document.
 *
 * @startingPoint section="Core" subtitle="Every Badge variant" viewport="700x150"
 */
export interface BadgeProps {
    /** The visual weight and shape. */
    status?:
        | 'in-review'
        | 'draft'
        | 'approved'
        | 'changes-requested'
        | (string & {});
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Badge(props: BadgeProps): JSX.Element;
