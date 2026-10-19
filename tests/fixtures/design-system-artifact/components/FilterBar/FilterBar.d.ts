import type { ReactNode } from 'react';

/**
 * The search, selects, switch, clear link and count above a filtered list.
 *
 * @startingPoint section="Core" subtitle="Every FilterBar variant" viewport="700x150"
 */
export interface FilterBarProps {
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function FilterBar(props: FilterBarProps): JSX.Element;
