// Pure zoom maths for the diagram viewer. A state is { scale, x, y }: the
// pixels per diagram unit, and the top-left point of the view in diagram
// units. A content box is { x, y, width, height } in diagram units, and a
// stage is { width, height } in pixels.

export const MAX_SCALE = 4;
export const MIN_SCALE = 0.25;
export const FIT_MAX_SCALE = 2;

export function clampScale(scale, min, max) {
    return Math.min(max, Math.max(min, scale));
}

export function fit(content, stage, maxScale = FIT_MAX_SCALE) {
    const scale = Math.min(
        stage.width / content.width,
        stage.height / content.height,
        maxScale,
    );
    return {
        scale,
        x: content.x + content.width / 2 - stage.width / scale / 2,
        y: content.y + content.height / 2 - stage.height / scale / 2,
    };
}

// The lowest zoom is 25%, or the opening fit when that is lower.
export function limits(fitScale) {
    return { min: Math.min(MIN_SCALE, fitScale), max: MAX_SCALE };
}

// Keeps the diagram point under `point`, in stage pixels, where it is.
export function zoomAt(state, factor, point, range) {
    const scale = clampScale(state.scale * factor, range.min, range.max);
    return {
        scale,
        x: state.x + point.x / state.scale - point.x / scale,
        y: state.y + point.y / state.scale - point.y / scale,
    };
}

export function pan(state, dx, dy) {
    return {
        scale: state.scale,
        x: state.x - dx / state.scale,
        y: state.y - dy / state.scale,
    };
}

export function viewBox(state, stage) {
    return [
        state.x,
        state.y,
        stage.width / state.scale,
        stage.height / state.scale,
    ].join(' ');
}
