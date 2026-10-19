import type { ReactNode } from 'react';

export interface SettingsLayoutItem {
    label: string;
    href: string;
    /** The icon before the label, such as a 16 px stroke icon. */
    icon?: ReactNode;
    /** True for the section that is open. It gets the highlight. */
    active?: boolean;
}

/**
 * A settings page: the page header, a section navigation beside the content, and the content column.
 *
 * @startingPoint section="Layout" subtitle="A settings page with its section navigation" viewport="900x520"
 */
export interface SettingsLayoutProps {
    /** The page heading, drawn as the h1. */
    title: ReactNode;
    /** A sentence under the heading. Left out when empty. */
    description?: ReactNode;
    /** The sections of the navigation, in order. */
    items?: SettingsLayoutItem[];
    /** The accessible name of the navigation. */
    navLabel?: string;
    /** Gives the content its own column width. */
    columns?: boolean;
    className?: string;
    /** The content of the open section: one or more `Panel` sections. */
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function SettingsLayout(props: SettingsLayoutProps): JSX.Element;
