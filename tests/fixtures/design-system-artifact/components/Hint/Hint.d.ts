import type { ReactNode } from 'react';

/**
 * A line of help under a form control.
 *
 * @startingPoint section="Core" subtitle="Every Hint variant" viewport="700x150"
 */
export interface HintProps {
    /** The element to render. */
    as?: keyof JSX.IntrinsicElements;
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Hint(props: HintProps): JSX.Element;
