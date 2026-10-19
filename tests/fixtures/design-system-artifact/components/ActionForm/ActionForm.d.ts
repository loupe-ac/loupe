import type { ReactNode } from 'react';

/**
 * A form that posts with a CSRF token and holds one button, such as an archive, a revoke or a skip.
 *
 * @startingPoint section="Core" subtitle="Every ActionForm variant" viewport="700x150"
 */
export interface ActionFormProps {
    className?: string;
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function ActionForm(props: ActionFormProps): JSX.Element;
