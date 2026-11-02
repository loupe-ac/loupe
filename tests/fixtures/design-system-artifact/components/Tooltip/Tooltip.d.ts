import type { ReactNode } from 'react';

/**
 * A short label that shows over an element on hover or focus. A touch screen hides it. The interactive variant stays open for a link.
 *
 * @startingPoint section="Core" subtitle="A Tooltip, plain and interactive" viewport="700x150"
 */
export interface TooltipProps {
    /** Keeps the tooltip open while the pointer or the focus is inside it, so it may hold a link. */
    interactive?: boolean;
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Tooltip(props: TooltipProps): JSX.Element;
