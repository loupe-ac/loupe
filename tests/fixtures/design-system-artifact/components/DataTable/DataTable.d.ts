import type { ReactNode } from 'react';

/**
 * A list of one-line rows under a header. The caller sets the column grid with a modifier.
 *
 * @startingPoint section="Core" subtitle="Every DataTable variant" viewport="700x150"
 */
export interface DataTableProps {
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function DataTable(props: DataTableProps): JSX.Element;
