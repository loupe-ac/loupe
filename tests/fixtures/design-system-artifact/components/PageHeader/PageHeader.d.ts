import type { ReactNode } from 'react';

/**
 * The heading row of a page: a title, an optional description and the actions beside them.
 *
 * @startingPoint section="Layout" subtitle="The page heading, with and without actions" viewport="700x200"
 */
export interface PageHeaderProps {
    /** The page heading, drawn as the h1. */
    title: ReactNode;
    /** A sentence under the heading. Left out when empty. */
    description?: ReactNode;
    /** `workshop` gives the heading the workshop size. */
    variant?:
        | 'workshop'
        | (string & {});
    /** Extra class of the h1, such as `lp-workspace-title--doc`. */
    titleClass?: string;
    /** The buttons beside the heading. Drawn after the heading block. */
    actions?: ReactNode;
    className?: string;
    [attribute: string]: unknown;
}

export declare function PageHeader(props: PageHeaderProps): JSX.Element;
