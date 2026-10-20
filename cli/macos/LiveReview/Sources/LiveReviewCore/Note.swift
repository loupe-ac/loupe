import CryptoKit
import Foundation

/// Where a note goes. These are the three choices of the web widget: one card for the
/// review, a new card for each note, or a new card for each note under an epic.
public enum Destination: Equatable, Sendable {
    /// The first save makes the card, and the later notes of the review join it.
    case reviewCard
    case card(id: String, label: String)
    case eachNote(parentId: String?)

    public func afterSave(_ result: SaveResult) -> Destination {
        switch self {
        case .reviewCard: return .card(id: result.cardId, label: result.label)
        case .card, .eachNote: return self
        }
    }
}

public struct NoteElement: Encodable, Equatable, Sendable {
    public var role: String
    public var label: String?
    public var identifier: String?
    public var frame: Frame

    public init(role: String, label: String?, identifier: String?, frame: Frame) {
        self.role = role
        self.label = label
        self.identifier = identifier
        self.frame = frame
    }
}

public struct NativeFacts: Encodable, Equatable, Sendable {
    public var appName: String
    public var bundleId: String
    public var deviceName: String
    public var udid: String
    public var osVersion: String
    public var screenTitle: String
    public var element: NoteElement?

    public init(appName: String, bundleId: String, deviceName: String, udid: String, osVersion: String, screenTitle: String, element: NoteElement?) {
        self.appName = appName
        self.bundleId = bundleId
        self.deviceName = deviceName
        self.udid = udid
        self.osVersion = osVersion
        self.screenTitle = screenTitle
        self.element = element
    }
}

/// The JSON body of `loupe review save`. The marks are drawn into the image, so the
/// note sends no strokes. The delivery id stays the same across retries of one note.
public struct Note: Sendable {
    public var body: String
    public var destination: Destination
    public var native: NativeFacts
    public var jpeg: Data
    public var deliveryId: String?

    public init(body: String, destination: Destination, native: NativeFacts, jpeg: Data, deliveryId: String? = nil) {
        self.body = body
        self.destination = destination
        self.native = native
        self.jpeg = jpeg
        self.deliveryId = deliveryId
    }

    /// A note saves only with text and a marked image, because the helper refuses an empty image.
    public static func canSave(body: String, jpeg: Data) -> Bool {
        !body.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty && !jpeg.isEmpty
    }

    private struct Target: Encodable {
        var cardId: String?
        var newCard: NewCard?
        struct NewCard: Encodable { var parentCardId: String? }
    }

    private struct Screenshot: Encodable {
        var contentType: String
        var data: String
    }

    private struct Body: Encodable {
        var body: String
        var surface = "ios-simulator"
        var deliveryId: String?
        var target: Target
        var native: NativeFacts
        var screenshot: Screenshot
    }

    /// The body for `loupe review save`. Pass `withDelivery: false` to get the bytes that
    /// the delivery id is derived from.
    public func json(withDelivery: Bool = true) throws -> Data {
        let target: Target
        switch destination {
        case let .card(id, _): target = Target(cardId: id)
        case .reviewCard: target = Target(newCard: .init(parentCardId: nil))
        case let .eachNote(parent): target = Target(newCard: .init(parentCardId: parent))
        }
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.sortedKeys, .withoutEscapingSlashes]

        return try encoder.encode(Body(
            body: body,
            deliveryId: withDelivery ? deliveryId : nil,
            target: target,
            native: native,
            screenshot: Screenshot(contentType: "image/jpeg", data: jpeg.base64EncodedString())
        ))
    }
}

/// Gives a note one delivery id for as long as its content stays the same. A retry of an
/// unchanged note reuses the id, and any change to the text, the marks or the image gets a new one.
public struct DeliveryKeeper: Sendable {
    private var last: (hash: String, id: String)?

    public init() {}

    public mutating func id(for note: Note) throws -> String {
        let hash = SHA256.hash(data: try note.json(withDelivery: false)).map { String(format: "%02x", $0) }.joined()
        if let last, last.hash == hash { return last.id }
        let id = UUID().uuidString.lowercased()
        last = (hash, id)

        return id
    }

    public mutating func reset() { last = nil }
}
