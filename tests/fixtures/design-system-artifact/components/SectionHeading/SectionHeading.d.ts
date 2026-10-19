import type { ReactNode } from 'react';

/**
 * The title of a section, with an optional sentence and optional actions.
 *
 * @startingPoint section="Core" subtitle="Every SectionHeading variant" viewport="700x150"
 */
export interface SectionHeadingProps {
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function SectionHeading(props: SectionHeadingProps): JSX.Element;
