import type { ReactNode } from 'react';

/**
 * The list of errors of a form field.
 *
 * @startingPoint section="Core" subtitle="Every FieldErrors variant" viewport="700x150"
 */
export interface FieldErrorsProps {
    /** The element to render. */
    as?: keyof JSX.IntrinsicElements;
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function FieldErrors(props: FieldErrorsProps): JSX.Element;
