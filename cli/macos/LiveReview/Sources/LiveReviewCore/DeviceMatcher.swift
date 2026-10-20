import Foundation

public struct BootedDevice: Equatable, Sendable {
    public var name: String
    public var udid: String
    public var osVersion: String

    public init(name: String, udid: String, osVersion: String) {
        self.name = name
        self.udid = udid
        self.osVersion = osVersion
    }
}

public enum DeviceMatcher {
    /// Reads the output of `xcrun simctl list devices booted -j`.
    public static func parseBooted(_ json: Data) -> [BootedDevice] {
        guard let root = try? JSONSerialization.jsonObject(with: json) as? [String: Any],
              let runtimes = root["devices"] as? [String: Any] else { return [] }
        var result: [BootedDevice] = []
        for (runtime, value) in runtimes {
            guard let version = osVersion(runtime: runtime), let list = value as? [[String: Any]] else { continue }
            for entry in list where (entry["state"] as? String) == "Booted" {
                guard let name = entry["name"] as? String, let udid = entry["udid"] as? String else { continue }
                result.append(BootedDevice(name: name, udid: udid, osVersion: version))
            }
        }

        return result.sorted { ($0.name, $0.udid) < ($1.name, $1.udid) }
    }

    /// "com.apple.CoreSimulator.SimRuntime.iOS-26-5" gives "26.5". Other platforms give nil.
    public static func osVersion(runtime: String) -> String? {
        guard let tail = runtime.split(separator: ".").last, tail.hasPrefix("iOS-") else { return nil }
        let parts = tail.dropFirst(4).split(separator: "-")

        return parts.isEmpty ? nil : parts.joined(separator: ".")
    }

    /// Matches a Device Hub window title such as "iPhone 17 Pro – iOS 26.5" to the one
    /// booted device with that name and version. Any other count gives nil.
    public static func match(title: String, in devices: [BootedDevice]) -> BootedDevice? {
        let found = devices.filter { title == "\($0.name) – iOS \($0.osVersion)" }

        return found.count == 1 ? found[0] : nil
    }
}
