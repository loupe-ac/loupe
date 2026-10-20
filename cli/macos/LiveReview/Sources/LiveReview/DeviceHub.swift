import AppKit
import ApplicationServices
import LiveReviewCore

/// The simulator screen the panel reviews.
struct Located {
    var device: BootedDevice
    var screen: Frame
    var window: Frame
    var group: AXUIElement
}

/// Reads the Device Hub app of Xcode 27 through the accessibility interface.
enum DeviceHub {
    static let processName = "Device Hub"
    private static let screenSubrole = "iOSContentGroup"

    /// NSRunningApplication reports a process id of -1 for Device Hub, so the id comes from its windows.
    static var pid: pid_t? {
        guard let list = CGWindowListCopyWindowInfo([.optionAll], kCGNullWindowID) as? [[String: Any]] else { return nil }

        return list.first { ($0[kCGWindowOwnerName as String] as? String) == processName }?[kCGWindowOwnerPID as String] as? pid_t
    }

    static func attribute(_ element: AXUIElement, _ name: String) -> CFTypeRef? {
        var value: CFTypeRef?
        guard AXUIElementCopyAttributeValue(element, name as CFString, &value) == .success else { return nil }

        return value
    }

    static func string(_ element: AXUIElement, _ name: String) -> String {
        (attribute(element, name) as? String) ?? ""
    }

    static func frame(_ element: AXUIElement) -> Frame? {
        guard let value = attribute(element, "AXFrame"), CFGetTypeID(value) == AXValueGetTypeID() else { return nil }
        var rect = CGRect.zero
        guard AXValueGetValue(value as! AXValue, .cgRect, &rect) else { return nil }

        return Frame(rect)
    }

    /// The element of Device Hub under a point, in global points with the origin top left.
    static func element(at point: CGPoint) -> AXUIElement? {
        guard let pid else { return nil }
        var found: AXUIElement?
        guard AXUIElementCopyElementAtPosition(AXUIElementCreateApplication(pid), Float(point.x), Float(point.y), &found) == .success else { return nil }

        return found
    }

    static func read(_ node: AXUIElement, inNavigationBar: Bool) -> Element? {
        guard let frame = frame(node) else { return nil }
        var label = string(node, "AXDescription")
        if label.isEmpty { label = string(node, "AXTitle") }

        return Element(
            role: string(node, "AXRole"),
            roleDescription: string(node, "AXRoleDescription"),
            subrole: string(node, "AXSubrole"),
            label: label,
            identifier: string(node, "AXIdentifier"),
            frame: frame,
            inNavigationBar: inNavigationBar
        )
    }

    /// Every element below the screen group, in tree order, at most `limit` of them.
    static func elements(in screen: AXUIElement, limit: Int = 800) -> [Element] {
        var result: [Element] = []
        func visit(_ node: AXUIElement, depth: Int, inNavigationBar: Bool) {
            guard result.count < limit, depth < 16 else { return }
            let descriptor = (string(node, "AXRoleDescription") + string(node, "AXSubrole") + string(node, "AXDescription")).lowercased()
            let bar = inNavigationBar || descriptor.contains("navigation") || string(node, "AXRole") == "AXToolbar"
            if let element = read(node, inNavigationBar: bar) { result.append(element) }
            for child in (attribute(node, "AXChildren") as? [AXUIElement]) ?? [] {
                visit(child, depth: depth + 1, inNavigationBar: bar)
            }
        }
        for child in (attribute(screen, "AXChildren") as? [AXUIElement]) ?? [] {
            visit(child, depth: 0, inNavigationBar: false)
        }

        return result
    }

    private static func findScreenGroup(in node: AXUIElement, depth: Int = 0) -> AXUIElement? {
        if string(node, "AXSubrole") == screenSubrole { return node }
        guard depth < 10 else { return nil }
        for child in (attribute(node, "AXChildren") as? [AXUIElement]) ?? [] {
            if let found = findScreenGroup(in: child, depth: depth + 1) { return found }
        }

        return nil
    }

    /// Every Device Hub window whose title names a booted device, with its screen group.
    static func locate(devices: [BootedDevice]) -> [Located] {
        guard let pid, let windows = attribute(AXUIElementCreateApplication(pid), "AXWindows") as? [AXUIElement] else { return [] }

        return windows.compactMap { window in
            guard let device = DeviceMatcher.match(title: string(window, "AXTitle"), in: devices),
                  let group = findScreenGroup(in: window), let screen = frame(group), let box = frame(window) else { return nil }
            return Located(device: device, screen: screen, window: box, group: group)
        }
    }
}
