import Foundation

public struct SimulatorApp: Equatable, Sendable {
    public var bundleId: String
    public var name: String

    public init(bundleId: String, name: String) {
        self.bundleId = bundleId
        self.name = name
    }
}

/// The simulator does not say which app is in front. Each simulator app is a
/// process of the Mac, and the simulator runs the app on screen at a higher
/// scheduling priority than the apps behind it, so `ps` tells them apart.
public enum ForegroundApp {
    private static let background: Set<String> = [
        "com.apple.springboard", "com.apple.Spotlight", "com.apple.chrono.WidgetRenderer-Default",
    ]

    public struct Running: Equatable, Sendable {
        public var pid: Int
        public var bundleId: String
    }

    /// Reads `launchctl list` of the simulator and returns its running apps.
    public static func running(launchctl output: String) -> [Running] {
        var running: [Running] = []
        for line in output.split(separator: "\n") {
            let columns = line.split(separator: "\t", omittingEmptySubsequences: false)
            guard columns.count >= 3, let pid = Int(columns[0]) else { continue }
            let label = columns[2]
            guard label.hasPrefix("UIKitApplication:") else { continue }
            let rest = label.dropFirst("UIKitApplication:".count)
            guard let end = rest.firstIndex(of: "[") else { continue }
            running.append(Running(pid: pid, bundleId: String(rest[rest.startIndex..<end])))
        }

        return running
    }

    /// Reads `ps -o pid=,pri=` and returns the priority of each process.
    public static func priorities(ps output: String) -> [Int: Int] {
        var result: [Int: Int] = [:]
        for line in output.split(separator: "\n") {
            let columns = line.split(separator: " ")
            guard columns.count == 2, let pid = Int(columns[0]), let pri = Int(columns[1]) else { continue }
            result[pid] = pri
        }

        return result
    }

    /// The app on screen runs above the priority of the apps behind it. With no
    /// app above the others, such as on the home screen, no app is in front.
    public static func pick(running: [Running], priorities: [Int: Int]) -> String? {
        let ranked = running.compactMap { app in priorities[app.pid].map { (app: app, pri: $0) } }
        guard let floor = ranked.map(\.pri).min() else { return nil }
        let usable = ranked.filter { !background.contains($0.app.bundleId) && !$0.app.bundleId.contains("WidgetRenderer") }
        guard let top = usable.map(\.pri).max(), top > floor else { return nil }
        let front = usable.filter { $0.pri == top }

        return front.count == 1 ? front[0].app.bundleId : nil
    }

    /// Reads `simctl listapps` (an old-style property list) and returns the display name.
    public static func displayName(listapps output: Data, bundleId: String) -> String? {
        guard let root = try? PropertyListSerialization.propertyList(from: output, format: nil) as? [String: Any],
              let app = root[bundleId] as? [String: Any] else { return nil }

        return (app["CFBundleDisplayName"] as? String) ?? (app["CFBundleName"] as? String)
    }
}
