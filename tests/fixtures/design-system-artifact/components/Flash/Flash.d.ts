import type { ReactNode } from 'react';

/**
 * A short message about the result of an action.
 *
 * @startingPoint section="Core" subtitle="Every Flash severity, with and without a dismiss button" viewport="700x190"
 */
export interface FlashProps {
    /** The colour of the dot. */
    severity?: 'success' | 'error' | 'warning' | 'info';
    /** The text of the dismiss button. Leave it out for a message with no button. */
    dismissLabel?: string;
    /** Draws the coloured dot. True by default. */
    dot?: boolean;
    className?: string;
    /** The message. */
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Flash(props: FlashProps): JSX.Element;
