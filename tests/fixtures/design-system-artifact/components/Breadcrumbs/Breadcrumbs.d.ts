import type { ReactNode } from 'react';

/**
 * The trail at the top of a page: links to the parent pages and the current page last.
 *
 * @startingPoint section="Layout" subtitle="A trail of pages" viewport="700x120"
 */
export interface BreadcrumbsProps {
    /** The trail. A string is a crumb with no link. The last one is the page you are on. */
    items: Array<string | { label: string; href?: string }>;
    className?: string;
    /** Anything drawn after the list, such as a version pill. */
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Breadcrumbs(props: BreadcrumbsProps): JSX.Element;
