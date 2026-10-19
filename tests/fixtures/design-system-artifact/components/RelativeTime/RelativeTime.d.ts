import type { ReactNode } from 'react';

/**
 * A moment as a short age such as 2h ago, or as a date. The title holds the full timestamp.
 *
 * @startingPoint section="Core" subtitle="Every RelativeTime variant" viewport="700x150"
 */
export interface RelativeTimeProps {
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function RelativeTime(props: RelativeTimeProps): JSX.Element;
