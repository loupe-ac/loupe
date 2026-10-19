import type { ReactNode } from 'react';

/**
 * The row of buttons that ends a form or a dialog.
 *
 * @startingPoint section="Core" subtitle="Every FormActions variant" viewport="700x150"
 */
export interface FormActionsProps {
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function FormActions(props: FormActionsProps): JSX.Element;
