import type { ReactNode } from 'react';

/**
 * One link of the sidebar: an icon, a label, an optional count and the current-page mark.
 *
 * @startingPoint section="Layout" subtitle="A sidebar link, current and not" viewport="700x200"
 */
export interface NavItemProps {
    /** The link text. */
    label: ReactNode;
    /** The icon, drawn before the label. Pass an svg that uses `currentColor`. */
    icon?: ReactNode;
    /** Marks the current page. */
    active?: boolean;
    /** A plain numeral at the end of the row. Left out when undefined. */
    count?: number;
    href?: string;
    className?: string;
    /** Anything else drawn after the label, such as an amber pill. */
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function NavItem(props: NavItemProps): JSX.Element;
