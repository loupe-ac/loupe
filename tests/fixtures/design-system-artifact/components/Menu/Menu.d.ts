import type { ReactNode } from 'react';

/**
 * A panel of actions or links that opens under a trigger, with items, headings and dividers.
 *
 * @startingPoint section="Core" subtitle="Every Menu variant and size" viewport="700x150"
 */
export interface MenuProps {
    /** The visual weight and shape. */
    variant?:
        | 'light'
        | 'chrome'
        | (string & {});
    /** The size step. Leave it out for the default size. */
    size?: 'sm' | 'md' | 'lg';
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Menu(props: MenuProps): JSX.Element;
