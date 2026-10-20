import AppKit
import LiveReviewCore

/// Global points have their origin top left. AppKit windows use the bottom left of the main display.
func appKitRect(_ frame: Frame) -> NSRect {
    let height = NSScreen.screens.first?.frame.height ?? 0

    return NSRect(x: frame.x, y: height - frame.y - frame.height, width: frame.width, height: frame.height)
}

/// The inverse of `appKitRect`, for a point such as `NSEvent.mouseLocation`.
func globalPoint(_ point: NSPoint) -> CGPoint {
    CGPoint(x: point.x, y: (NSScreen.screens.first?.frame.height ?? 0) - point.y)
}

let markColor = NSColor(calibratedRed: 1.0, green: 0.55, blue: 0.0, alpha: 1)

enum MouseKind { case down, drag, up }

private final class OverlayView: NSView {
    var origin = CGPoint.zero
    var highlight: Frame?
    var strokes: [[CGPoint]] = []
    var onMove: ((CGPoint?) -> Void)?
    var onMouse: ((MouseKind, CGPoint) -> Void)?

    override var isFlipped: Bool { true }

    override func updateTrackingAreas() {
        trackingAreas.forEach(removeTrackingArea)
        addTrackingArea(NSTrackingArea(rect: .zero, options: [.mouseMoved, .mouseEnteredAndExited, .activeAlways, .inVisibleRect], owner: self))
        super.updateTrackingAreas()
    }

    override func resetCursorRects() { addCursorRect(bounds, cursor: .crosshair) }
    override func acceptsFirstMouse(for event: NSEvent?) -> Bool { true }

    private func global(_ event: NSEvent) -> CGPoint {
        let local = convert(event.locationInWindow, from: nil)

        return CGPoint(x: local.x + origin.x, y: local.y + origin.y)
    }

    override func mouseMoved(with event: NSEvent) { onMove?(global(event)) }
    override func mouseExited(with event: NSEvent) { onMove?(nil) }
    override func mouseDown(with event: NSEvent) { onMouse?(.down, global(event)) }
    override func mouseDragged(with event: NSEvent) { onMouse?(.drag, global(event)) }
    override func mouseUp(with event: NSEvent) { onMouse?(.up, global(event)) }

    override func draw(_ dirtyRect: NSRect) {
        if let box = highlight {
            let rect = NSRect(x: box.x - origin.x, y: box.y - origin.y, width: box.width, height: box.height)
            markColor.withAlphaComponent(0.18).setFill()
            rect.fill()
            markColor.setStroke()
            let path = NSBezierPath(rect: rect.insetBy(dx: 1, dy: 1))
            path.lineWidth = 2
            path.stroke()
        }
        markColor.setStroke()
        for stroke in strokes where stroke.count >= 2 {
            let path = NSBezierPath()
            path.lineWidth = 3
            path.lineCapStyle = .round
            path.lineJoinStyle = .round
            path.move(to: CGPoint(x: stroke[0].x - origin.x, y: stroke[0].y - origin.y))
            for p in stroke.dropFirst() { path.line(to: CGPoint(x: p.x - origin.x, y: p.y - origin.y)) }
            path.stroke()
        }
    }
}

/// A transparent window over the simulator screen. It takes the mouse, so a pick or a
/// stroke never taps the app under review. Holding Option lets the mouse through.
final class CaptureOverlay {
    var onMove: ((CGPoint?) -> Void)? { didSet { view.onMove = onMove } }
    var onMouse: ((MouseKind, CGPoint) -> Void)? { didSet { view.onMouse = onMouse } }

    private let panel: NSPanel
    private let view = OverlayView()

    init() {
        panel = NSPanel(contentRect: .zero, styleMask: [.borderless, .nonactivatingPanel], backing: .buffered, defer: false)
        panel.isOpaque = false
        panel.backgroundColor = .clear
        panel.hasShadow = false
        panel.level = .floating
        panel.collectionBehavior = [.canJoinAllSpaces, .fullScreenAuxiliary, .stationary]
        panel.acceptsMouseMovedEvents = true
        panel.contentView = view
    }

    func show(screen: Frame, highlight: Frame?, strokes: [[CGPoint]]) {
        view.origin = CGPoint(x: screen.x, y: screen.y)
        view.highlight = highlight
        view.strokes = strokes
        panel.setFrame(appKitRect(screen), display: true)
        panel.orderFrontRegardless()
        panel.invalidateCursorRects(for: view)
        view.needsDisplay = true
    }

    func hide() { panel.orderOut(nil) }

    /// Lets the mouse reach the simulator while the owner holds Option.
    func passThrough(_ enabled: Bool) { panel.ignoresMouseEvents = enabled }
}
