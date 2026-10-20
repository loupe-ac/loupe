import Foundation

public struct SimulatorApp: Equatable, Sendable {
    public var bundleId: String
    public var name: String

    public init(bundleId: String, name: String) {
        self.bundleId = bundleId
        self.name = name
    }
}

/// The simulator does not say which app is in front. The panel takes the app that
/// launched last among the running apps, and prefers a third-party one.
public enum ForegroundApp {
    private static let background: Set<String> = [
        "com.apple.springboard", "com.apple.Spotlight", "com.apple.chrono.WidgetRenderer-Default",
    ]

    /// Reads `launchctl list` of the simulator and returns the bundle id to report.
    public static func pick(launchctl output: String) -> String? {
        var running: [(pid: Int, bundle: String)] = []
        for line in output.split(separator: "\n") {
            let columns = line.split(separator: "\t", omittingEmptySubsequences: false)
            guard columns.count >= 3, let pid = Int(columns[0]) else { continue }
            let label = columns[2]
            guard label.hasPrefix("UIKitApplication:") else { continue }
            let rest = label.dropFirst("UIKitApplication:".count)
            guard let end = rest.firstIndex(of: "[") else { continue }
            running.append((pid, String(rest[rest.startIndex..<end])))
        }
        let usable = running.filter { !background.contains($0.bundle) && !$0.bundle.contains("WidgetRenderer") }
        let thirdParty = usable.filter { !$0.bundle.hasPrefix("com.apple.") }

        return (thirdParty.isEmpty ? usable : thirdParty).max { $0.pid < $1.pid }?.bundle
    }

    /// Reads `simctl listapps` (an old-style property list) and returns the display name.
    public static func displayName(listapps output: Data, bundleId: String) -> String? {
        guard let root = try? PropertyListSerialization.propertyList(from: output, format: nil) as? [String: Any],
              let app = root[bundleId] as? [String: Any] else { return nil }

        return (app["CFBundleDisplayName"] as? String) ?? (app["CFBundleName"] as? String)
    }
}
