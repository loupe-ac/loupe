import type { ReactNode } from 'react';

/**
 * A bordered tinted card that holds one block of a page.
 *
 * @startingPoint section="Core" subtitle="Every Panel variant" viewport="700x150"
 */
export interface PanelProps {
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Panel(props: PanelProps): JSX.Element;
