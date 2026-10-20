import type { ReactNode } from 'react';

/**
 * A drop-down list of options.
 *
 * @startingPoint section="Core" subtitle="Every Select variant" viewport="700x150"
 */
export interface SelectProps {
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Select(props: SelectProps): JSX.Element;
