import type { ReactNode } from 'react';

/**
 * A size scale for the icon tag: xs is 3, sm is 3.5, md is 4, lg is 5, xl is 8 and 2xl is 12. xl and 2xl are for an empty-state icon. No component wraps it.
 *
 * @startingPoint section="Core" subtitle="Every Icon size" viewport="700x150"
 */
export interface IconProps {
    /** The size step. */
    variant?:
        | 'xs'
        | 'sm'
        | 'md'
        | 'lg'
        | 'xl'
        | '2xl'
        | (string & {});
    className?: string;
    /** The SVG shapes of a 24 by 24 Lucide icon. A check mark draws when you leave it out. */
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Icon(props: IconProps): JSX.Element;
