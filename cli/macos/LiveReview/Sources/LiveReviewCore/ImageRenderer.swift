import CoreGraphics
import Foundation
import ImageIO
import UniformTypeIdentifiers

/// What to draw on the screenshot: the box of a picked element, the strokes, or both.
public struct Marks: Sendable {
    public var box: Frame?
    public var strokes: [Stroke]

    public init(box: Frame? = nil, strokes: [Stroke] = []) {
        self.box = box
        self.strokes = strokes
    }
}

public struct RenderedImage: Sendable {
    public var jpeg: Data
    public var width: Int
    public var height: Int

    public init(jpeg: Data, width: Int, height: Int) {
        self.jpeg = jpeg
        self.width = width
        self.height = height
    }
}

public enum ImageRenderer {
    public static let longEdge = 1568.0
    public static let quality = 0.8
    private static let mark = CGColor(red: 1.0, green: 0.55, blue: 0.0, alpha: 1)

    /// Draws the marks on a PNG, scales it to 1568 pixels on the long edge, and encodes JPEG.
    /// Box frames are in pixels of the PNG. Stroke points are fractions of it.
    public static func render(png: Data, marks: Marks) -> RenderedImage? {
        guard let source = CGImageSourceCreateWithData(png as CFData, nil),
              let image = CGImageSourceCreateImageAtIndex(source, 0, nil) else { return nil }
        let sourceW = Double(image.width)
        let sourceH = Double(image.height)
        let scale = min(1, longEdge / max(sourceW, sourceH))
        let width = max(1, Int((sourceW * scale).rounded()))
        let height = max(1, Int((sourceH * scale).rounded()))
        guard let context = CGContext(
            data: nil, width: width, height: height, bitsPerComponent: 8, bytesPerRow: 0,
            space: CGColorSpace(name: CGColorSpace.sRGB)!,
            bitmapInfo: CGImageAlphaInfo.noneSkipLast.rawValue
        ) else { return nil }
        context.interpolationQuality = .high
        context.draw(image, in: CGRect(x: 0, y: 0, width: width, height: height))
        let line = max(3.0, Double(width) / 200)
        context.setLineWidth(line)
        context.setLineCap(.round)
        context.setLineJoin(.round)

        if let frame = marks.box {
            // Image rows run top down, the context runs bottom up.
            let rect = CGRect(
                x: frame.x * scale,
                y: Double(height) - (frame.y + frame.height) * scale,
                width: frame.width * scale,
                height: frame.height * scale
            )
            context.setFillColor(mark.copy(alpha: 0.18)!)
            context.fill(rect)
            context.setStrokeColor(mark)
            context.stroke(rect.insetBy(dx: line / 2, dy: line / 2))
        }
        context.setStrokeColor(mark)
        for stroke in marks.strokes where stroke.points.count >= 2 {
            context.beginPath()
            for (index, p) in stroke.points.enumerated() {
                let point = CGPoint(x: Double(p.x) * Double(width), y: Double(height) - Double(p.y) * Double(height))
                if index == 0 { context.move(to: point) } else { context.addLine(to: point) }
            }
            context.strokePath()
        }

        guard let marked = context.makeImage() else { return nil }
        let output = NSMutableData()
        guard let destination = CGImageDestinationCreateWithData(output, UTType.jpeg.identifier as CFString, 1, nil) else { return nil }
        CGImageDestinationAddImage(destination, marked, [kCGImageDestinationLossyCompressionQuality: quality] as CFDictionary)
        guard CGImageDestinationFinalize(destination) else { return nil }

        return RenderedImage(jpeg: output as Data, width: width, height: height)
    }

    public static func size(png: Data) -> (width: Int, height: Int)? {
        guard let source = CGImageSourceCreateWithData(png as CFData, nil),
              let props = CGImageSourceCopyPropertiesAtIndex(source, 0, nil) as? [CFString: Any],
              let w = props[kCGImagePropertyPixelWidth] as? Int, let h = props[kCGImagePropertyPixelHeight] as? Int else { return nil }

        return (w, h)
    }
}
