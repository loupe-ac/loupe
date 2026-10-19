import type { ReactNode } from 'react';

/**
 * The state of a card with its reason, its facts, what clears it, and the other states that apply.
 *
 * @startingPoint section="Core" subtitle="A StatusBox for a stuck card, with facts and other states" viewport="700x420"
 */
export interface StatusBoxProps {
    /** The state. It sets the colour and the icon of the chip. */
    kind: 'stuck' | 'needs-you' | 'working' | 'waiting' | 'chip';
    /** The heading of the box. */
    title: ReactNode;
    /** The chip text, the state and how long it has held. */
    chip: ReactNode;
    /** The sentence that says why the card has the state. */
    lead: ReactNode;
    /** Optional facts. Set `wide` for a sentence that takes the whole row. */
    fields?: { label: ReactNode; value: ReactNode; wide?: boolean }[];
    /** The other states that apply. */
    others?: { kind: string; label: ReactNode; lead: ReactNode; since?: ReactNode }[];
    /** The heading of the list of other states. */
    othersTitle?: ReactNode;
    className?: string;
    [attribute: string]: unknown;
}

export declare function StatusBox(props: StatusBoxProps): JSX.Element;
