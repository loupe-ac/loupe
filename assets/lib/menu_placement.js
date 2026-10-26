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
