import type { ReactNode } from 'react';

/**
 * A small coloured label for a category or a tone.
 *
 * @startingPoint section="Core" subtitle="Every Tag variant" viewport="700x150"
 */
export interface TagProps {
    /** The visual weight and shape. */
    tone?:
        | 'neutral'
        | 'lime'
        | 'purple'
        | 'green'
        | 'amber'
        | 'red'
        | 'teal'
        | 'sky'
        | 'blue'
        | 'indigo'
        | 'pink'
        | 'orange'
        | (string & {});
    /** The element to render. */
    as?: keyof JSX.IntrinsicElements;
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Tag(props: TagProps): JSX.Element;
