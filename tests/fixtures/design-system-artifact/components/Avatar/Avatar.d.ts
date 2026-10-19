import type { ReactNode } from 'react';

/**
 * The first letter of a name, or an icon, in a disc or a rounded square. A dot shows a connection.
 *
 * @startingPoint section="Core" subtitle="Every Avatar variant and size" viewport="700x150"
 */
export interface AvatarProps {
    /** The visual weight and shape. */
    variant?:
        | 'accent'
        | 'tint'
        | 'ink'
        | 'danger'
        | 'chrome'
        | (string & {});
    /** The size step. Leave it out for the default size. */
    size?: 'xs' | 'sm' | 'md' | 'lg';
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Avatar(props: AvatarProps): JSX.Element;
