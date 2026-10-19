import type { ReactNode } from 'react';

/**
 * A dark block of code or a command, with a header bar and a copy button.
 *
 * @startingPoint section="Core" subtitle="Every CodeBlock variant" viewport="700x150"
 */
export interface CodeBlockProps {
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function CodeBlock(props: CodeBlockProps): JSX.Element;
