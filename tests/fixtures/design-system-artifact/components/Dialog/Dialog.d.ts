import type { ReactNode } from 'react';

/**
 * A modal window over the page, opened by the modal controller.
 *
 * @startingPoint section="Core" subtitle="Every Dialog variant" viewport="700x420"
 */
export interface DialogProps {
    /** The visual weight and shape. */
    size?:
        | 'document'
        | 'search'
        | (string & {});
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Dialog(props: DialogProps): JSX.Element;
