import type { ReactNode } from 'react';

/**
 * A chip that names a state, with an optional reason in a tooltip.
 *
 * @startingPoint section="Core" subtitle="A StatusChip in each colour, with a reason" viewport="700x150"
 */
export interface StatusChipProps {
    /** The colour: pending, addressed, resolved, ok, failed, neutral. The default chip has none. */
    modifier?: 'pending' | 'addressed' | 'resolved' | 'ok' | 'failed' | 'neutral';
    /** The state name. The content is the label when this is left out. */
    label?: ReactNode;
    /** Draws the coloured dot. True by default. */
    dot?: boolean;
    /** The element, span by default. Use button for a chip that toggles. */
    as?: keyof JSX.IntrinsicElements;
    /** Why the state holds. Shown in a tooltip on hover and focus. */
    reason?: ReactNode;
    /** The tooltip id. Generated when left out. */
    id?: string;
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function StatusChip(props: StatusChipProps): JSX.Element;
