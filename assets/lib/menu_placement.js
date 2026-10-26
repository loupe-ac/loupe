export function menuShift(left, width, screenWidth, margin) {
    return Math.max(
        margin - left,
        Math.min(0, screenWidth - margin - (left + width)),
    );
}

export function placeMenuPanel(panel) {
    panel.style.removeProperty('--lp-menu-shift');
    if (
        getComputedStyle(panel).position !== 'absolute' ||
        !panel.offsetParent
    ) {
        return;
    }
    const left =
        panel.offsetParent.getBoundingClientRect().left + panel.offsetLeft;
    const margin = parseFloat(
        getComputedStyle(document.documentElement).fontSize,
    );
    const shift = menuShift(
        left,
        panel.offsetWidth,
        document.documentElement.clientWidth,
        margin,
    );
    if (shift !== 0) {
        panel.style.setProperty('--lp-menu-shift', `${shift}px`);
    }
}

export function followMenuPanel(panel) {
    const place = () => placeMenuPanel(panel);
    const placeAfterMove = (event) => {
        if (event.target !== panel && event.target.contains(panel)) {
            place();
        }
    };
    place();
    window.addEventListener('resize', place);
    document.addEventListener('transitionend', placeAfterMove, true);

    return () => {
        window.removeEventListener('resize', place);
        document.removeEventListener('transitionend', placeAfterMove, true);
    };
}
