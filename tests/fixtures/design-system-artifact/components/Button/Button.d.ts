import type { ReactNode } from 'react';

/**
 * Any action or link that looks like a button. Sentence-case labels. Combine modifiers with a space, such as `ghost icon`.
 *
 * @startingPoint section="Core" subtitle="Every Button variant and size" viewport="700x330"
 */
export interface ButtonProps {
    /** The visual weight and shape. */
    variant?:
        | 'primary'
        | 'inverse'
        | 'outline'
        | 'success'
        | 'danger'
        | 'ghost'
        | 'danger-ghost'
        | 'on-card'
        | 'icon'
        | 'compact'
        | 'open'
        | (string & {});
    /** The size step. Leave it out for the default size. */
    size?: 'sm' | 'lg';
    disabled?: boolean;
    type?: 'button' | 'submit' | 'reset';
    /** Renders a link instead of a button. */
    href?: string;
    onClick?: () => void;
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function Button(props: ButtonProps): JSX.Element;
