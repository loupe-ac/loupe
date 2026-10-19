import type { ReactNode } from 'react';

/**
 * A row of tabs that switches between views of one page, or a pill group that switches a view or a setting.
 *
 * @startingPoint section="Core" subtitle="Every Tabs variant" viewport="700x170"
 */
export interface TabsProps {
    /** The visual weight and shape. */
    variant?:
        | 'segmented'
        | (string & {});
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Tabs(props: TabsProps): JSX.Element;
