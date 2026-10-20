/**
 * The frame of every sign-in, sign-up and install page: a topbar with the brand mark, one centred card and a footer of legal links.
 *
 * @startingPoint section="Layout" subtitle="The sign-in page frame with a form" viewport="900x640"
 */
export interface AuthShellProps {
    /** Content at the right of the topbar, such as a link to the other sign-in page. */
    nav?: React.ReactNode;
    /** The footer row. Defaults to the app footer: the copyright and the legal links. Put custom links in a `lp-auth__footer-links` element, or pass `null` to hide the row. */
    footer?: React.ReactNode;
    /** Widens the card for a page that needs more than a form column. */
    wide?: boolean;
    className?: string;
    /** The content of the card: a heading, a form and a link. */
    children?: React.ReactNode;
    [attribute: string]: unknown;
}

export declare function AuthShell(props: AuthShellProps): JSX.Element;
