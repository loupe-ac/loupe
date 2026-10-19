/**
 * A one-line text field.
 *
 * @startingPoint section="Core" subtitle="An Input, plain and mono" viewport="700x150"
 */
export interface InputProps {
    /** Draws the value in the mono font. */
    mono?: boolean;
    className?: string;
    /** An input is a void element, so it takes no children. */
    children?: never;
    [attribute: string]: unknown;
}

export declare function Input(props: InputProps): JSX.Element;
