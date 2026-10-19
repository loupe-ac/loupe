import type { ReactNode } from 'react';

/**
 * A button that puts a text on the clipboard and shows that it did.
 *
 * @startingPoint section="Core" subtitle="Every CopyButton variant" viewport="700x150"
 */
export interface CopyButtonProps {
    /** The visual weight and shape. */
    variant?:
        | 'text'
        | 'icon'
        | (string & {});
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function CopyButton(props: CopyButtonProps): JSX.Element;
