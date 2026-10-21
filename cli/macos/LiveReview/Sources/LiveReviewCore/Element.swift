import Foundation

/// One accessibility element of a simulator screen, as the panel reads it.
public struct Element: Equatable, Sendable {
    public var role: String
    public var roleDescription: String
    public var subrole: String
    public var label: String
    public var identifier: String
    public var frame: Frame
    public var inNavigationBar: Bool

    public init(role: String, roleDescription: String = "", subrole: String = "", label: String = "", identifier: String = "", frame: Frame, inNavigationBar: Bool = false) {
        self.role = role
        self.roleDescription = roleDescription
        self.subrole = subrole
        self.label = label
        self.identifier = identifier
        self.frame = frame
        self.inNavigationBar = inNavigationBar
    }

    public var isHeading: Bool {
        role == "AXHeading" || subrole == "AXHeading" || roleDescription.lowercased() == "heading"
    }

    public var isStaticText: Bool { role == "AXStaticText" }

    /// The role as a person reads it: "Button", "Heading".
    public var displayRole: String {
        let name = role.hasPrefix("AX") ? String(role.dropFirst(2)) : role

        return name.isEmpty ? "Element" : name
    }

    fileprivate var key: String {
        let f = frame
        return "\(role)|\(label)|\(identifier)|\(Int(f.x.rounded())),\(Int(f.y.rounded())),\(Int(f.width.rounded())),\(Int(f.height.rounded()))"
    }
}

public enum ElementList {
    /// The tree lists some elements twice. Keeps the first of each identical entry.
    public static func removingDuplicates(_ elements: [Element]) -> [Element] {
        var seen = Set<String>()

        return elements.filter { seen.insert($0.key).inserted }
    }

    /// The name of a screen: the first heading from the top, else the first static
    /// text inside the navigation bar, else an empty string.
    public static func screenTitle(_ elements: [Element]) -> String {
        let ordered = removingDuplicates(elements).enumerated().sorted { a, b in
            if a.element.frame.y != b.element.frame.y { return a.element.frame.y < b.element.frame.y }
            if a.element.frame.x != b.element.frame.x { return a.element.frame.x < b.element.frame.x }

            return a.offset < b.offset
        }.map(\.element)

        func named(_ test: (Element) -> Bool) -> String? {
            ordered.first { test($0) && !$0.label.trimmingCharacters(in: .whitespaces).isEmpty }?.label
        }

        return named { $0.isHeading } ?? named { $0.isStaticText && $0.inNavigationBar } ?? ""
    }
}
