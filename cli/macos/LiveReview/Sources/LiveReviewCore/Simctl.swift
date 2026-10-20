import Foundation

public enum Simctl {
    private static let xcrun = "/usr/bin/xcrun"

    public static func bootedDevices() async -> [BootedDevice] {
        guard let json = try? await ProcessRunner.run(executable: xcrun, arguments: ["simctl", "list", "devices", "booted", "-j"]) else { return [] }

        return DeviceMatcher.parseBooted(json)
    }

    /// simctl cannot write an image to stdout, so the shot goes through a temporary file.
    public static func screenshotPNG(udid: String) async -> Data? {
        let file = FileManager.default.temporaryDirectory.appendingPathComponent("live-review-\(UUID().uuidString).png")
        defer { try? FileManager.default.removeItem(at: file) }
        _ = try? await ProcessRunner.run(executable: xcrun, arguments: ["simctl", "io", udid, "screenshot", "--type=png", file.path])
        guard let data = try? Data(contentsOf: file), data.starts(with: [0x89, 0x50, 0x4E, 0x47]) else { return nil }

        return data
    }

    public static func foregroundApp(udid: String) async -> SimulatorApp? {
        guard let listing = try? await ProcessRunner.run(executable: xcrun, arguments: ["simctl", "spawn", udid, "launchctl", "list"]),
              let bundle = ForegroundApp.pick(launchctl: String(decoding: listing, as: UTF8.self)) else { return nil }
        let apps = try? await ProcessRunner.run(executable: xcrun, arguments: ["simctl", "listapps", udid])
        let name = apps.flatMap { ForegroundApp.displayName(listapps: $0, bundleId: bundle) }

        return SimulatorApp(bundleId: bundle, name: name ?? bundle)
    }
}
