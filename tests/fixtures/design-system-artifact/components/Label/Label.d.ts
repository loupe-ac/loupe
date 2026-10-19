import type { ReactNode } from 'react';

/**
 * The name of a form control.
 *
 * @startingPoint section="Core" subtitle="Every Label variant" viewport="700x150"
 */
export interface LabelProps {
    /** The element to render. */
    as?: keyof JSX.IntrinsicElements;
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Label(props: LabelProps): JSX.Element;
