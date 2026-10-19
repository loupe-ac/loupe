import type { ReactNode } from 'react';

/**
 * A form field with its label, control, hint and errors.
 *
 * @startingPoint section="Core" subtitle="Every FormField variant" viewport="700x150"
 */
export interface FormFieldProps {
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function FormField(props: FormFieldProps): JSX.Element;
