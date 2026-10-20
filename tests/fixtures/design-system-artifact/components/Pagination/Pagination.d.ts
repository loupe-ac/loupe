/**
 * The previous, next and page-number links of a long list.
 *
 * @startingPoint section="Core" subtitle="A Pagination on the first, a middle and the last page" viewport="700x200"
 */
export interface PaginationProps {
    /** The current page, counting from 1. */
    page: number;
    /** The number of pages. The control draws nothing for 1 or less. */
    totalPages: number;
    /** The page numbers to draw, with `null` for a gap. All pages when left out. */
    pageList?: Array<number | null>;
    /** Returns the link of a page. A `#` link by default. */
    hrefFor?: (page: number) => string;
    className?: string;
    [attribute: string]: unknown;
}

export declare function Pagination(props: PaginationProps): JSX.Element | null;
