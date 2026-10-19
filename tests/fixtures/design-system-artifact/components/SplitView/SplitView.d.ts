import type { ReactNode } from 'react';

/**
 * A master-detail page: a held header over a list pane beside a detail pane, each scrolling on its own.
 *
 * @startingPoint section="Layout" subtitle="A list pane beside a detail pane" viewport="700x320"
 */
export interface SplitViewProps {
    /** The held header, such as a PageHeader. Drawn above the panes and never scrolls. */
    header?: ReactNode;
    /** The list pane, on the left. It scrolls on its own. Fill it with ListRow parts. */
    list?: ReactNode;
    /** The detail pane, on the right. It scrolls on its own. */
    detail?: ReactNode;
    className?: string;
    [attribute: string]: unknown;
}

export declare function SplitView(props: SplitViewProps): JSX.Element;
