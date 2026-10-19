import type { ReactNode } from 'react';

/**
 * An icon that names what a card needs now, with its reason in an interactive tooltip.
 *
 * @startingPoint section="Core" subtitle="A StateMark for each state" viewport="700x150"
 */
export interface StateMarkProps {
    /** The state. It picks the icon and the colour. */
    kind: 'stuck' | 'needs-you' | 'working' | 'waiting' | 'chip';
    /** The state name. It is the accessible name of the mark. */
    label: string;
    /** The tooltip id. Generated when left out. */
    id?: string;
    className?: string;
    /** The tooltip. It may hold a link. */
    children?: ReactNode;
    [attribute: string]: unknown;
}

export declare function StateMark(props: StateMarkProps): JSX.Element;
