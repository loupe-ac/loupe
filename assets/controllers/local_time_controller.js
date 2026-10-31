import { Controller } from '@hotwired/stimulus';

const partOf = (parts, type) =>
    parts.find((part) => part.type === type)?.value ?? '';

/** The date as `Sun 1 Nov` and the time as `18:00`, in the given zone, or in the zone of the browser when it is undefined. */
export const localParts = (date, timeZone = undefined) => {
    const dateParts = new Intl.DateTimeFormat('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        timeZone,
    }).formatToParts(date);
    const timeParts = new Intl.DateTimeFormat('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
        timeZone,
    }).formatToParts(date);

    return {
        date: `${partOf(dateParts, 'weekday')} ${partOf(dateParts, 'day')} ${partOf(dateParts, 'month')}`,
        time: `${partOf(timeParts, 'hour')}:${partOf(timeParts, 'minute')}`,
    };
};

/** Rewrites the date or the time of a `<time>` element in the zone of the reader. The server text stays when the value is not a date. */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = { part: String };

    connect() {
        this.onMorph = () => this.render();
        this.element.addEventListener('turbo:morph-element', this.onMorph);
        this.render();
    }

    disconnect() {
        this.element.removeEventListener('turbo:morph-element', this.onMorph);
    }

    /** A morph puts the server text back and keeps the controller connected, so it renders again. */
    render() {
        const date = new Date(this.element.getAttribute('datetime') ?? '');
        if (Number.isNaN(date.getTime())) {
            return;
        }
        const text = localParts(date)[this.partValue];
        if (text) {
            this.element.textContent = text;
        }
    }
}
