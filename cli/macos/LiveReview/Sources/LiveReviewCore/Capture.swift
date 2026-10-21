import CoreGraphics
import Foundation

/// What the owner marked: a frozen screenshot with the element or the strokes drawn on it.
public struct Capture: Sendable {
    public var device: BootedDevice
    public var app: SimulatorApp?
    public var screenTitle: String
    public var element: Element?
    public var mapper: ScreenMapper
    public var png: Data
    public var strokes: [[CGPoint]]
    public var image: RenderedImage

    public init(device: BootedDevice, app: SimulatorApp?, screenTitle: String, element: Element?, mapper: ScreenMapper,
                png: Data, strokes: [[CGPoint]] = [], image: RenderedImage = .blank) {
        self.device = device
        self.app = app
        self.screenTitle = screenTitle
        self.element = element
        self.mapper = mapper
        self.png = png
        self.strokes = strokes
        self.image = image
    }

    public var marked: Bool { element != nil || !strokes.isEmpty }

    /// The screenshot with the element box and every stroke drawn on it.
    public func render() -> RenderedImage? {
        let marks = Marks(box: element.map { mapper.pixels($0.frame) }, strokes: strokes.compactMap { mapper.stroke(from: $0) })
        guard let image = ImageRenderer.render(png: png, marks: marks), !image.jpeg.isEmpty else { return nil }

        return image
    }

    /// The largest square of an image of the screen, in its pixels, centred on the marks as far as the edges allow.
    public func thumbnailSquare(width: Int, height: Int) -> CGRect {
        let w = Double(width), h = Double(height)
        let side = min(w, h)
        var marks = strokes.compactMap { mapper.stroke(from: $0) }.flatMap(\.points)
        if let element {
            let box = mapper.pixels(element.frame)
            marks += [CGPoint(x: box.x / mapper.imageWidth, y: box.y / mapper.imageHeight),
                      CGPoint(x: (box.x + box.width) / mapper.imageWidth, y: (box.y + box.height) / mapper.imageHeight)]
        }
        let xs = marks.map(\.x), ys = marks.map(\.y)
        let cx = xs.isEmpty ? 0.5 : (xs.min()! + xs.max()!) / 2
        let cy = ys.isEmpty ? 0.5 : (ys.min()! + ys.max()!) / 2
        let x = min(max(0, cx * w - side / 2), w - side).rounded(.down)
        let y = min(max(0, cy * h - side / 2), h - side).rounded(.down)

        return CGRect(x: x, y: y, width: side, height: side)
    }
}

/// Hands out a token per request, so only the answer to the newest request is used.
public struct LatestRequest: Sendable {
    private var current = 0

    public init() {}

    public mutating func begin() -> Int {
        current += 1

        return current
    }

    public mutating func cancel() { current += 1 }

    public func isCurrent(_ token: Int) -> Bool { token == current }
}

public enum FreezeEnd: Equatable, Sendable { case stale, screenshotFailed, screenChanged, renderFailed, ready }

/// The capture the panel holds and the screenshot it waits for. A failed render keeps the marks
/// with a blank image, so Save stays off until a render of every mark succeeds.
public struct CaptureSession: Sendable {
    public private(set) var capture: Capture?
    public private(set) var freezing = false
    private var pendingElement: Element?
    private var pendingStrokes: [[CGPoint]] = []
    private var freeze = LatestRequest()

    public init() {}

    /// The capture that Save may send: no screenshot in flight, and an image of the latest marks.
    public var ready: Capture? {
        guard !freezing, let capture, !capture.image.jpeg.isEmpty else { return nil }

        return capture
    }

    /// The element the panel shows: the picked one shows at once, before its screenshot is ready.
    public var picked: Element? { capture?.element ?? (freezing ? pendingElement : nil) }

    /// Drops the old capture, so Save cannot send it while the new screenshot is taken.
    public mutating func beginFreeze(element: Element? = nil) -> Int {
        capture = nil
        pendingElement = element
        pendingStrokes = []
        freezing = true

        return freeze.begin()
    }

    /// `titleNow` and `bundleNow` are read after the screenshot. A title or an app that differs from the one
    /// read before it means the screen moved, so the image and its facts may not match.
    public mutating func endFreeze(_ token: Int, with made: Capture?, titleNow: String, bundleNow: String?,
                                   render: (Capture) -> RenderedImage? = { $0.render() }) -> FreezeEnd {
        guard freeze.isCurrent(token) else { return .stale }
        freezing = false
        pendingElement = nil
        guard var made else { return .screenshotFailed }
        guard made.screenTitle == titleNow, made.app?.bundleId == bundleNow else {
            pendingStrokes = []
            return .screenChanged
        }
        made.strokes += pendingStrokes
        pendingStrokes = []
        made.image = render(made) ?? .blank
        capture = made

        return made.image.jpeg.isEmpty ? .renderFailed : .ready
    }

    /// Adds a finished stroke. Returns false when the new image could not be drawn.
    public mutating func add(stroke: [CGPoint], render: (Capture) -> RenderedImage? = { $0.render() }) -> Bool {
        guard var current = capture else {
            pendingStrokes.append(stroke)
            return true
        }
        current.strokes.append(stroke)
        current.image = render(current) ?? .blank
        capture = current

        return !current.image.jpeg.isEmpty
    }

    public mutating func clear() {
        freeze.cancel()
        freezing = false
        capture = nil
        pendingElement = nil
        pendingStrokes = []
    }
}

public extension RenderedImage {
    static let blank = RenderedImage(jpeg: Data(), width: 0, height: 0)
}
