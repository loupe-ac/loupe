import type { ReactNode } from 'react';

/**
 * The page container: the frame that holds the content of a page, with a named width.
 *
 * @startingPoint section="Layout" subtitle="The frame of a page" viewport="700x200"
 */
export interface WorkspaceProps {
    /** The width of the frame. `default` is the standard page, `narrow` suits a form, and `full` drops the cap for a viewport-sized page. */
    width?:
        | 'default'
        | 'narrow'
        | 'full'
        | (string & {});
    /** The content of the page. */
    children?: ReactNode;
    className?: string;
    [attribute: string]: unknown;
}

export declare function Workspace(props: WorkspaceProps): JSX.Element;
