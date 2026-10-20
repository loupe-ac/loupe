import type { ReactNode } from 'react';

/**
 * One row of a list: an icon, a title and a meta line. The current row is highlighted.
 *
 * @startingPoint section="Layout" subtitle="A list row, open and not open" viewport="700x200"
 */
export interface ListRowProps {
    /** The row title. */
    title: ReactNode;
    /** The icon before the title. */
    icon?: ReactNode;
    /** The small line under the title, such as a source and an age. */
    meta?: ReactNode;
    /** True for the row that is open. It gets the highlight. */
    current?: boolean;
    /** The link target of the row. */
    href?: string;
    className?: string;
    [attribute: string]: unknown;
}

export declare function ListRow(props: ListRowProps): JSX.Element;
