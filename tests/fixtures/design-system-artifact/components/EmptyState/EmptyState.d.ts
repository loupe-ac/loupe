import type { ReactNode } from 'react';

/**
 * The block that tells a user a list or page has no content yet.
 *
 * @startingPoint section="Core" subtitle="An EmptyState with an icon, a title, a sentence and a link" viewport="700x220"
 */
export interface EmptyStateProps {
    /** An icon, drawn as inline SVG. Optional. */
    icon?: ReactNode;
    /** A smaller block for an empty section inside a page. */
    compact?: boolean;
    /** A step of the icon scale, such as `xl`. `2xl` by default, and `xl` when compact. */
    iconSize?: 'xs' | 'sm' | 'md' | 'lg' | 'xl' | '2xl';
    /** Colour classes of the icon. Optional. */
    iconClass?: string;
    /** The heading. Optional. */
    title?: string;
    /** The sentence under the heading. Optional. */
    body?: string;
    /** The text of the link. The link shows only when `linkHref` is set too. */
    linkLabel?: string;
    /** The target of the link. */
    linkHref?: string;
    className?: string;
    /** Goes after the body, for a second paragraph or an action. */
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function EmptyState(props: EmptyStateProps): JSX.Element;
