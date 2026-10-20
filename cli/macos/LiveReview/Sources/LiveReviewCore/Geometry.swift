import CoreGraphics
import Foundation

/// A box in the global screen points of the Mac, or in pixels of the screenshot.
public struct Frame: Codable, Equatable, Sendable {
    public var x: Double
    public var y: Double
    public var width: Double
    public var height: Double

    public init(x: Double, y: Double, width: Double, height: Double) {
        self.x = x
        self.y = y
        self.width = width
        self.height = height
    }

    public init(_ rect: CGRect) {
        self.init(x: rect.origin.x, y: rect.origin.y, width: rect.size.width, height: rect.size.height)
    }

    public var rect: CGRect { CGRect(x: x, y: y, width: width, height: height) }

    public func contains(x px: Double, y py: Double) -> Bool {
        px >= x && px <= x + width && py >= y && py <= y + height
    }
}

/// Maps points of the simulator screen on the Mac to the pixels of a screenshot.
public struct ScreenMapper: Sendable {
    public let screen: Frame
    public let imageWidth: Double
    public let imageHeight: Double

    public init?(screen: Frame, imageWidth: Int, imageHeight: Int) {
        guard screen.width > 0, screen.height > 0, imageWidth > 0, imageHeight > 0 else { return nil }
        self.screen = screen
        self.imageWidth = Double(imageWidth)
        self.imageHeight = Double(imageHeight)
    }

    /// A point as fractions of the screen, 0 to 1 when it lies inside it.
    public func fraction(x: Double, y: Double) -> (x: Double, y: Double) {
        ((x - screen.x) / screen.width, (y - screen.y) / screen.height)
    }

    /// A frame of the Mac screen as a frame of screenshot pixels, clamped to the image.
    public func pixels(_ frame: Frame) -> Frame {
        let sx = imageWidth / screen.width
        let sy = imageHeight / screen.height
        let left = max(0, (frame.x - screen.x) * sx)
        let top = max(0, (frame.y - screen.y) * sy)
        let right = min(imageWidth, (frame.x + frame.width - screen.x) * sx)
        let bottom = min(imageHeight, (frame.y + frame.height - screen.y) * sy)

        return Frame(x: left, y: top, width: max(0, right - left), height: max(0, bottom - top))
    }

    /// A frame relative to the screen origin, still in points of the Mac.
    public func relative(_ frame: Frame) -> Frame {
        Frame(x: frame.x - screen.x, y: frame.y - screen.y, width: frame.width, height: frame.height)
    }
}

/// A freehand stroke as fractions of the screenshot width and height.
public struct Stroke: Equatable, Sendable {
    public var points: [CGPoint]

    public init(points: [CGPoint]) { self.points = points }
}

public extension ScreenMapper {
    /// Turns raw Mac points into a stroke of fractions. A stroke needs two points.
    func stroke(from points: [CGPoint]) -> Stroke? {
        guard points.count >= 2 else { return nil }

        return Stroke(points: points.map { p in
            let f = fraction(x: Double(p.x), y: Double(p.y))

            return CGPoint(x: f.x, y: f.y)
        })
    }
}
