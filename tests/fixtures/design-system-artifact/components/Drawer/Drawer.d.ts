import type { ReactNode } from 'react';

/**
 * A panel that slides in from the right edge, with a header, a body that scrolls and a footer.
 *
 * @startingPoint section="Layout" subtitle="A header, a scrolling body and a footer" viewport="700x420"
 */
export interface DrawerProps {
    /** The header band, such as a title and a close button. Left out, no band is drawn. */
    header?: ReactNode;
    /** The body. It scrolls when it is taller than the drawer. */
    children?: ReactNode;
    /** The footer band, such as the main action. Left out, no band is drawn. */
    footer?: ReactNode;
    /** Pass `open` to show the dialog. */
    open?: boolean;
    className?: string;
    [attribute: string]: unknown;
}

export declare function Drawer(props: DrawerProps): JSX.Element;
