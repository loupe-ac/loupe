/**
 * A text field of several lines.
 *
 * @startingPoint section="Core" subtitle="A Textarea with text" viewport="700x170"
 */
export interface TextareaProps {
    /** The text of the field. Pass it here, never as an element child. */
    defaultValue?: string;
    /** Plain text, taken as the default value. */
    children?: string;
    className?: string;
    [attribute: string]: unknown;
}

export declare function Textarea(props: TextareaProps): JSX.Element;
