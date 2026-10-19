/**
 * The frame of a public page for a signed-out visitor: the marketing header, the page body and the footer with the legal links.
 *
 * @startingPoint section="Layout" subtitle="Header, page body and footer" viewport="900x500"
 */
export interface MarketingShellProps {
    /** The page body, drawn between the header and the footer. */
    children?: React.ReactNode;
    className?: string;
    [attribute: string]: unknown;
}

export declare function MarketingShell(props: MarketingShellProps): JSX.Element;
