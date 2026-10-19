/**
 * The logo square and the brand name, as the sidebar, the sign-in pages and the marketing pages draw them.
 *
 * @startingPoint section="Layout" subtitle="Both sizes and the mark alone" viewport="700x150"
 */
export interface BrandMarkProps {
    /** The size step. md by default. */
    size?: 'sm' | 'md';
    /** Draws the logo square alone, named by the brand name. */
    markOnly?: boolean;
    className?: string;
    /** The mark draws the brand name itself, so it takes no children. */
    children?: never;
    [attribute: string]: unknown;
}

export declare function BrandMark(props: BrandMarkProps): JSX.Element;
